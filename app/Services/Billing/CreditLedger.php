<?php

namespace App\Services\Billing;

use App\Enums\NotificationType;
use App\Models\User;
use App\Services\Notifier;
use Illuminate\Support\Str;

/**
 * Atomic credit movements.
 *
 * Every mutation runs as a single conditional UPDATE so two concurrent
 * requests can never both succeed against the last credit — the affected-rows
 * check *is* the concurrency guard, there is no read-then-write window.
 */
class CreditLedger
{
    public function balance(User $user): int
    {
        return (int) $user->fresh()?->credits ?? 0;
    }

    /**
     * Remove credits if, and only if, the balance still covers them.
     */
    public function deduct(User $user, int $amount = 1): bool
    {
        if ($amount <= 0) {
            return true;
        }

        $affected = (int) User::query()
            ->whereKey($user->getKey())
            ->where('credits', '>=', $amount)
            ->decrement('credits', $amount);

        $user->refresh();

        if ($affected > 0) {
            $this->warnIfLow($user);
        }

        return $affected > 0;
    }

    /**
     * One warning per threshold crossing, so a balance sliding through the
     * last credits tells the owner once instead of on every message.
     */
    private function warnIfLow(User $user): void
    {
        $threshold = max(1, (int) config('billing.low_credits_threshold'));

        if ($user->credits > $threshold) {
            return;
        }

        $cost = max(1, (int) config('billing.credits_per_message'));

        Notifier::make()
            ->type(NotificationType::LowCredits)
            ->to($user)
            ->title('Credits running low')
            ->body(sprintf(
                'You have %d %s left. Each chat message costs %d %s.',
                $user->credits,
                Str::plural('credit', $user->credits),
                $cost,
                Str::plural('credit', $cost),
            ))
            ->link(route('dashboard'), 'Open dashboard')
            ->dedupe('billing.low_credits.'.$user->getKey().'.'.$threshold)
            ->send();
    }

    /**
     * Return credits to the account. Refunds never fail: a user is never
     * charged for a message the model did not deliver.
     */
    public function refund(User $user, int $amount = 1): void
    {
        if ($amount <= 0) {
            return;
        }

        User::query()
            ->whereKey($user->getKey())
            ->increment('credits', $amount);

        $user->refresh();
    }

    /**
     * Grant credits (welcome bonus, admin top-up, compensation).
     */
    public function grant(User $user, int $amount = 1): void
    {
        $this->refund($user, $amount);
    }
}
