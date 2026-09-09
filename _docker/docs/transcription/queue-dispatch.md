# Transcription queue workers (`queue-transcription`, `queue-transcription-process`)

## What this is

Both long-running passes of the transcription pipeline are dispatched through
**dedicated queue connections** defined in the app's `config/queue.php`, instead of
sharing the `default,mails,message_broadcast` queue that the existing `queue` service
already processes:

- `AnalyzeSpeakersJob` (phase 1: whole-file speaker diarization, run before
  transcription starts so the UI can show a speaker preview) → connection
  `transcription`, worker container `queue-transcription`.
- `ProcessTranscriptionJob` (phase 2: parallel chunk transcription + final whole-file
  diarization with the user-confirmed speaker references + optional LLM cleanup) →
  connection `transcription_process`, worker container `queue-transcription-process`.
  This replaced the previous unsupervised `exec('... artisan transcription:process-job
  ... > /dev/null 2>&1 &')` dispatch, which had no supervision at all and — with
  `LOG_CHANNEL=stderr` — silently discarded every log line the detached process produced.

Every environment's compose file (`docker-compose.yml`, `docker-compose.dev.yml`,
`docker-compose.staging.yml`, `docker-compose.prod.yml`) runs both worker containers
next to the existing `queue` container:

```
command: [ 'php', 'artisan', 'queue:work', 'transcription', '--queue=transcription', '--tries=1', '--timeout=3900' ]
command: [ 'php', 'artisan', 'queue:work', 'transcription_process', '--queue=transcription_process', '--tries=1', '--timeout=7800' ]
```

If you deploy or update these compose files, make sure both workers come up alongside
`queue` — without `queue-transcription`, dispatched `AnalyzeSpeakersJob`s sit in the
`jobs` table forever and speaker analysis silently never completes (this exact gap
caused a real incident on a long-running dev stack); without
`queue-transcription-process`, jobs get stuck at `status = 'preprocessed'` and the
actual transcription silently never starts.

## Why separate workers, not just separate queue names

1. **Runtime.** A diarization request runs on the whole, unchunked audio file and can
   legitimately take up to `config('transcription.diarization_timeout_ceiling')`
   (default 3600s) plus the job's own margin — each job sets its own
   `public int $timeout` in its constructor (`AnalyzeSpeakersJob`: ceiling + 300s ≈
   3900s; `ProcessTranscriptionJob`: 2 × ceiling + 300s ≈ 7500s, because phase 2 runs
   two diarization-scale operations back to back — the chunk transcription pass and the
   final whole-file diarization), which Laravel's worker honors over any `--timeout` CLI
   flag. The `queue` worker's `--timeout=90` is correctly sized for quick
   mail/broadcast jobs and would kill a real run. The two transcription workers are also
   separate *from each other* so a running phase-2 pass never blocks other users'
   speaker previews (and vice versa).
2. **Blocking.** A single `queue:work` process handles one job at a time. If diarization
   shared the `queue` worker, a single long-running diarization request would block every
   mail and broadcast message from being delivered for its entire duration.
3. **Duplicate execution risk.** The `database` queue driver considers an in-flight job
   abandoned, and hands the same row to another worker, once `retry_after` elapses —
   independent of whether the original worker is still actually running it. The shared
   `database`/`redis` connection's `retry_after` (~90s) is far shorter than a real
   diarization run, so sharing it would cause the same diarization request to be picked
   up and run a second time (double S3 download, double request to the Speaches server,
   two processes racing to write the same `transcription_jobs` row) while the first one
   was still in flight. Each transcription connection sets its own `retry_after` above
   its job's timeout specifically to avoid this: `transcription` →
   `TRANSCRIPTION_QUEUE_RETRY_AFTER` (default 3900), `transcription_process` →
   `TRANSCRIPTION_PROCESS_QUEUE_RETRY_AFTER` (default 7800). If you change
   `SPEACHES_DIARIZATION_TIMEOUT_CEILING`, change these with it.

Both transcription connections intentionally always use the `database` driver
(`config/queue.php`), regardless of the app's main `QUEUE_CONNECTION` (which is `redis`
in `.env.prod` / `.env.staging`, `database` in `.env.dev`). The `jobs` table exists in
every environment via Laravel's standard queue migration either way, and a MySQL-backed
queue avoids tying a job that must survive up to an hour (or two) to Redis's
eviction/memory policies.

## Failure handling

Both jobs set `public int $tries = 1` (no automatic queue-level retry — the provider
already retries transient failures internally, and a request that legitimately ran out
of time would just burn the same time again for the same result) and implement
`failed(Throwable $exception)`, which marks the underlying `transcription_jobs` row as
`status = 'failed'` with an error message. This matters specifically because a
worker-level timeout kill (`SIGALRM`) terminates the PHP process with `exit()`, bypassing
the job's own `handle()`/`catch` block entirely — without the `failed()` hook, a killed
job would leave `transcription_jobs.status` stuck at `analyzing_speakers` (phase 1) or
`transcribing` (phase 2) forever with no error surfaced to the user. (Verified
empirically against a real `queue:work` process during the investigation that led to
this change.) `ProcessTranscriptionJob.failed()` additionally leaves the row alone if
`transcribeChunksParallel()` already marked it failed with a more specific message.

## Local development

- Docker: `docker compose -f _docker/compose/docker-compose.dev.yml up -d` brings up
  `queue-transcription` and `queue-transcription-process` alongside everything else.
- Non-docker (`php hawki dev` / `bin/hawki/development.php`):
  `php artisan queue:work transcription --queue=transcription &` and
  `php artisan queue:work transcription_process --queue=transcription_process &` lines
  run alongside the other queue workers.
- Composer scripts: `composer run queue-transcription` and
  `composer run queue-transcription-process` run them standalone.
