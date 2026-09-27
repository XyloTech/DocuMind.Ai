<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class FirebaseIdentityLookup
{
    /**
     * Resolve a Firebase ID token through the configured Firebase project.
     *
     * @return array{uid: string, email: string, name: string, avatar_url: ?string}|null
     */
    public function lookup(string $idToken): ?array
    {
        $apiKey = (string) config('services.firebase.api_key');

        if ($apiKey === '') {
            throw new RuntimeException('Firebase Authentication is not configured.');
        }

        $response = Http::connectTimeout(3)
            ->timeout(8)
            ->post('https://identitytoolkit.googleapis.com/v1/accounts:lookup?key='.rawurlencode($apiKey), [
                'idToken' => $idToken,
            ]);

        $errorCode = strtoupper((string) $response->json('error.message', ''));

        if ($response->serverError() || $response->status() === 429
            || $response->status() === 403 || str_contains($errorCode, 'API_KEY')) {
            throw new RuntimeException('Firebase Authentication is temporarily unavailable.');
        }

        if (! $response->successful()) {
            return null;
        }

        $profile = $response->json('users.0');

        if (! is_array($profile)
            || ($profile['emailVerified'] ?? false) !== true
            || ! is_string($profile['localId'] ?? null)
            || ! is_string($profile['email'] ?? null)
            || ! filter_var($profile['email'], FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $avatarUrl = $profile['photoUrl'] ?? null;

        if (! is_string($avatarUrl)
            || ! filter_var($avatarUrl, FILTER_VALIDATE_URL)
            || parse_url($avatarUrl, PHP_URL_SCHEME) !== 'https'
            || ! str_ends_with((string) parse_url($avatarUrl, PHP_URL_HOST), '.googleusercontent.com')) {
            $avatarUrl = null;
        }

        $name = $profile['displayName'] ?? '';

        return [
            'uid' => $profile['localId'],
            'email' => mb_strtolower($profile['email']),
            'name' => is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 255) : mb_substr($profile['email'], 0, 255),
            'avatar_url' => $avatarUrl === null ? null : mb_substr($avatarUrl, 0, 2048),
        ];
    }
}
