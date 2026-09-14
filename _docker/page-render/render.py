"""HAWKI page renderer.

Turns a slide deck (or any document LibreOffice Impress opens, or a PDF) into
one PNG per page, so a vision model can see the slide as it is laid out. The
file converter extracts a deck's text and the image files embedded in it, but
never renders a slide - a model given a poster that way can name the icon in
it and cannot say where the icon sits.

  GET  /health                      liveness, no key
  GET  /                            {"version", "supported_formats"} - what HAWKI
                                    asks at runtime, same shape as the converter
  POST /render?max_pages=&dpi=      multipart "file" -> zip of pages/page_NNN.png
                                    plus meta.json {pages_total, pages_rendered}

Every render gets its own LibreOffice profile directory: soffice is a single
instance per profile, and two uploads converting at once would otherwise make
the second one exit with nothing. RENDER_API_KEY (optional) fences / and
/render like F_API_KEY does on the converter; empty leaves them open, which
is fine on a compose network nothing else can reach.
"""

import io
import logging
import os
import re
import shutil
import subprocess
import tempfile
import zipfile
from pathlib import Path

from fastapi import Depends, FastAPI, File, Header, HTTPException, Query, UploadFile
from fastapi.responses import JSONResponse, Response

logging.basicConfig(
    level=os.environ.get("LOG_LEVEL", "INFO").upper(),
    format="%(asctime)s %(levelname)s [%(name)s] %(message)s",
)
logger = logging.getLogger("page-render")

VERSION = "1.0.0"
API_KEY = os.environ.get("RENDER_API_KEY", "").strip()
MAX_UPLOAD_BYTES = int(os.environ.get("MAX_UPLOAD_MB", "256")) * 1024 * 1024
# soffice on a big deck is slow on a small host; the HAWKI side waits
# PAGE_RENDER_TIMEOUT, which should be at least this.
CONVERT_TIMEOUT = int(os.environ.get("RENDER_TIMEOUT", "120"))
MAX_PAGES_CEILING = int(os.environ.get("MAX_PAGES_CEILING", "100"))
DPI_CEILING = 200

# What LibreOffice Impress opens plus PDF (rasterised directly, no LibreOffice).
SUPPORTED = [
    ".pdf",
    ".pptx", ".ppt", ".pptm", ".ppsx", ".pps", ".potx", ".potm", ".pot",
    ".odp", ".otp", ".fodp", ".key",
]

app = FastAPI(title="HAWKI page renderer", version=VERSION)


def require_key(authorization: str | None = Header(default=None)) -> None:
    if API_KEY == "":
        return
    if authorization != f"Bearer {API_KEY}":
        raise HTTPException(status_code=401, detail="Invalid or missing API key")


@app.get("/health")
def health() -> dict:
    return {"status": "ok"}


@app.get("/", dependencies=[Depends(require_key)])
def info() -> dict:
    return {"version": VERSION, "supported_formats": SUPPORTED}


def _run(cmd: list[str], timeout: int) -> subprocess.CompletedProcess:
    return subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)


def _to_pdf(source: Path, workdir: Path) -> Path:
    """LibreOffice -> PDF. A profile dir per call keeps parallel renders apart."""
    profile = workdir / "lo-profile"
    profile.mkdir()
    result = _run(
        [
            "soffice",
            f"-env:UserInstallation=file://{profile}",
            "--headless", "--norestore", "--nologo",
            "--convert-to", "pdf",
            "--outdir", str(workdir),
            str(source),
        ],
        CONVERT_TIMEOUT,
    )
    pdf = workdir / (source.stem + ".pdf")
    if result.returncode != 0 or not pdf.exists():
        logger.warning("soffice failed for %s: rc=%s stderr=%s", source.name, result.returncode, result.stderr[-500:])
        raise HTTPException(status_code=422, detail="LibreOffice could not open the file")
    return pdf


def _page_count(pdf: Path) -> int:
    result = _run(["pdfinfo", str(pdf)], 30)
    match = re.search(r"^Pages:\s+(\d+)", result.stdout, re.M)
    return int(match.group(1)) if match else 0


def _rasterise(pdf: Path, workdir: Path, max_pages: int, dpi: int) -> list[Path]:
    out = workdir / "pages"
    out.mkdir()
    result = _run(
        ["pdftoppm", "-png", "-r", str(dpi), "-f", "1", "-l", str(max_pages), str(pdf), str(out / "p")],
        CONVERT_TIMEOUT,
    )
    if result.returncode != 0:
        logger.warning("pdftoppm failed for %s: %s", pdf.name, result.stderr[-500:])
        raise HTTPException(status_code=422, detail="The PDF could not be rasterised")
    # pdftoppm zero-pads the page number to the width of the last page (p-1.png,
    # p-01.png, p-001.png); sort numerically rather than by name.
    pages = sorted(out.glob("p-*.png"), key=lambda p: int(p.stem.split("-")[-1]))
    return pages


@app.post("/render", dependencies=[Depends(require_key)])
async def render(
    file: UploadFile = File(...),
    max_pages: int = Query(default=20, ge=1),
    dpi: int = Query(default=110, ge=36),
) -> Response:
    max_pages = min(max_pages, MAX_PAGES_CEILING)
    dpi = min(dpi, DPI_CEILING)

    name = Path(file.filename or "").name
    ext = Path(name).suffix.lower()
    if ext not in SUPPORTED:
        raise HTTPException(status_code=400, detail=f"Unsupported format: {ext or '(none)'}")

    data = await file.read()
    if len(data) > MAX_UPLOAD_BYTES:
        raise HTTPException(status_code=413, detail="File too large")
    if not data:
        raise HTTPException(status_code=400, detail="Empty file")

    workdir = Path(tempfile.mkdtemp(prefix="render_"))
    try:
        # A safe, extension-preserving name: LibreOffice picks its import
        # filter by extension, and the original name may hold anything.
        source = workdir / f"document{ext}"
        source.write_bytes(data)

        pdf = source if ext == ".pdf" else _to_pdf(source, workdir)
        total = _page_count(pdf)
        pages = _rasterise(pdf, workdir, max_pages, dpi)

        buffer = io.BytesIO()
        with zipfile.ZipFile(buffer, "w", zipfile.ZIP_DEFLATED) as zf:
            for index, page in enumerate(pages, start=1):
                zf.write(page, f"pages/page_{index:03d}.png")
            zf.writestr(
                "meta.json",
                '{"name": %s, "pages_total": %d, "pages_rendered": %d, "dpi": %d}'
                % (repr(name).replace("'", '"'), total, len(pages), dpi),
            )
        logger.info("rendered %s: %d/%d pages at %d dpi", name, len(pages), total, dpi)
        return Response(content=buffer.getvalue(), media_type="application/zip")
    except subprocess.TimeoutExpired:
        logger.warning("render timed out for %s after %ss", name, CONVERT_TIMEOUT)
        raise HTTPException(status_code=504, detail="Rendering timed out")
    finally:
        shutil.rmtree(workdir, ignore_errors=True)
