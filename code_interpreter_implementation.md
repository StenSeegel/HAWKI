# Code Interpreter Implementation

Status: 2026-09-03, branch `feature/local-websearch`. Both phases implemented and
verified end to end against the live APIs; see 1.5 and 2.3.

## Principle

**No UI toggle.** A user should not have to know that an exact answer needs code. The
tool is offered on every request of a model that carries the capability, and the model
decides for itself whether the request needs it. This is the equivalent of an always-on
toggle, bounded by the model's capability.

Concretely, three switches gate it — none of them in the chat UI:

| gate | where | who sets it |
| --- | --- | --- |
| model carries the capability | `ai_models.settings.tools.code_interpreter` | admin, Language Models → Tools |
| activation | `hawki_tools.tools.code_interpreter.activation = always` | admin, `/admin/models/tools` |
| which implementation runs | `api_providers.additional_settings.hawki_tools.code_interpreter.override` | admin, API Management |

The last one decides between the two phases below: **override off → the provider's own
code interpreter (Phase 1); override on → HAWKI's MCP-backed one (Phase 2).** That is
the same rule web search already follows, so a provider that brings its own tool is
never shadowed by ours.

```
request
  └─ model has code_interpreter? ──no──> no tool offered
        │yes
        └─ provider override on? ──no──> Phase 1: native (Responses API `code_interpreter`)
              │yes
              └─────────────────────────> Phase 2: HAWKI tool → code-exec-mcp via the gateway
```

---

## Already in place (both phases build on this)

- `activation` modes in `config/hawki_tools.php`; `code_interpreter` is `always`, so it
  needs no `payload.tools` flag from the frontend (which only ever sends `web_search`
  and `image_generation`). Enforced in `HawkiToolRegistry::needsUserActivation()`.
- `code_interpreter` as a **model capability**: checkbox in `AiModelToolsLayout`,
  badge in `AiModelListLayout`, validation in `AiModelEditScreen`, toggle whitelist in
  `AiModelListScreen`, icon (`resources/icons/square-terminal.svg`) in the model list,
  model library and model info card, plus `ModelCapability_CodeInterpreter` /
  `ModelCapabilityTag_CodeInterpreter` in `en_US`/`de_DE`.
- Per-provider override UI: `ProviderHawkiToolsLayout` lists every configured tool.
- Admin surface for prompts and MCP bindings: `/admin/models/tools`.

---

## Phase 1 — Native code interpreter for OpenAI models  ✅ implemented 2026-09-03

**Goal:** models on the `responses` adapter run code in OpenAI's own sandbox. Nothing
of HAWKI's MCP machinery is involved.

**Test model:** `gpt-5.6-luna`, active on the `openai` provider (adapter `responses`)
with the *Code Interpreter* capability ticked. Verified live, see 1.6.

### 1.1 Attach the tool — `ResponsesRequestConverter::convertRequestToPayload()`

Next to the existing `web_search` and `image_generation` blocks, but **without** the
`$rawPayload['tools'][...]` condition those two use — there is no button to read:

```php
// Native code interpreter. No frontend flag: the model decides per request.
// Skipped when the provider hands this tool to HAWKI instead.
if (($availableTools['code_interpreter'] ?? false) === true
    && ! $model->getProvider()->getConfig()->isHawkiToolOverridden('code_interpreter')) {
    $payload['tools'][] = [
        'type' => 'code_interpreter',
        'container' => ['type' => 'auto'],
    ];
}
```

Note the two existing blocks build `$payload['tools']` defensively; keep that shape.

### 1.2 Surface what the model is doing — `ResponsesStreamingRequest::chunkToResponse()`

Measured against the live API on 2026-09-03. The event sequence of one call is:

```
response.output_item.added          item.type = code_interpreter_call
response.code_interpreter_call.in_progress
response.code_interpreter_call_code.delta   x N
response.code_interpreter_call_code.done
response.code_interpreter_call.interpreting
response.code_interpreter_call.completed
response.output_item.done           item = { code, container_id, outputs }
```

Three things were wrong in the first cut, and all three are fixed:

- **The step was logged out of order.** `code_interpreter_call` was handled under
  `response.output_item.done`, which is the *last* event of the call, and it added an
  `in_progress` entry to the persisted `status_log` — behind the `completed` one that
  `…call.completed` had already added. A reloaded message therefore showed "Code
  executed" followed by "Running code…". The `in_progress` step is now logged on
  `response.output_item.added`, and `output_item.done` logs nothing.
- **The code events never matched.** The switch had
  `response.code_interpreter_code.delta` / `…done`; the API sends
  `response.code_interpreter_call_code.delta` / `…done`. Both cases fell to `default`,
  so the streamed code was dropped. Fixed and accumulated per `output_index`.
- **`outputs` was always null.** Even for code that called `print()`. The Responses API
  withholds sandbox stdout unless the request asks for it, so the converter now sends
  `include: ['code_interpreter_call.outputs']` whenever the tool is attached. Pinned by
  `ResponsesCodeInterpreterTest::test_the_request_asks_for_what_the_sandbox_printed`.

### 1.3 What the user sees — decided

The open question of whether to show the code is settled: **the code goes into the
message**, not into the status step. `response.output_item.done` writes

````
```python
<the code that ran>
```

```output
<what it printed>
```
````

in front of the answer, which is the order it happened in. Two reasons, and the second
is the one that matters:

- the chat code box already renders a fenced python block with highlighting, copy,
  minimize and a **run** button, so the user can read and re-run exactly what ran;
- the block is persisted with the message, so the **next turn carries the code and its
  output back to the model**. The tool exchange itself is not kept — `store` is false
  and the converter maps assistant turns from their text — so without this the
  conversation had no record that anything had been computed.

The `output` block is not left as a second code box. `foldOutputIntoPreviousCodeBox()`
moves its content into the code box's own `.editor-code-output-container` - the same
panel the run button fills - and drops the extra box, so a model's run and a user's run
look identical. A failed run gets the panel's error styling. The fold happens while
processing the *output* block rather than the code block, because `formatHljs()` walks
blocks in document order and the code box above is only wrapped by then.

This is purely a rendering change: the message text still holds both fenced blocks, so
nothing new has to be persisted, the model still reads the output on the next turn, and
existing messages pick up the panel on reload without being regenerated.

The status step stays as the compact indicator. `getStatusLabel()` / `getStatusIcon()`
in `public/js/syntax_modifier.js` already knew `code_interpreter`
("Running code…" / "Code executed", terminal icon), with `Status_CodeInterpreter*` in
`en_US`/`de_DE`; the only frontend change needed was adding `code_interpreter` to the
spinner condition in the status log renderer.

Also emitted: a `code_interpreter_call` auxiliary carrying `code`, `output` and
`container_id`, and `serverToolUse.code_interpreter` on the usage record.

### 1.4 Non-streaming

`ResponsesRequest::dataToResponse()` ignored `code_interpreter_call` items entirely, so
group chats and the built-in assistants showed nothing. It now logs the same two steps
and prepends the same blocks.

### 1.5 Plots

A chart is returned as an `image` output whose `url` is a **complete `data:` URI** -
nothing has to be fetched from the container. (It is also announced a second time as a
`container_file_citation` annotation on the answer text, which HAWKI ignores.)

It was being rendered as the literal text `[image]`, so the model said "here is the
chart" and the message showed none. Now `collectCodeInterpreterImages()` stores it
through `AttachmentService::storeFromBase64()` and emits a `generated_image`
auxiliary - the same one the image generation tool uses, so the frontend renders it in a
framed container with a download button, links the file to the message, and keeps it
across a reload.

Two things worth knowing:

- **Stored at `'original'` size.** The size presets belong to the image generation tool
  and the `default` arm squares anything unknown to 1024x1024, which stretches every
  chart. `'original'` is a new arm in `resolveImageGenerationDimension()` that resolves
  to 0x0, which `resizeGeneratedImage()` already treats as "leave it alone". Verified:
  a 790x440 plot is stored 790x440.
- **Written into the message inline, at the point the code ran.** The frontend's own
  image container is inserted *before* `.message-content`, which put the chart above the
  code that drew it and above the answer. So the plot goes into the content stream as
  `![Plot](url)` right after the code and output blocks, and the auxiliary carries
  `inline: true` so `syntax_modifier.js` skips building a container for it. The
  auxiliary is still required - it is what links the stored file to the message and
  moves it out of temp storage.
- **Not added to `$this->generatedImages`.** That list appends its own markdown at
  `response.completed`, which would land after the answer rather than under the code.
  (Generated images from the image tool do currently render twice - once from their
  container, once from that markdown. Untouched here, but it is the same cause.)

Reading order in the finished message: code box, output box, chart, answer.

### 1.6 Verified — `tests/E2E/CodeInterpreterResponsesTest.php`

Six tests, run against the live API through `/req/streamAI`. They boot the local
application and exercise the real route, middleware, converter, SSE parser and
auxiliaries; only the upstream call leaves the machine.

| test | proves |
| --- | --- |
| `a_computational_prompt_runs_code_and_returns_the_exact_result` | the tool fires; `in_progress` then `completed`; the answer holds the real digest |
| `the_run_is_identifiable_in_the_persisted_status_log` | the reloaded message shows the two steps in order |
| `the_executed_code_is_written_into_the_message` | a python block is in the message and the auxiliary carries the code |
| `what_the_sandbox_printed_comes_back_and_is_shown` | `include` works; the output block holds the digest |
| `a_plot_is_stored_and_handed_over_as_an_image` | a chart becomes a stored image, and no base64 lands in the text |
| `a_question_of_stable_knowledge_runs_no_code` | no tool for "Hauptstadt von Frankreich" |

The prompt has to be genuinely out of reach or the test proves nothing: a model that
can compute something in its head will, tool or no tool — `2^256` came back exact with
no sandbox round. A SHA-256 digest cannot be recalled, so an exact match means code ran.

Result on `gpt-5.6-luna`, 2026-09-03: **6/6 green.**

## Phase 2 — HAWKI tool bridge to `code-exec-mcp`

**Goal:** models whose provider has no native code interpreter — the ki@JLU gateway —
get one through HAWKI's tool runtime.

**Test model:** `jlu/gemma-4-26b-it` on the `ki-at-jlu` provider.

### 2.1 What already works

- `CodeInterpreterTool` (`app/Services/AI/Tools/`): declares the `code` argument,
  calls the bound MCP tool, unwraps the server's `{"text","meta"}` envelope, reports
  timeouts and silent code in terms the model can act on, truncates at 8k chars.
- Registered in `HawkiToolRegistry::IMPLEMENTATIONS`, so `isImplemented()` is true and
  the Tools screen shows *runtime available*.
- Binding `code_interpreter → code-exec-mcp → code_exec`, reached **through the API
  gateway** (`https://api.hrz.uni-giessen.de/mcp`, `x-litellm-api-key`, gateway server
  `mcp_gVisor`), with the key read from the `ki-at-jlu` provider.
- The tool loop itself: `OpenAiHawkiToolsNonStreamingRequest` /
  `…StreamingRequest` run rounds, emit status auxiliaries, sum usage once.
- Verified live on `jlu/qwen3.8-27b`: "Berechne exakt 2^128" → one `code_interpreter`
  call → correct value; "Hauptstadt von Frankreich" → no call.

### 2.2 Brought up to parity with Phase 1  ✅ 2026-09-03

A user cannot tell which provider serves a model, so the two implementations have to be
indistinguishable in the chat. Three gaps closed:

- **The step did not survive a reload.** `emitToolStatus()` sent the status auxiliary
  but nothing was written to the persisted `status_log`, so a reloaded message showed no
  tool step at all. `OpenAiStreamingRequest::$statusLog` and `addStatusToLog()` are now
  `protected`, and the tool loop logs `in_progress` / `completed` around each call.
- **The code and its output were nowhere.** The sandbox result reaches the model as a
  `role: tool` message that is discarded with the request, so the user never saw it and
  the next turn had no record of it. `emitCodeInterpreterCall()` now writes the same
  python and output blocks the native path writes.
- **Non-streaming** did the same, via `renderCodeInterpreterCall()`.

### 2.3 Plots — the sandbox has to be told how

Measured directly against the gVisor sandbox behind `code-exec-mcp`:

| | |
| --- | --- |
| working directory (`/work`) | **read-only** |
| `/tmp` | writable |
| `plt.show()` | produces nothing |
| `print("data:image/png;base64,…")` | **works - the only way an image gets out** |

Left to itself a model writes `plt.savefig("plot.png")` and the run dies on a
`PermissionError`, which is what it did on the first measured attempt. So the convention
is stated in `CodeInterpreterTool::getArgumentSchema()`, on the `code` argument -
deliberately there rather than only in the awareness prompt, because that prompt is
editable per installation and stored in `app_settings`, so a customised installation
would never learn it. It is repeated in the config awareness for fresh installs.

The printed base64 is then taken out in `CodeInterpreterTool::execute()`, **before** the
8000 character output cap: a PNG runs to tens of thousands of characters, so the cap was
both corrupting the image and spending the model's context on base64. The image is
stored and collected in `SandboxImages`, a request-scoped singleton, which the calling
request drains to emit the `generated_image` auxiliaries - the tool interface returns
text and cannot emit auxiliaries itself.

`/req/conv/executeCode` - the run button on a chat code box - goes through the same
tool, so it now returns `images: [url, …]` next to the output and `renderCodeOutput()`
renders those. It still also lifts inline base64 out of the text, which is what the
create mode editor's own endpoint returns.

### 2.4 Verified — `tests/E2E/CodeInterpreterHawkiToolTest.php`

The same matrix as Phase 1, plus the plot case, against `jlu/qwen3.8-27b` on the `ki-at-jlu` provider with
the override on. Unlike the native path this really executes here: the MCP server is
reached through the gateway from the machine running the test.

Result 2026-09-03: **5/5 green** — the tool fires, the digest is exact, the code and
output blocks are in the message, a plot is stored and rendered, the persisted log is in
order, and "Hauptstadt von Frankreich" runs no code.

**`jlu/gemma-4-26b-it`** needed prompt work, and it is the reason 2.5 below exists. Once
the prompt was fixed: **6/6 green**, and 4/4 on the reported failure prompt.
`jlu/qwen3.8-27b`: 6/6. Measure another gateway model with

```
docker exec -e E2E_HAWKI_CODE_INTERPRETER_MODEL=jlu/<model> hawki-dev-app \
  vendor/bin/phpunit --group e2e --filter CodeInterpreterHawkiToolTest
```

### 2.5 A model that refuses, and then invents the answer

Reported from the browser on gemma: given a script and asked to run it, the answer was

> *(Hinweis: Da ich das Code-Tool in dieser spezifischen Umgebung nicht direkt mit
> Bildausgabe ausführen konnte, habe ich die Berechnungen basierend auf dem von dir
> bereitgestellten Skript interpretiert. Die Werte entsprechen der Logik des Codes mit
> dem Seed 42.)*

No tool call, no plot, and **statistics presented as results that no run had produced**.
Two causes, both in the prompt this document had just added:

1. **The image guidance was phrased negatively.** "plt.show() and savefig() to a file
   both produce nothing", "do NOT call plt.show()". A weaker model compresses a list of
   what does not work into "this environment cannot do images", and then apologises for
   it. It is now stated positively - "PLOTS AND IMAGES work like this" - with the
   explicit prohibition the code instruction already had and which demonstrably works:
   *never tell the user that this environment cannot show images*.
2. **Nothing forbade fabricating.** Added: *never state a number, a statistic or a
   result as if the code had produced it unless you actually called the tool and read it
   back.*

Also sharpened: a request to **run** a script is a run case, never a
"code the user only wants read or reviewed" case.

And the retry hint the tool sends back when code prints nothing is now tailored to what
the code did - the generic "print what should be returned" told a model that had just
called `plt.show()` nothing about the data URI route, so it retried the same thing and
burned its rounds. See `CodeInterpreterTool::emptyOutputHint()`.

Measured after the change, 4 consecutive runs of the reported prompt on gemma: tool
called every time, all 3 plots rendered, the true seed-42 mean (`100.28998...`) reported
every time, no excuse text. Pinned by
`test_a_script_it_is_asked_to_run_is_run_and_not_narrated`, which checks the seed value
precisely because a narrated answer says "about 100" and gets it wrong.

### 2.6 Web search on made the code interpreter unusable

Found in the container log of the run reported from the browser:

```
[CodeInterpreterTool] Calling MCP tool {"server":"websearch-mcp","tool":"code_exec"}
[ToolCallRunner]      Tool execution failed  {"error":"Tool 'code_exec' not found"}
```

`OpenAiHawkiToolsClient::resolveBinding()` returned the **first** provider-pinned MCP
server it found among the resolved tools and handed that one server to every tool of the
request. The ki@JLU provider pins web search to `websearch-mcp` and pins nothing for the
code interpreter, so with the web search button on, `code_exec` was dispatched to the
search server, which does not have that tool.

Consequences worth noting, because they made this look like a model problem:

- the tool failure reaches the model as text, so it did the sensible thing with what it
  was told - apologised and worked from the script - which read exactly like the
  refusal-and-fabricate behaviour of 2.5 and hid the real cause;
- it only happened with web search **on**. Every test and measurement here sends no
  `tools` flags, so web search was never resolved, no binding was pinned, and the code
  interpreter fell back to its own `code-exec-mcp` - which is why the suite was green
  throughout.

Fixed by resolving one server **per tool** (`resolveBindings()`), threaded through both
loop requests as a map and looked up by tool name at call time. A tool with no pinned
server gets `null` and falls back to the server in its own binding.

Verified live with the web search button on: `{"server":"code-exec-mcp"}`, the code ran,
the plot rendered, the true seed-42 mean came back. Pinned by
`OpenAiHawkiToolsAdapterTest::test_each_tool_gets_its_own_pinned_server` (checked to
fail against the old behaviour) and
`OpenAiHawkiToolsLoopTest::test_a_tool_without_a_pinned_server_is_not_given_another_tools_server`.

**Where the logs are:** neither `storage/logs/laravel.log` nor the `logs` table is
current on the dev stack - both stopped days ago. Web request logging goes to the
container's stdout, so `docker logs hawki-dev-app --since ...` is the place to look. A
CLI run via `docker exec php ...` logs to that process instead, so its lines never
appear there.

### 2.7 Known: qwen3-coder-next

Measured after the fixes above, `jlu/qwen3-coder-next` is the one gateway model that
still fails the matrix (3/6), for reasons that are about the model rather than HAWKI:

- it writes notebook style bare expressions (`hashlib.sha256(...).hexdigest()` with no
  `print`), so it spends rounds on the empty-output hint before it prints anything - the
  tailored hint of 2.5 helps but does not always save it inside the 3 round cap;
- it echoed a base64 data URI into its own answer prose. It never receives base64 back -
  the tool replaces it with a placeholder - so the blob is confabulated: a memorised PNG
  header and then noise, which renders as a broken image. The prompt now says never to
  write base64 or a data URI into the answer.

Not treated as a blocker: the other three models are green, and nothing here indicates a
defect in the tool runtime. Worth re-measuring if that model is put in front of users.

### 2.8 Acceptance criteria — met

- Tool failures reach the model as text, never as a broken message (`CodeInterpreterTool`).
- Usage records one summed entry with `serverToolUse.code_interpreter`.

## The awareness prompt lives in the database

`config/hawki_tools.php` is only the default for a fresh installation:
`app_settings.hawki_tools_tools.code_interpreter.awareness` overrides it at boot (via
`AppServiceProvider::loadDynamicConfiguration()`), which is what the Tools screen writes.
Editing the config file on an installation that has ever saved that screen changes
nothing.

The stored copy was synced to the file default on 2026-09-03, and again after the
rewrite in 2.5, so the two agree.
`web_search` was deliberately left alone - its prompt is tuned and measured per model.

This is also why the sandbox image convention is pinned in
`CodeInterpreterTool::getArgumentSchema()` rather than only in the prompt: the schema
ships with the code and reaches the model whatever an administrator has saved.

## Running the end-to-end tests

Both suites read the provider and model out of the running installation - the key
included, decrypted with the APP_KEY from `.env` - and re-create them in the isolated
sqlite database the suite forces. Nothing is written back, and no secret has to be
copied into a test variable. See `tests/E2E/LiveAiProvider.php`.

```
docker exec hawki-dev-app vendor/bin/phpunit --group e2e
```

From the host, point the database at the published port:
`E2E_DB_HOST=127.0.0.1 vendor/bin/phpunit --group e2e`.

The group is excluded from the default run, and each test skips itself with a reason
when the provider, the key or the database is not there.

## Open questions

- **Non-image container files.** A CSV or PDF the code interpreter writes is not
  inlined the way an image is; it stays a container file reference, and fetching one
  needs the containers endpoint HAWKI does not call. Such an output is logged and
  skipped.
- **Plots on the non-streaming path.** `ResponsesRequest` renders the code block but no
  image: that path supports no generated images at all today, image generation included,
  so plot support there is a larger piece of work than a code interpreter change.
- **Cost and time.** Each tool round is a full upstream request. The HAWKI loop caps at
  3 rounds; OpenAI's own loop has no such cap on our side.
- **`image_gen` vs `image_generation`.** The model capability key and the tool config
  key differ for image generation, so that gate can never pass. Harmless today (no
  runtime), but it must be aligned before an image tool ships — the code interpreter
  deliberately uses the same key (`code_interpreter`) everywhere, and a test pins it.
