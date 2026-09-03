# Code Interpreter Implementation

Status: 2026-09-03, branch `feature/local-websearch`.

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
with the *Code Interpreter* capability ticked. Verified live, see 1.4.

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

The four relevant events are currently in the **ignore list** (`response.code_interpreter_call.in_progress`,
`…completed`, `response.code_interpreter_code.delta`, `…done`). They need to become
status auxiliaries, mirroring how `web_search_call` is handled:

- `response.output_item.added` with `item.type === 'code_interpreter_call'` →
  `addStatusToLog('code_interpreter', 'initiated', …)` + a `status` auxiliary.
- `…code.delta` / `…code.done` → accumulate the generated code; decide whether to show
  it (see open questions) or only the fact that code ran.
- `…call.completed` → completed status, so the spinner resolves.

### 1.3 Frontend labels — `public/js/syntax_modifier.js`

`getStatusLabel()` and `getStatusIcon()` know `web_search`, `reasoning`,
`image_generation`, `processing`. Add a `code_interpreter` type to both
(`code_interpreter_in_progress` → "Running code…", `code_interpreter_completed` →
"Code executed"), with matching `Status_*` keys in `en_US`/`de_DE`. Without this the
step renders with the fallback icon and a raw status string.

### 1.4 Test plan

Prerequisite: `gpt-5.6-luna` added and its *Code Interpreter* capability ticked;
`hawki_tools.code_interpreter.override` **off** for the `openai` provider.

| prompt | expected |
| --- | --- |
| "Berechne exakt 2^256 und gib nur die Zahl aus." | tool runs; exact 78-digit value |
| "Was ist die Hauptstadt von Frankreich?" | no tool; direct answer |
| "Sortiere [5,3,9,1] und nenne den Median." | tool runs; correct result |
| a long-running loop | completes or fails without hanging the stream |

**Measured 2026-09-03 on `gpt-5.6-luna`:**

| prompt | tool ran | result |
| --- | --- | --- |
| SHA-256 of a string | yes | digest matches `hashlib` exactly |
| `987654321987654321 * 123456789123456789` | yes | exact, matches Python |
| `2^256` | no — answered from reasoning | exact anyway |
| capital of France | no | direct answer |

Worth knowing: a model that *can* compute something in its head will, even with the
tool attached. `2^256` produced the right 78-digit value without a sandbox round, so a
test prompt has to be genuinely out of reach (a hash, a large product) to prove the
tool fires.

Verify server-side that the payload carries `{"type":"code_interpreter"}` (the request
is logged by `AbstractRequest`), and in the browser that the status step appears and
resolves, and that the answer persists after the stream ends.

### 1.5 Acceptance criteria — met

- The function is in the payload for a capable model with the override off, and absent
  when the capability is off or the override is on.
- A code run shows a status step that resolves, and the final answer persists.
- Non-computational prompts trigger no tool call.
- Feature tests cover the converter's gating matrix (capability × override).

---

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

### 2.2 What remains

1. **Verify on `jlu/gemma-4-26b-it`.** Gemma is the model that needed the tool prompt
   moved into the user turn for web search; the code interpreter prompt has not been
   measured on it at all. Run the same want-tool/want-direct matrix used for search and
   tune `hawki_tools.tools.code_interpreter.awareness` if it under- or over-calls.
2. **Code boxes in chat messages.** Fenced code the model returns is not rendered as a
   code box in chat; create mode has both the styling and a run button. Reuse it, so a
   user can see and re-run the code the model executed. *(Tracked separately.)*
3. **Frontend labels** — the same `code_interpreter` status type as Phase 1.3; the
   streaming loop already emits `type: code_interpreter` status auxiliaries.
4. **Decide what the user sees of the code.** Today only a status step appears; the
   executed code lives in the tool call, not in the message.

### 2.3 Test plan

Prerequisite: *Code Interpreter* ticked on `jlu/gemma-4-26b-it`;
`hawki_tools.code_interpreter.override` **on** for `ki-at-jlu` (already set).

Same prompt matrix as Phase 1, plus:

| case | expected |
| --- | --- |
| MCP server unreachable | model answers that it could not run the code; no exception |
| code that prints nothing | model is told to print its result, and retries |
| code that runs too long | timeout reported, stream still completes |

### 2.4 Acceptance criteria

- Gemma calls the tool for computational prompts and not for stable knowledge, measured
  rather than assumed.
- Tool failures reach the model as text, never as a broken message.
- Usage records one summed entry with `serverToolUse.code_interpreter`.

---

## Open questions

- **Container files.** OpenAI's code interpreter can emit images and files
  (`container_file_citation` annotations). Out of scope for Phase 1's first cut; text
  output only. Phase 2's MCP server can base64 an image, which HAWKI cannot yet ingest.
- **Showing the code.** Both phases can produce the code that ran. Whether to render it
  in the message (with the create-mode run button) or keep it in the status step is a
  product decision — it is the natural companion to the code-box work.
- **Cost and time.** Each tool round is a full upstream request. The HAWKI loop caps at
  3 rounds; OpenAI's own loop has no such cap on our side.
- **`image_gen` vs `image_generation`.** The model capability key and the tool config
  key differ for image generation, so that gate can never pass. Harmless today (no
  runtime), but it must be aligned before an image tool ships — the code interpreter
  deliberately uses the same key (`code_interpreter`) everywhere, and a test pins it.
