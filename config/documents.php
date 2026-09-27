<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Upload Limits
    |--------------------------------------------------------------------------
    */

    'max_size_kb' => (int) env('DOCUMENT_MAX_SIZE_KB', 25600),

    'max_per_user' => (int) env('DOCUMENT_MAX_PER_USER', 50),

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Documents live on a non-public disk so they can never be fetched with a
    | bare URL. They are streamed through an authenticated controller instead.
    |
    */

    'disk' => env('DOCUMENT_DISK', 'local'),

    'directory' => 'documents',

];
