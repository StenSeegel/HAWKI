<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            'after_commit' => false,
        ],

        /*
        |----------------------------------------------------------------------
        | Transcription Connection
        |----------------------------------------------------------------------
        |
        | Separate connection for AnalyzeSpeakersJob (whole-file diarization),
        | which can legitimately run for up to
        | config('transcription.diarization_timeout_ceiling') (default 3600s).
        | It needs its own connection rather than sharing 'database':
        |   - retry_after must exceed the job's real runtime, or the database
        |     queue driver will consider the in-flight job abandoned and hand
        |     the same row to a second worker, causing a duplicate diarization
        |     request (double S3 download, double Speaches call, racing writes
        |     to the same TranscriptionJob row). Sharing the 'database'
        |     connection's retry_after (90s) would make this near-guaranteed.
        |   - a single worker process handles one job at a time; a long
        |     diarization run on the shared default/mails/message_broadcast
        |     worker would block mail delivery and broadcasts for its entire
        |     duration.
        |
        */
        'transcription' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('TRANSCRIPTION_QUEUE', 'transcription'),
            'retry_after' => (int) env('TRANSCRIPTION_QUEUE_RETRY_AFTER', 3900),
            'after_commit' => false,
        ],

        /*
        |----------------------------------------------------------------------
        | Transcription Process Connection
        |----------------------------------------------------------------------
        |
        | Separate connection for ProcessTranscriptionJob (phase 2: parallel
        | chunk transcription + final whole-file diarization + optional LLM
        | cleanup). It cannot share the 'transcription' connection above:
        | phase 2 runs the diarization-scale work twice over (transcription
        | pass + diarization pass), so its job timeout (~2x ceiling + 300s) is
        | roughly double AnalyzeSpeakersJob's — retry_after must exceed that
        | larger runtime for the same duplicate-delivery reasons documented
        | above. A separate queue/worker also keeps a running phase-2 job from
        | blocking other users' speaker previews (and vice versa).
        |
        */
        'transcription_process' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('TRANSCRIPTION_PROCESS_QUEUE', 'transcription_process'),
            'retry_after' => (int) env('TRANSCRIPTION_PROCESS_QUEUE_RETRY_AFTER', 7800),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
            'block_for' => null,
            'after_commit' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];
