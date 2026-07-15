<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Transcription Service Settings
    |--------------------------------------------------------------------------
    |
    | This file is for storing the settings for the Transcription Service.
    |
    */

    'provider' => 'custom_speaches',
    'base_url' => 'http://134.176.150.177/v1',
    'api_key' => '',
    'model' => 'Systran/faster-whisper-large-v3',
    'diarization_model' => 'pyannote/speaker-diarization-community-1',

    /*
    |--------------------------------------------------------------------------
    | Max Concurrency
    |--------------------------------------------------------------------------
    |
    | The maximum number of transcription requests that may be in flight at the
    | Speaches server at any one time, across ALL jobs and chunks. This must
    | match the number of model instances the Speaches server can run in
    | parallel, otherwise surplus requests will queue, time out, or be rejected.
    |
    */
    'max_concurrency' => (int) env('SPEACHES_MAX_CONCURRENCY', 3),

    /*
    |--------------------------------------------------------------------------
    | Retry
    |--------------------------------------------------------------------------
    |
    | Transient failures (connection errors or HTTP 5xx) talking to the Speaches
    | server are retried with a fixed backoff, so a single hiccup does not abort
    | an entire transcription job.
    |
    */
    'retry_times' => (int) env('SPEACHES_RETRY_TIMES', 3),
    'retry_delay_ms' => (int) env('SPEACHES_RETRY_DELAY_MS', 3000),

    /*
    |--------------------------------------------------------------------------
    | Diarization Timeout
    |--------------------------------------------------------------------------
    |
    | Diarization runs on the whole (unchunked) audio file, so its request
    | timeout must scale with the file's duration instead of using the flat
    | timeout applied to transcription chunks. The budget is:
    |
    |   clamp(duration_seconds * multiplier + buffer_seconds, floor, ceiling)
    |
    | Tune these once real timings from the Speaches server are known.
    |
    */
    'diarization_timeout_multiplier' => (float) env('SPEACHES_DIARIZATION_TIMEOUT_MULTIPLIER', 2.0),
    'diarization_timeout_buffer_seconds' => (int) env('SPEACHES_DIARIZATION_TIMEOUT_BUFFER', 120),
    'diarization_timeout_floor' => (int) env('SPEACHES_DIARIZATION_TIMEOUT_FLOOR', 600),
    'diarization_timeout_ceiling' => (int) env('SPEACHES_DIARIZATION_TIMEOUT_CEILING', 3600),
];
