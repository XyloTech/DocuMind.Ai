<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Billing\CreditLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_deduct_removes_credits_when_the_balance_covers_them(): void
    {
        $user = User::factory()->create(['credits' => 5]);

        $this->assertTrue(app(CreditLedger::class)->deduct($user, 3));
        $this->assertSame(2, $user->fresh()->credits);
    }

    public function test_deduct_refuses_to_push_the_balance_below_zero(): void
    {
        $user = User::factory()->create(['credits' => 1]);

        $this->assertFalse(app(CreditLedger::class)->deduct($user, 2));
        $this->assertSame(1, $user->fresh()->credits);
    }

    public function test_a_refund_gives_credits_back(): void
    {
        $user = User::factory()->create(['credits' => 0]);

        app(CreditLedger::class)->refund($user, 4);

        $this->assertSame(4, $user->fresh()->credits);
    }

    public function test_a_healthy_balance_never_warns(): void
    {
        $user = User::factory()->create(['credits' => 50]);

        app(CreditLedger::class)->deduct($user, 1);

        $this->assertSame(0, UserNotification::query()->count());
    }

    public function test_hitting_the_low_credit_threshold_warns_once_and_then_refreshes_the_same_row(): void
    {
        $threshold = (int) config('billing.low_credits_threshold');
        $user = User::factory()->create(['credits' => $threshold + 1]);

        app(CreditLedger::class)->deduct($user, 1);
        app(CreditLedger::class)->deduct($user, 1);
        app(CreditLedger::class)->deduct($user, 1);

        $rows = UserNotification::query()->where('user_id', $user->getKey())->get();

        $this->assertCount(1, $rows);
        $this->assertSame(NotificationType::LowCredits, $rows[0]->type);
        $this->assertSame('billing.low_credits.'.$user->getKey().'.'.$threshold, $rows[0]->dedupe_key);
        $this->assertStringContainsString(($threshold - 2).' credits left', (string) $rows[0]->body);
    }

    public function test_two_racing_spenders_cannot_both_take_the_last_credit(): void
    {
        $user = User::factory()->create(['credits' => 1]);

        $first = app(CreditLedger::class)->deduct($user, 1);
        $second = app(CreditLedger::class)->deduct($user, 1);

        $this->assertTrue($first);
        $this->assertFalse($second);
        $this->assertSame(0, $user->fresh()->credits);
    }
}
