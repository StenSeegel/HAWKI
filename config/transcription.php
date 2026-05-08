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
    'min_speakers' => 1,
    'max_speakers' => 5,
];
