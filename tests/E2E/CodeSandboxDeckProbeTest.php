<?php

declare(strict_types=1);

namespace Tests\E2E;

use App\Services\AI\Tools\CodeInterpreterTool;
use App\Services\Mcp\Exception\McpException;
use App\Services\Mcp\McpClient;
use App\Services\Mcp\McpServerRegistry;
use App\Services\Mcp\Value\McpServerConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Can code-exec-mcp do what OpenAI's code sandbox does when it builds a PPTX?
 *
 * Measured against the transcript of a real ChatGPT run (chat vj1rsiysdqbmt9ez),
 * whose pipeline was:
 *
 *   1. write a Node script holding the whole deck            (apply_patch, /mnt/data)
 *   2. build it with pptxgenjs                                (node x.js)
 *   3. convert it                                             (libreoffice --headless --convert-to pdf)
 *   4. rasterise it and look at the slides                    (pdftoppm -png)
 *   5. fix what looked wrong and repeat                        (several tool calls, one filesystem)
 *   6. hand the .pptx to the user as a download
 *
 * Every step is a separate capability of the sandbox, and every one of them can
 * be absent independently. These probes measure them one by one instead of
 * asking a model to try and reading tea leaves from its excuses. They talk to
 * the MCP server directly - no model in the loop - so a failure here is the
 * sandbox's, never a prompt's.
 *
 * A probe is allowed to fail. That is its result: a red test names exactly which
 * of the six steps we cannot reproduce today. Each one prints what it found, so
 * `--group e2e --testdox` reads as a capability matrix.
 *
 * Run it inside the app container, where .env and the database resolve the way
 * the application resolves them (see LiveAiProvider):
 *
 *   docker exec hawki-dev-app php -d memory_limit=1G vendor/bin/phpunit \
 *       --group e2e --filter CodeSandboxDeckProbe --testdox
 */
#[Group('e2e')]
class CodeSandboxDeckProbeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The MCP server's key is read from this API provider, so the provider row
     * has to exist in the test database before the registry can resolve it.
     */
    private const PROVIDER = 'ki-at-jlu';

    /**
     * Only seeded to drag the provider along - no model is used in this file.
     */
    private const KEY_CARRIER_MODEL = 'jlu/qwen3.8-27b';

    private McpServerConfig $server;

    private McpClient $client;

    private string $mcpTool;

    /** What the last sandbox call printed, before any JSON decoding. */
    private string $lastRawText = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        try {
            LiveAiProvider::seed(self::PROVIDER, self::KEY_CARRIER_MODEL);
        } catch (SkipLiveProvider $e) {
            $this->markTestSkipped($e->getMessage());
        }

        $registry = app(McpServerRegistry::class);
        $binding = $registry->binding(CodeInterpreterTool::KEY);

        if ($binding['server'] === null) {
            $this->markTestSkipped(
                'code_interpreter is not bound to a usable MCP server - check '
                .'hawki_tools.bindings.code_interpreter and the gateway key.'
            );
        }

        $this->server = $binding['server'];
        $this->mcpTool = (string) ($binding['tools']['run'] ?? 'code_exec');

        // One client for the whole test, as a single chat turn would have it:
        // if the server keeps any per-session state, this is the instance that
        // would see it.
        $this->client = app(McpClient::class);
    }

    /**
     * Step 0: what is actually in the box.
     *
     * Nothing here is asserted beyond the run itself, because every individual
     * finding is asserted by one of the probes below. This one exists so a run
     * prints the whole inventory in one place - which binaries, which modules,
     * what the working directory allows - instead of leaving it to be inferred
     * from four red tests.
     */
    public function test_the_sandbox_toolchain_inventory(): void
    {
        $inventory = $this->runJson(<<<'PY'
import sys, os, json, shutil, subprocess, importlib.util

def first_line(cmd):
    try:
        r = subprocess.run(cmd, capture_output=True, text=True, timeout=25)
        out = (r.stdout or r.stderr).strip().splitlines()
        return out[0][:120] if out else '(no output)'
    except Exception as e:
        return 'ERR %s' % type(e).__name__

BINS = ['node', 'npm', 'npx', 'soffice', 'libreoffice', 'pdftoppm', 'pdftocairo',
        'unoconv', 'apply_patch', 'git', 'pandoc']
MODULES = ['pptx', 'docx', 'openpyxl', 'matplotlib', 'numpy', 'PIL',
           'reportlab', 'fitz', 'pdf2image', 'cairosvg', 'lxml']

found = {b: shutil.which(b) for b in BINS}

inv = {
    'python': sys.version.split()[0],
    'cwd': os.getcwd(),
    'cwd_writable': os.access(os.getcwd(), os.W_OK),
    'tmp_writable': os.access('/tmp', os.W_OK),
    'home': os.environ.get('HOME'),
    'bins': {b: p for b, p in found.items() if p},
    'bins_missing': sorted(b for b, p in found.items() if not p),
    'modules': sorted(m for m in MODULES if importlib.util.find_spec(m)),
    'modules_missing': sorted(m for m in MODULES if not importlib.util.find_spec(m)),
    'versions': {b: first_line([b, '--version']) for b in ('node', 'soffice', 'libreoffice', 'pdftoppm') if found.get(b)},
}
print(json.dumps(inv))
PY);

        $this->report('SANDBOX INVENTORY', $inventory);

        $this->assertArrayHasKey('python', $inventory, 'The inventory did not come back as JSON.');
    }

    /**
     * Step 5, and the one that decides the shape of everything else.
     *
     * OpenAI's run is six tool calls sharing one /mnt/data: it writes the script
     * in call one, runs it in call two, repairs it in call five. If our sandbox
     * starts empty on every call, that whole loop has to be folded into a single
     * code_exec - build, render and inspect in one round - and the model never
     * gets to look at a slide before committing to it.
     *
     * code-exec-mcp is configured with requires_session => false, so the gateway
     * carries no session for us; whether the server keeps a workspace per API key
     * anyway is exactly what this measures.
     */
    public function test_the_filesystem_survives_between_two_calls(): void
    {
        $marker = 'probe-'.bin2hex(random_bytes(8));

        $this->runCode(<<<PY
open('/tmp/hawki_probe_marker.txt', 'w').write('{$marker}')
print('written')
PY);

        $second = $this->runCode(<<<'PY'
import os
p = '/tmp/hawki_probe_marker.txt'
print(open(p).read() if os.path.exists(p) else 'GONE')
PY);

        $this->report('FILESYSTEM BETWEEN CALLS', [
            'written' => $marker,
            'read_back' => trim($second),
            'verdict' => str_contains($second, $marker)
                ? 'persistent - a multi-call build/render/repair loop is possible'
                : 'fresh per call - build, render and inspect must happen in ONE code_exec',
        ]);

        // Measured: fresh per call. The prompt is written for exactly that
        // ("EVERY CALL IS A FRESH PROCESS", build-check-print in one call), so
        // this asserts the documented behaviour - and a sandbox that one day
        // keeps its /tmp shows up here as the signal to rewrite that guidance,
        // not as a silent change under the models' feet.
        $this->assertStringNotContainsString(
            $marker,
            $second,
            'The sandbox filesystem now SURVIVES between calls. The awareness prompt and the '
            .'tool schema describe a fresh process per call - revisit both, a multi-call '
            .'build/inspect/repair loop has become possible.'
        );
    }

    /**
     * Step 2, our version of it.
     *
     * pptxgenjs is a Node library and this sandbox is Python-only by its tool
     * schema, so python-pptx is the realistic substitute. It is markedly weaker -
     * no theme fonts, shadows and rounded-rectangle helpers of the kind the
     * OpenAI deck leans on - but it writes a valid OOXML package, which is the
     * part that matters.
     */
    public function test_python_pptx_can_build_a_valid_deck(): void
    {
        $result = $this->runJson(<<<'PY'
import json, io, base64, importlib.util, importlib.metadata

if not importlib.util.find_spec('pptx'):
    print(json.dumps({'available': False}))
else:
    from pptx import Presentation
    from pptx.util import Inches, Pt
    from pptx.dml.color import RGBColor

    prs = Presentation()
    prs.slide_width = Inches(13.333)   # LAYOUT_WIDE, as the OpenAI deck uses
    prs.slide_height = Inches(7.5)

    blank = prs.slide_layouts[6]

    s = prs.slides.add_slide(blank)
    box = s.shapes.add_textbox(Inches(0.8), Inches(1.25), Inches(7.2), Inches(1.2))
    p = box.text_frame.paragraphs[0]
    r = p.add_run()
    r.text = 'ANTHROPOMORPHISIERUNG VON LLM'
    r.font.size = Pt(29)
    r.font.bold = True
    r.font.color.rgb = RGBColor(0xFF, 0xFF, 0xFF)
    s.notes_slide.notes_text_frame.text = '[Sources]\n- https://example.org'

    s2 = prs.slides.add_slide(blank)
    s2.shapes.add_textbox(Inches(0.65), Inches(0.52), Inches(11.8), Inches(0.42)).text_frame.text = '1 - Was bedeutet das?'

    buf = io.BytesIO()
    prs.save(buf)
    data = buf.getvalue()

    import zipfile
    names = zipfile.ZipFile(io.BytesIO(data)).namelist()

    print(json.dumps({
        'available': True,
        'version': importlib.metadata.version('python-pptx'),
        'bytes': len(data),
        'zip_magic': data[:2].decode('latin1'),
        'has_content_types': '[Content_Types].xml' in names,
        'has_presentation': 'ppt/presentation.xml' in names,
        'slide_parts': sorted(n for n in names if n.startswith('ppt/slides/slide')),
        'notes_parts': sorted(n for n in names if n.startswith('ppt/notesSlides/notesSlide')),
        'b64_chars': len(base64.b64encode(data)),
    }))
PY);

        $this->report('PYTHON-PPTX', $result);

        $this->assertTrue(
            (bool) ($result['available'] ?? false),
            'python-pptx is not installed in the sandbox. Without it and without Node '
            .'there is no way to author a .pptx at all - the sandbox image needs it.'
        );

        $this->assertSame('PK', $result['zip_magic'] ?? null, 'The saved deck is not a ZIP container.');
        $this->assertTrue((bool) ($result['has_presentation'] ?? false), 'No ppt/presentation.xml part.');
        $this->assertCount(2, $result['slide_parts'] ?? [], 'The deck did not get two slides.');
        $this->assertNotEmpty($result['notes_parts'] ?? [], 'Speaker notes did not survive - that is where OpenAI puts its sources.');
    }

    /**
     * Step 1 and 2 as OpenAI literally does them.
     *
     * Expected to fail on a Python-only sandbox. It is here because the answer
     * changes what we would ask for: if node is present but pptxgenjs is not,
     * the gap is one npm package in the image; if node is absent, the whole
     * pptxgenjs route is off the table and python-pptx is the only path.
     */
    public function test_node_and_pptxgenjs_are_available(): void
    {
        $result = $this->runJson(<<<'PY'
import json, shutil, subprocess

node = shutil.which('node')
out = {'node': node, 'npm': shutil.which('npm')}

if node:
    r = subprocess.run([node, '-e', "try{require('pptxgenjs');console.log('OK')}catch(e){console.log('MISSING '+e.code)}"],
                       capture_output=True, text=True, timeout=30)
    out['pptxgenjs'] = (r.stdout or r.stderr).strip()[:200]
    out['node_version'] = subprocess.run([node, '--version'], capture_output=True, text=True).stdout.strip()

print(json.dumps(out))
PY);

        $this->report('NODE / PPTXGENJS', $result);

        $this->assertNotNull(
            $result['node'] ?? null,
            'No node in the sandbox, so pptxgenjs - the library OpenAI builds its decks with - '
            .'cannot be used at all. Either python-pptx carries the feature, or node plus '
            .'pptxgenjs goes into the sandbox image.'
        );

        $this->assertStringStartsWith(
            'OK',
            (string) ($result['pptxgenjs'] ?? ''),
            'node is present but pptxgenjs is not installed - the gap is one npm package in the sandbox image.'
        );
    }

    /**
     * Steps 3 and 4: the render-back loop.
     *
     * This is the part that makes absolute-coordinate layout survivable. Without
     * it a model places a text box at x=6.55 and never learns that it overlaps
     * the one at x=6.35 - and the OpenAI deck is nothing but arithmetic like
     * that. A deck we cannot look at is a deck we cannot fix.
     *
     * LibreOffice's first start in a cold sandbox is slow; the server timeout is
     * 120s, which this has to fit inside.
     */
    public function test_libreoffice_and_pdftoppm_can_rasterise_a_deck(): void
    {
        $result = $this->runJson(<<<'PY'
import json, os, glob, shutil, subprocess, importlib.util, time

out = {'soffice': shutil.which('soffice') or shutil.which('libreoffice'), 'pdftoppm': shutil.which('pdftoppm')}

if out['soffice'] and importlib.util.find_spec('pptx'):
    from pptx import Presentation
    from pptx.util import Inches
    prs = Presentation()
    prs.slide_width, prs.slide_height = Inches(13.333), Inches(7.5)
    for i in range(3):
        s = prs.slides.add_slide(prs.slide_layouts[6])
        s.shapes.add_textbox(Inches(1), Inches(1), Inches(8), Inches(1)).text_frame.text = 'Folie %d' % (i + 1)
    prs.save('/tmp/hawki_probe_deck.pptx')

    t0 = time.time()
    r = subprocess.run([out['soffice'], '--headless', '--convert-to', 'pdf',
                        '--outdir', '/tmp', '/tmp/hawki_probe_deck.pptx'],
                       capture_output=True, text=True, timeout=100)
    out['convert_rc'] = r.returncode
    out['convert_seconds'] = round(time.time() - t0, 1)
    out['convert_stderr'] = r.stderr.strip()[:300]
    out['pdf_exists'] = os.path.exists('/tmp/hawki_probe_deck.pdf')

    if out['pdf_exists'] and out['pdftoppm']:
        subprocess.run([out['pdftoppm'], '-png', '-r', '60',
                        '/tmp/hawki_probe_deck.pdf', '/tmp/hawki_probe_slide'],
                       capture_output=True, timeout=60)
        pngs = sorted(glob.glob('/tmp/hawki_probe_slide*.png'))
        out['png_count'] = len(pngs)
        out['png_bytes'] = [os.path.getsize(p) for p in pngs]

print(json.dumps(out))
PY);

        $this->report('RENDER-BACK LOOP', $result);

        $this->assertNotNull(
            $result['soffice'] ?? null,
            'No LibreOffice in the sandbox, so a generated deck can never be rendered and '
            .'checked. This is the single biggest quality difference to the OpenAI run.'
        );

        $this->assertTrue((bool) ($result['pdf_exists'] ?? false), 'LibreOffice did not produce a PDF: '.json_encode($result));

        $this->assertNotNull($result['pdftoppm'] ?? null, 'No pdftoppm (poppler-utils), so the PDF cannot become slide images.');

        $this->assertSame(3, $result['png_count'] ?? 0, 'The three slides did not rasterise to three PNGs.');
    }

    /**
     * Step 6, part one: can the bytes leave the sandbox at all?
     *
     * Only stdout comes back. A PNG gets out because SandboxImages recognises the
     * base64 signature and lifts it into an attachment; nothing does that for a
     * .pptx. This probe measures the raw channel - the MCP call, uncapped - so a
     * failure here means the transport is the problem, not our cap.
     *
     * The payload is a stdlib ZIP of the size a nine-slide deck reaches, not a
     * real deck: the transport does not care what is in the container, and tying
     * this probe to python-pptx would hide the transport finding behind a missing
     * library. A .pptx IS a ZIP, so the magic bytes and the size are honest.
     */
    public function test_a_deck_can_be_carried_out_as_base64_over_stdout(): void
    {
        $result = $this->runJson(<<<'PY'
import json, io, os, base64, hashlib, zipfile

# A nine-slide deck with a master, a theme and speaker notes lands around 40 kB.
# The parts are random bytes, so the ZIP does not deflate away to nothing and the
# payload keeps the size a real deck has.
buf = io.BytesIO()
with zipfile.ZipFile(buf, 'w', zipfile.ZIP_STORED) as z:
    z.writestr('[Content_Types].xml', '<Types/>')
    for i in range(9):
        z.writestr('ppt/slides/slide%d.xml' % (i + 1), os.urandom(4400))
data = buf.getvalue()
b64 = base64.b64encode(data).decode()

print(json.dumps({'bytes': len(data), 'b64_chars': len(b64),
                  'magic': data[:2].decode('latin1'),
                  'sha256': hashlib.sha256(data).hexdigest()}))
print('BEGINDECK' + b64 + 'ENDDECK')
PY, firstLineOnly: true);

        $raw = $this->lastRawText;

        $this->assertSame(
            1,
            preg_match('/BEGINDECK([A-Za-z0-9+\/=]+)ENDDECK/', $raw, $m),
            'The base64 of the deck did not survive the trip out of the sandbox intact.'
        );

        $decoded = base64_decode($m[1], true);

        $this->report('DECK OUT OVER STDOUT', [
            'deck_bytes' => $result['bytes'] ?? null,
            'b64_chars' => $result['b64_chars'] ?? null,
            'returned_chars' => strlen($m[1]),
            'sha256_matches' => hash('sha256', (string) $decoded) === ($result['sha256'] ?? ''),
            'note' => 'a nine-slide deck needs ~'.(int) (($result['b64_chars'] ?? 0) / 1000).'k characters of stdout',
        ]);

        $this->assertSame(
            $result['sha256'],
            hash('sha256', (string) $decoded),
            'The deck came back corrupted - the transport mangles a payload this size.'
        );
    }

    /**
     * Step 6, part two: and what our own tool does with it.
     *
     * CodeInterpreterTool caps output at 8000 characters, and a deck's base64
     * runs to hundreds of thousands. SandboxImages lifts a document data URI out
     * before the cap - stores it, collects it for the request to announce as a
     * container_file, and hands the model a one-line note with the link to write.
     * This is the live version of SandboxFileDeliveryTest: same assertion, real
     * sandbox, real attachment store.
     */
    public function test_a_deck_leaves_the_tool_as_a_stored_file_not_as_truncated_base64(): void
    {
        // The attachment row belongs to a user; in production the tool runs
        // inside that user's request.
        $this->actingAs(\App\Models\User::where('username', 'admin')->firstOrFail());

        $tool = app(CodeInterpreterTool::class);
        $images = app(\App\Services\AI\Tools\SandboxImages::class);
        $images->drain();

        $output = $tool->execute(['code' => <<<'PY'
import io, os, base64, zipfile

buf = io.BytesIO()
with zipfile.ZipFile(buf, 'w', zipfile.ZIP_STORED) as z:
    z.writestr('[Content_Types].xml', '<Types/>')
    for i in range(9):
        z.writestr('ppt/slides/slide%d.xml' % (i + 1), os.urandom(4400))

print('data:application/vnd.openxmlformats-officedocument.presentationml.presentation;name=probe.pptx;base64,'
      + base64.b64encode(buf.getvalue()).decode())
PY]);

        $files = array_values(array_filter($images->drain(), static fn (array $f) => ($f['kind'] ?? null) === 'file'));

        $this->report('DECK THROUGH THE TOOL', [
            'returned_chars' => mb_strlen($output),
            'note' => mb_substr($output, 0, 300),
            'stored_files' => array_map(static fn (array $f) => ['name' => $f['name'], 'mime' => $f['mime'], 'url' => $f['url']], $files),
        ]);

        $this->assertStringNotContainsString('[output truncated', $output, 'The deck still hits the output cap.');
        $this->assertStringNotContainsString('base64,', $output, 'The base64 reached the model.');
        $this->assertStringContainsString('[probe.pptx](sandbox:/tmp/probe.pptx)', $output);
        $this->assertCount(1, $files, 'The deck was not collected as a file for the request to announce.');
        $this->assertSame('probe.pptx', $files[0]['name']);
    }

    /**
     * The model's mistakes, replayed without the model.
     *
     * Recorded from chat bvic3eauxr19qzgf (gemma-4-26b-it, 2026-09-14): two
     * rounds, both "node returned non-zero exit status 1", and the model gave
     * up. The JavaScript called defineSlideMaster() on the pptxgenjs CLASS -
     * `const pptx = require('pptxgenjs'); const pptxgen = new pptx();` with the
     * names the wrong way round - so node threw a TypeError. The snippet had
     * capture_output=True and printed str(e), which for CalledProcessError is
     * the exit status and nothing else: the TypeError never reached the model.
     *
     * Two things have to hold, and this probe checks both on the live sandbox:
     * the script fails for the reason we think, and the failure now explains
     * itself through the very print(e) the model wrote.
     */
    public function test_a_swallowed_node_error_still_reaches_the_model_through_print_e(): void
    {
        $output = $this->runCode(<<<'PY'
import subprocess
open('/tmp/deck.js', 'w').write("""
const pptx = require('pptxgenjs');
const pptxgen = new pptx();
pptx.defineSlideMaster({ title: 'MASTER_SLIDE', background: { color: 'F1F1F1' }, objects: [] });
const slide1 = pptx.addSlide({ masterName: 'MASTER_SLIDE' });
pptx.writeFile({ fileName: '/tmp/anthropomorphisierung.pptx' });
""")
try:
    subprocess.run(["node", "/tmp/deck.js"], check=True, capture_output=True, text=True)
    print("SUCCESS")
except Exception as e:
    print(f"ERROR: {str(e)}")
PY);

        $this->report('SWALLOWED NODE ERROR', ['model_sees' => mb_substr($output, 0, 600)]);

        $this->assertStringContainsString('ERROR:', $output, 'The replay did not fail - the recorded mistake no longer reproduces.');
        $this->assertStringContainsString(
            'TypeError: pptx.defineSlideMaster is not a function',
            $output,
            'print(e) on a CalledProcessError still hides the subprocess stderr; the sandbox image needs its sitecustomize.py.'
        );
    }

    /**
     * The example in the prompt has to run. A shape the model is told to copy
     * that does not itself produce a deck would teach every model the same
     * mistake; so the API the awareness prompt shows is executed here - every
     * method it names, with the notes and sources it promises - on each look a
     * deck can have: the JLU template in German (the default) and English, and
     * HAWKI's own purple. The JLU decks have to carry the JLU logo, which the
     * template keeps on its sample slides and hawki_slides carries over.
     */
    public function test_the_hawki_slides_api_from_the_prompt_builds_a_deck_in_every_look(): void
    {
        $awareness = (string) config('hawki_tools.tools.code_interpreter.awareness');

        foreach ([
            'from hawki_slides import Deck',
            'Deck(title=',
            'deck.bullets(',
            'deck.cards(',
            'deck.two_columns(',
            'deck.quote(',
            'deck.closing(',
            'deck.save("/tmp/<title>.pptx")',
            'template="attached"',
            'style="purple"',
            'EVERY CALL IS A FRESH PROCESS',
        ] as $line) {
            $this->assertStringContainsString($line, $awareness, 'The awareness prompt lost this line: '.$line);
        }

        $result = $this->runJson(<<<'PY'
import subprocess, os, json, glob, zipfile, traceback
from hawki_slides import Deck

def build(tag, **kw):
    deck = Deck(title="Probe", author="HAWKI", **kw)
    deck.title("Probe", "Untertitel")
    deck.bullets("Punkte", ["eins", {"text": "zwei", "sub": ["zwei a", "zwei b"]}, "drei"], notes="Notiz", sources=["https://example.org/a"])
    deck.cards("Karten", [{"heading": "A", "text": "a"}, {"heading": "B", "text": "b"}, {"heading": "C", "text": "c"}])
    deck.two_columns("Zwei Spalten", {"heading": "Links", "items": ["l1", "l2"]}, {"heading": "Rechts", "items": ["r1"]})
    deck.quote("Ein Satz.", "Quelle")
    deck.closing("Danke")
    path = deck.save(f"/tmp/{tag}.pptx")
    subprocess.run(["soffice", "--headless", "--convert-to", "pdf", "--outdir", "/tmp", path], check=True, capture_output=True, text=True)
    subprocess.run(["pdftoppm", "-png", "-r", "30", f"/tmp/{tag}.pdf", f"/tmp/{tag}"], check=True, capture_output=True, text=True)
    z = zipfile.ZipFile(path); names = z.namelist()
    notes = [n for n in names if n.startswith('ppt/notesSlides/notesSlide') and n.endswith('.xml')]
    return {
        'slides': len([n for n in names if n.startswith('ppt/slides/slide') and n.endswith('.xml')]),
        'rendered': len(glob.glob(f"/tmp/{tag}-*.png")),
        'notes': len(notes),
        'sources_in_notes': any(b'example.org/a' in z.read(n) for n in notes),
        'media': len([n for n in names if n.startswith('ppt/media/')]),
        'bytes': os.path.getsize(path),
    }

out = {}
for tag, kw in [("jlu_de", {"lang": "de"}), ("jlu_en", {"lang": "en"}), ("purple", {"style": "purple"})]:
    try:
        out[tag] = build(tag, **kw)
    except Exception:
        out[tag] = {"error": traceback.format_exc()[-600:]}
print(json.dumps(out))
PY);

        $this->report('HAWKI_SLIDES', $result);

        foreach (['jlu_de', 'jlu_en', 'purple'] as $look) {
            $deck = $result[$look] ?? [];
            $this->assertArrayNotHasKey('error', $deck, "The $look deck failed: ".($deck['error'] ?? ''));
            $this->assertSame(6, $deck['slides'] ?? 0, "$look: six calls, six slides.");
            $this->assertSame(6, $deck['rendered'] ?? 0, "$look: LibreOffice did not render every slide.");
            $this->assertTrue($deck['sources_in_notes'] ?? false, "$look: the sources did not land in the speaker notes.");
        }

        // The JLU decks carry the logo the template keeps on its sample slides.
        $this->assertGreaterThanOrEqual(1, $result['jlu_de']['media'] ?? 0, 'The German JLU deck lost the JLU logo.');
        $this->assertGreaterThanOrEqual(1, $result['jlu_en']['media'] ?? 0, 'The English JLU deck lost the JLU logo.');
    }

    /**
     * The user's own template. It arrives as a `files` entry on the call and
     * appears at /work/<name>; Deck(template="attached") finds it there. The
     * probe sends the English JLU template as if a user had attached it and
     * checks the deck was built on it rather than on the default.
     */
    public function test_an_attached_template_is_found_at_work_and_used(): void
    {
        // The template to attach comes out of the sandbox image itself, so the
        // probe needs no fixture: the English JLU template, base64 over stdout.
        $fetched = $this->runCode(<<<'PY'
import base64
print(base64.b64encode(open('/usr/local/share/hawki-slides/templates/JLU-en.potx', 'rb').read()).decode())
PY);
        $template = base64_decode(trim(explode("\n", trim($fetched))[0]), true);

        if ($template === false || $template === '') {
            $this->markTestSkipped('Could not read a template out of the sandbox image to attach.');
        }

        $result = $this->runJson(<<<'PY'
import json, os, zipfile
from hawki_slides import Deck, attached_templates
out = {'work': sorted(os.listdir('/work')), 'attached': attached_templates()}
deck = Deck(title="Probe", template="attached")
out['template_path'] = deck.template_path
deck.title("Probe", "auf der eigenen Vorlage").closing("Ende")
path = deck.save("/tmp/own.pptx")
names = zipfile.ZipFile(path).namelist()
out['slides'] = len([n for n in names if n.startswith('ppt/slides/slide') and n.endswith('.xml')])
out['layouts'] = len([n for n in names if n.startswith('ppt/slideLayouts/slideLayout') and n.endswith('.xml')])
print(json.dumps(out))
PY, files: [['name' => 'Meine Vorlage.potx', 'content_base64' => base64_encode($template)]]);

        $this->report('ATTACHED TEMPLATE', $result);

        $this->assertContains('Meine Vorlage.potx', $result['work'] ?? [], 'The attached file did not appear in /work.');
        $this->assertSame('/work/Meine Vorlage.potx', $result['template_path'] ?? null);
        $this->assertSame(2, $result['slides'] ?? 0);
        $this->assertGreaterThan(10, $result['layouts'] ?? 0, 'The deck does not carry the attached template\'s layouts.');
    }

    /**
     * save() outside /tmp is the read-only-working-directory mistake in a new
     * coat; the helper refuses it with a message that names the fix.
     */
    public function test_hawki_slides_refuses_to_save_outside_tmp_with_a_useful_message(): void
    {
        $output = $this->runCode(<<<'PY'
from hawki_slides import Deck
try:
    Deck(title="x").title("x").save("deck.pptx")
    print("SUCCESS")
except Exception as e:
    print("ERROR:", e)
PY);

        $this->assertStringContainsString('needs a path under /tmp', $output);
        $this->assertStringContainsString('read-only', $output);
    }

    /**
     * The other two ways a deck attempt has died so far, each with the message
     * the model now gets - so a regression in either shows up as text, not as
     * a model shrugging.
     */
    public function test_the_known_deck_failure_modes_explain_themselves(): void
    {
        // 1. A relative fileName: pptxgenjs writes into the read-only working directory.
        $relative = $this->runCode(<<<'PY'
import subprocess
open('/tmp/d.js', 'w').write("const P=require('pptxgenjs');const p=new P();p.addSlide();p.writeFile({fileName:'Deck.pptx'});")
try:
    subprocess.run(["node", "/tmp/d.js"], check=True, capture_output=True, text=True)
    print("SUCCESS")
except Exception as e:
    print("ERROR:", e)
PY);
        $this->assertStringContainsString('EROFS', $relative, 'A relative fileName no longer names the read-only directory as the cause.');

        // 2. soffice without a writable HOME would exit 77 silently; HOME=/tmp in the image prevents it.
        $home = $this->runJson(<<<'PY'
import os, json, subprocess
from pptx import Presentation
p = Presentation(); p.slides.add_slide(p.slide_layouts[6]); p.save('/tmp/h.pptx')
r = subprocess.run(["soffice", "--headless", "--convert-to", "pdf", "--outdir", "/tmp", "/tmp/h.pptx"], capture_output=True, text=True)
print(json.dumps({'HOME': os.environ.get('HOME'), 'rc': r.returncode, 'stderr': r.stderr.strip()[:200], 'pdf': os.path.exists('/tmp/h.pdf')}))
PY);
        $this->report('KNOWN FAILURE MODES', ['relative_filename' => mb_substr($relative, 0, 300), 'soffice' => $home]);

        $this->assertSame('/tmp', $home['HOME'], 'HOME is not /tmp; LibreOffice will exit 77 on the read-only rootfs.');
        $this->assertSame(0, $home['rc']);
        $this->assertTrue($home['pdf']);
        $this->assertSame('', $home['stderr'], 'LibreOffice prints to stderr on every run - that reaches the model as noise.');
    }

    /**
     * The third way a deck went missing, replayed. Chat bvic3eauxr19qzgf,
     * 10:07: the model built the deck, previewed all three slides, wrote
     * "Du kannst die Präsentation hier herunterladen:" with a sandbox: link -
     * and never printed the file. /tmp is discarded with the container, the
     * link pointed at nothing, and the frontend rightly left it as text.
     *
     * The sandbox now delivers every document left in /tmp at exit, so the
     * same program yields the file note - through HAWKI's tool, with the real
     * attachment store, as the model would see it. And a program that DID
     * print its file gets it delivered once, not twice.
     */
    public function test_a_document_the_program_forgot_to_print_is_delivered_anyway(): void
    {
        $this->actingAs(\App\Models\User::where('username', 'admin')->firstOrFail());
        $images = app(\App\Services\AI\Tools\SandboxImages::class);
        $images->drain();

        $output = app(CodeInterpreterTool::class)->execute(['code' => <<<'PY'
import subprocess, base64, os
open("/tmp/deck.js", "w").write("""
const PptxGenJS = require("pptxgenjs");
const pptx = new PptxGenJS();
pptx.layout = "LAYOUT_WIDE";
const s1 = pptx.addSlide(); s1.addText("Anthropomorphisierung", { x: 1, y: 2, w: 12, h: 1.5, fontSize: 44 });
pptx.writeFile({ fileName: "/tmp/anthropomorphisierung.pptx" });
""")
subprocess.run(["node", "/tmp/deck.js"], check=True, capture_output=True, text=True)
subprocess.run(["soffice", "--headless", "--convert-to", "pdf", "--outdir", "/tmp", "/tmp/anthropomorphisierung.pptx"], check=True, capture_output=True, text=True)
subprocess.run(["pdftoppm", "-png", "-r", "40", "/tmp/anthropomorphisierung.pdf", "/tmp/slide"], check=True, capture_output=True, text=True)
for i in range(1, 4):
    p = f"/tmp/slide-{i}.png"
    if os.path.exists(p):
        print("data:image/png;base64," + base64.b64encode(open(p, "rb").read()).decode())
# ...and no print of the deck itself, as recorded.
PY]);

        $files = array_values(array_filter($images->drain(), static fn (array $f) => ($f['kind'] ?? null) === 'file'));

        $this->report('FORGOTTEN DECK', [
            'model_sees' => mb_substr($output, 0, 400),
            'files' => array_map(static fn (array $f) => $f['name'], $files),
        ]);

        $this->assertStringContainsString('[image 1 was produced', $output);
        $this->assertStringContainsString(
            '[file 1 "anthropomorphisierung.pptx" was produced',
            $output,
            'The deck the program left in /tmp was not delivered - the sandbox image needs its sitecustomize.py auto-delivery.'
        );
        $this->assertStringContainsString('(sandbox:/tmp/anthropomorphisierung.pptx)', $output);
        $this->assertCount(1, $files, 'Expected exactly the deck; the conversion PDF must not come along as a second file.');
        $this->assertSame('anthropomorphisierung.pptx', $files[0]['name']);
    }

    /**
     * The name a model picks has spaces in it - "Anthropomorphisierung von
     * LLMs.pptx" - and that once broke delivery end to end. The sandbox now
     * percent-encodes the name and HAWKI decodes it back.
     */
    public function test_a_document_with_spaces_in_its_name_is_delivered(): void
    {
        $this->actingAs(\App\Models\User::where('username', 'admin')->firstOrFail());
        $images = app(\App\Services\AI\Tools\SandboxImages::class);
        $images->drain();

        $output = app(CodeInterpreterTool::class)->execute(['code' => <<<'PY'
from hawki_slides import Deck
Deck(title="x").title("x").save("/tmp/Anthropomorphisierung von LLMs Übersicht.pptx")
print("saved")
PY]);

        $files = array_values(array_filter($images->drain(), static fn (array $f) => ($f['kind'] ?? null) === 'file'));

        $this->assertStringNotContainsString('[output truncated', $output);
        $this->assertCount(1, $files, 'The spaced name broke delivery again: '.mb_substr($output, 0, 300));
        $this->assertSame('Anthropomorphisierung von LLMs Übersicht.pptx', $files[0]['name']);
        $this->assertStringContainsString('(sandbox:/tmp/Anthropomorphisierung%20von%20LLMs%20%C3%9Cbersicht.pptx)', $output);
    }

    public function test_a_document_the_program_did_print_is_delivered_once(): void
    {
        $this->actingAs(\App\Models\User::where('username', 'admin')->firstOrFail());
        $images = app(\App\Services\AI\Tools\SandboxImages::class);
        $images->drain();

        $output = app(CodeInterpreterTool::class)->execute(['code' => <<<'PY'
import base64
open('/tmp/twice.pptx', 'wb').write(b'PK\x03\x04' + b'0' * 2000)
print('data:application/vnd.openxmlformats-officedocument.presentationml.presentation;name=twice.pptx;base64,'
      + base64.b64encode(open('/tmp/twice.pptx', 'rb').read()).decode())
PY]);

        $files = array_values(array_filter($images->drain(), static fn (array $f) => ($f['kind'] ?? null) === 'file'));

        $this->assertCount(1, $files, 'A file the program printed itself was delivered again at exit: '.$output);
        $this->assertStringNotContainsString('[file 2', $output);
    }

    /**
     * Whether the missing pieces can be installed from inside, or have to go into
     * the image.
     *
     * The tool's own argument schema tells models there is no network. If that
     * holds, every gap found above is an image change on the gVisor host - not
     * something a clever prompt can paper over with a pip install.
     */
    public function test_whether_the_sandbox_can_reach_the_network(): void
    {
        $result = $this->runJson(<<<'PY'
import json, socket

out = {}
try:
    socket.gethostbyname('pypi.org')
    out['dns'] = 'resolves'
except Exception as e:
    out['dns'] = 'blocked: %s' % type(e).__name__
try:
    socket.create_connection(('pypi.org', 443), timeout=6).close()
    out['tcp_443'] = 'open'
except Exception as e:
    out['tcp_443'] = 'blocked: %s' % type(e).__name__

print(json.dumps(out))
PY);

        $this->report('NETWORK', $result + [
            'meaning' => ($result['tcp_443'] ?? '') === 'open'
                ? 'missing libraries could be installed at runtime'
                : 'missing libraries must be baked into the sandbox image',
        ]);

        $this->assertArrayHasKey('dns', $result);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Run code on the sandbox and return what it printed.
     *
     * The execution server wraps its answer in {"text": ..., "meta": {...}};
     * CodeInterpreterTool unwraps that for the model, but it does it privately,
     * so the probes unwrap it here and keep the meta - a timed-out run is a
     * finding, not a silent empty string.
     */
    private function runCode(string $code, array $files = []): string
    {
        $arguments = ['code' => $code];
        if ($files !== []) {
            $arguments['files'] = $files;
        }

        try {
            $raw = $this->client->callTool($this->server, $this->mcpTool, $arguments);
        } catch (McpException $e) {
            $this->fail('The sandbox call failed: '.$e->getMessage());
        }

        $decoded = json_decode($raw, true);

        if (is_array($decoded) && array_key_exists('text', $decoded)) {
            if (($decoded['meta']['timed_out'] ?? false) === true) {
                $this->fail(
                    'The sandbox stopped the run on its timeout ('.$this->server->timeout.'s client side). '
                    .'Partial output: '.substr((string) $decoded['text'], 0, 500)
                );
            }

            $raw = (string) $decoded['text'];
        }

        return $this->lastRawText = $raw;
    }

    /**
     * Run code whose last line is a JSON object and decode it.
     *
     * @param  bool  $firstLineOnly  for a probe that prints its JSON and then a
     *                               large payload underneath it
     *
     * @return array<string,mixed>
     */
    private function runJson(string $code, bool $firstLineOnly = false, array $files = []): array
    {
        $output = trim($this->runCode($code, $files));

        $lines = array_values(array_filter(explode("\n", $output), static fn ($l) => trim($l) !== ''));

        $this->assertNotEmpty($lines, 'The sandbox printed nothing at all.');

        // The last line that IS JSON, not the last line: the sandbox delivers
        // documents left in /tmp by appending their data URIs after the
        // program's own output.
        $decoded = null;
        if ($firstLineOnly) {
            $decoded = json_decode(trim($lines[0]), true);
        } else {
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $decoded = json_decode(trim($lines[$i]), true);
                if (is_array($decoded)) {
                    break;
                }
            }
        }

        $this->assertIsArray(
            $decoded,
            'The probe did not print JSON. What came back:'."\n".substr($output, 0, 1500)
        );

        return $decoded;
    }

    /**
     * Print a finding. These tests exist to be read, not only to be green, so
     * every probe leaves its measurement in the run output.
     *
     * @param  array<string,mixed>  $findings
     */
    private function report(string $title, array $findings): void
    {
        fwrite(STDOUT, "\n=== ".$title." ===\n".json_encode($findings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }
}
