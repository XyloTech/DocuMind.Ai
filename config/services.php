<?php

$firebaseApiKey = env('FIREBASE_API_KEY', 'AIzaSyBGYCWtQ3ULVqP5QDum7Qhz14bD2PPcTaQ');

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'razorpay' => [
        'key_id' => env('RAZORPAY_KEY_ID'),
        'secret' => env('RAZORPAY_KEY_SECRET'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'firebase' => [
        'api_key' => $firebaseApiKey,
        'web' => [
            'apiKey' => $firebaseApiKey,
            'authDomain' => env('FIREBASE_AUTH_DOMAIN', 'schoolss-fb542.firebaseapp.com'),
            'projectId' => env('FIREBASE_PROJECT_ID', 'schoolss-fb542'),
            'storageBucket' => env('FIREBASE_STORAGE_BUCKET', 'schoolss-fb542.firebasestorage.app'),
            'messagingSenderId' => env('FIREBASE_MESSAGING_SENDER_ID', '253220830799'),
            'appId' => env('FIREBASE_APP_ID', '1:253220830799:web:6f9e02dc3a7b3bfc64743a'),
            'measurementId' => env('FIREBASE_MEASUREMENT_ID', 'G-C6HW7S2VBK'),
        ],
    ],

];
