<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Local Model Service
    |--------------------------------------------------------------------------
    |
    | DocuMind ships its own Python inference service (see the ml/ folder), so
    | no external AI API is required. It exposes an OpenAI-compatible surface
    | at /v1/embeddings and /v1/chat/completions, which the "local" driver
    | reuses. Set RAG_AI_DRIVER=local to talk to it.
    |
    */

    'base_url' => env('ML_BASE_URL', 'http://ml:8090/v1'),

    'api_key' => env('ML_API_KEY', 'local'),

    'enabled' => (bool) env('ML_ENABLED', true),

    'chat_model' => env('ML_CHAT_MODEL', 'Qwen/Qwen2.5-3B-Instruct-GGUF'),

    /*
    |--------------------------------------------------------------------------
    | Public Model Name
    |--------------------------------------------------------------------------
    |
    | The branded name shown in the interface. The technical identifier above
    | stays in the database for debugging, but it is never rendered: users see
    | this name (plus the latency) under every answer.
    |
    */

    'display_name' => env('ML_DISPLAY_NAME', 'SonicRock Pro'),

    /*
    |--------------------------------------------------------------------------
    | Model Aliases
    |--------------------------------------------------------------------------
    |
    | Optional per-model overrides keyed by a fragment of the raw identifier,
    | matched case-insensitively. Anything that does not match a key falls back
    | to `display_name`, so a repository path can never leak into the UI.
    |
    */

    'model_aliases' => [
        'gpt-4o-mini' => 'SonicRock Fast',
        'gpt-4o' => 'SonicRock Pro',
    ],

    'embedding_model' => env('ML_EMBEDDING_MODEL', 'BAAI/bge-small-en-v1.5'),

    'timeout' => (int) env('ML_TIMEOUT', 300),

    /*
    |--------------------------------------------------------------------------
    | Health Check
    |--------------------------------------------------------------------------
    |
    | Optional endpoint used by the admin panel and tests to verify the model
    | service is actually answering.
    |
    */

    'health_url' => env('ML_HEALTH_URL', 'http://ml:8090/health'),

];
