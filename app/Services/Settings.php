<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Read and write application settings stored in the settings table.
 *
 * Secret values (API keys, payment secrets) are encrypted at rest and are
 * only ever decrypted when read through this service.
 */
class Settings
{
    private const string SECRET_KEY = 'secret';

    /**
     * Request-level memo so a page render issues a single query.
     *
     * @var array<string, array{value: mixed, is_secret: bool}|null>
     */
    private array $loaded = [];

    public function get(string $key, mixed $default = null): mixed
    {
        $entry = $this->remember($key);

        if ($entry === null) {
            return $default;
        }

        return $entry['is_secret'] ? $this->decrypt((string) $entry['value']) : $entry['value'];
    }

    public function getString(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function has(string $key): bool
    {
        return $this->remember($key) !== null;
    }

    public function set(string $key, mixed $value, string $group = 'general', bool $isSecret = false): void
    {
        $stored = $isSecret ? [self::SECRET_KEY => Crypt::encryptString((string) $value)] : $value;

        Setting::updateOrCreate(
            ['key' => $key],
            ['value' => $stored, 'group' => $group, 'is_secret' => $isSecret],
        );

        unset($this->loaded[$key]);
    }

    /**
     * @return array{value: mixed, is_secret: bool}|null
     */
    private function remember(string $key): ?array
    {
        if (! array_key_exists($key, $this->loaded)) {
            $this->loaded[$key] = $this->load($key);
        }

        return $this->loaded[$key];
    }

    /**
     * @return array{value: mixed, is_secret: bool}|null
     */
    private function load(string $key): ?array
    {
        $setting = Setting::query()->where('key', $key)->first();

        if ($setting === null) {
            return null;
        }

        return [
            'value' => $setting->is_secret ? (string) ($setting->value[self::SECRET_KEY] ?? '') : $setting->value,
            'is_secret' => $setting->is_secret,
        ];
    }

    private function decrypt(string $payload): ?string
    {
        if ($payload === '') {
            return null;
        }

        try {
            return Crypt::decryptString($payload);
        } catch (DecryptException) {
            return null;
        }
    }
}
