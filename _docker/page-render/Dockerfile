FROM python:3.12-slim

# LibreOffice turns a deck into a PDF and poppler rasterises the pages, so a
# vision model gets to see a slide as laid out rather than the icons cut out of
# it. Same recipe as the code-exec sandbox: libreoffice-impress pulls only the
# Impress filters (a .pptx, .odp or .key is all this renders; PDFs skip
# LibreOffice entirely), there is no JRE - see the javaldx stub below.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libreoffice-impress \
        libreoffice-core \
        fonts-dejavu-core \
        fonts-liberation \
        poppler-utils \
    && rm -rf /var/lib/apt/lists/* \
    # oosplash launches javaldx to find a JRE on every start and prints
    # "Warning: failed to launch javaldx - java may not function correctly"
    # to stderr when the binary is missing. There is no Java here on purpose.
    # A javaldx that answers with a directory already on the library path
    # keeps the start quiet (an empty answer still warns: "failed to read path").
    && printf '#!/bin/sh\necho /usr/lib/libreoffice/program\n' > /usr/lib/libreoffice/program/javaldx \
    && chmod +x /usr/lib/libreoffice/program/javaldx

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY render.py .

# Port 80 like the file converter, so the URL is just http://page-render/.
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --retries=3 --start-period=20s \
    CMD python -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:80/health', timeout=3)"

CMD ["uvicorn", "render:app", "--host", "0.0.0.0", "--port", "80"]
