<?php

namespace App\Support;

use App\Services\Settings;

/**
 * Presents a model answer with the public, branded name instead of the raw
 * identifier that actually ran (a Hugging Face repo id, a driver name such as
 * `local`, or a provider slug). Internal names, repository paths and provider
 * details must never reach the interface, so anything unmatched falls back to
 * the configured brand rather than being echoed back to the user.
 */
final class ModelBrand
{
    /**
     * The display name for a model identifier, e.g. "SonicRock Pro".
     */
    public static function name(?string $model): string
    {
        $brand = trim((string) config('ml.display_name'));

        $needle = strtolower(trim((string) $model));

        if ($needle !== '') {
            foreach ((array) config('ml.model_aliases', []) as $pattern => $alias) {
                $pattern = strtolower(trim((string) $pattern));
                $alias = trim((string) $alias);

                if ($pattern !== '' && $alias !== '' && str_contains($needle, $pattern)) {
                    return $alias;
                }
            }
        }

        return $brand !== '' ? $brand : 'DocuMind';
    }

    /**
     * The branded name of whichever model is currently answering, resolved from
     * the active driver so the UI never has to know about drivers at all.
     */
    public static function active(): string
    {
        $settings = app(Settings::class);
        $driver = $settings->getString('ai_driver', (string) config('rag.ai_driver', ''));

        if ($driver === '') {
            $driver = (bool) config('ml.enabled', true) ? 'local' : 'openai';
        }

        $raw = match ($driver) {
            'openai' => (string) config('openai.chat_model'),
            'fake' => 'DocuMind',
            default => (string) config('ml.chat_model'),
        };

        return self::name($raw);
    }

    /**
     * "15.8s" — latency rounded for humans, never a raw millisecond count.
     */
    public static function seconds(?int $latencyMs): ?string
    {
        if ($latencyMs === null || $latencyMs <= 0) {
            return null;
        }

        return round($latencyMs / 1000, 1).'s';
    }

    /**
     * The metadata line under an answer: "SonicRock Pro · 15.8s".
     */
    public static function label(?string $model, ?int $latencyMs): string
    {
        $seconds = self::seconds($latencyMs);

        return $seconds === null
            ? self::name($model)
            : self::name($model).' · '.$seconds;
    }
}
