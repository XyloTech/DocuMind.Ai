<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Consent Version
    |--------------------------------------------------------------------------
    |
    | Bumped whenever the privacy notice changes so an account can be told
    | apart from users who agreed to an older, different wording.
    |
    */

    'consent_version' => '2026-09-25',

    /*
    |--------------------------------------------------------------------------
    | Retention Options
    |--------------------------------------------------------------------------
    |
    | Conversation retention windows offered in the privacy settings, in days.
    | The empty option means "keep until I delete it myself". The scheduled
    | `privacy:prune` command enforces whatever an account picks.
    |
    */

    'retention_options' => [30, 90, 365],

    /*
    |--------------------------------------------------------------------------
    | Training Export
    |--------------------------------------------------------------------------
    |
    | Where `privacy:training-export` writes its anonymised JSONL dataset.
    | Nothing is ever written without verified training consent per account.
    |
    */

    'training_disk' => env('PRIVACY_TRAINING_DISK', 'local'),

    'training_path' => 'training',

];
