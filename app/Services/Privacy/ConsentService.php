<?php

namespace App\Services\Privacy;

use App\Models\User;

/**
 * Applies and revokes consent. Nothing about a choice is remembered without an
 * audit entry, and consent is versioned so a changed privacy notice can be
 * detected instead of silently inheriting an old agreement.
 */
final class ConsentService
{
    public function __construct(private readonly PrivacyAudit $audit) {}

    /**
     * Answer the consent prompt for the first time (or re-answer it after a
     * revocation).
     *
     * @param  array<string, bool>  $choices
     */
    public function record(User $user, array $choices, ?string $ip = null): void
    {
        $history = (bool) ($choices['store_chat_history'] ?? false);
        $training = (bool) ($choices['allow_model_training'] ?? false);

        $user->store_chat_history = $history;
        $user->allow_model_training = $training;
        $user->privacy_consent_at = now();
        $user->privacy_consent_version = (string) config('privacy.consent_version');
        $user->save();

        $this->audit->record(
            $user,
            'consent.recorded',
            'Consent recorded: history '.($history ? 'on' : 'off').', training '.($training ? 'on' : 'off').'.',
            [
                'store_chat_history' => $history,
                'allow_model_training' => $training,
                'version' => $user->privacy_consent_version,
            ],
            $ip,
        );
    }

    /**
     * Change the toggles (and optional retention window) from the settings page.
     *
     * @param  array<string, mixed>  $choices
     */
    public function update(User $user, array $choices, ?string $ip = null): void
    {
        $history = (bool) ($choices['store_chat_history'] ?? false);
        $training = (bool) ($choices['allow_model_training'] ?? false);

        $retention = array_key_exists('chat_retention_days', $choices)
            ? $this->retention($choices['chat_retention_days'])
            : $user->chat_retention_days;

        $changes = [
            'store_chat_history' => [$user->store_chat_history, $history],
            'allow_model_training' => [$user->allow_model_training, $training],
            'chat_retention_days' => [$user->chat_retention_days, $retention],
        ];

        $user->store_chat_history = $history;
        $user->allow_model_training = $training;
        $user->chat_retention_days = $retention;

        if (! $user->hasGivenConsent()) {
            $user->privacy_consent_at = now();
            $user->privacy_consent_version = (string) config('privacy.consent_version');
        }

        $user->save();

        $changed = array_keys(array_filter(
            $changes,
            static fn (array $pair): bool => $pair[0] !== $pair[1],
        ));

        $this->audit->record(
            $user,
            $changed === [] ? 'settings.viewed' : 'settings.updated',
            $changed === []
                ? 'Privacy settings confirmed with no change.'
                : 'Privacy settings updated: '.implode(', ', $changed).'.',
            ['changed' => $changed] + $changes,
            $ip,
        );
    }

    /**
     * Withdraw consent entirely: the prompt returns and no further retention
     * or training is permitted until it is answered again.
     */
    public function revoke(User $user, ?string $ip = null): void
    {
        $user->store_chat_history = false;
        $user->allow_model_training = false;
        $user->privacy_consent_at = null;
        $user->privacy_consent_version = null;
        $user->save();

        $this->audit->record(
            $user,
            'consent.revoked',
            'Consent withdrawn. Chat storage and training are off.',
            [],
            $ip,
        );
    }

    /**
     * Accept only offered retention windows so a crafted request cannot set an
     * arbitrary value.
     */
    private function retention(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === '0') {
            return null;
        }

        $days = (int) $value;

        return in_array($days, config('privacy.retention_options', []), true) ? $days : null;
    }
}
