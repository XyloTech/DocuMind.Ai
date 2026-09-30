<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RazorpayBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_billing_page_lists_credit_packs(): void
    {
        $this->configureRazorpay();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('billing.index'))
            ->assertOk()
            ->assertSee('Starter')
            ->assertSee('Purchase history');
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('billing.index'))->assertRedirect(route('login'));
    }

    public function test_checkout_creates_a_pending_payment_and_returns_the_razorpay_order(): void
    {
        $this->configureRazorpay();
        Http::fake(['api.razorpay.com/*' => Http::response(['id' => 'order_abc123'], 200)]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('billing.checkout'), ['pack' => 'starter'])
            ->assertOk()
            ->assertJsonPath('key', 'rzp_test_key')
            ->assertJsonPath('order_id', 'order_abc123')
            ->assertJsonPath('amount', 19900)
            ->assertJsonPath('currency', 'INR');

        $payment = Payment::query()->sole();

        Http::assertSent(function (ClientRequest $request) use ($payment): bool {
            return $request->url() === 'https://api.razorpay.com/v1/orders'
                && $request['amount'] === 19900
                && $request['currency'] === 'INR'
                && $request['receipt'] === 'payment_'.$payment->getKey();
        });

        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame($user->getKey(), $payment->user_id);
        $this->assertSame('starter', $payment->pack_id);
        $this->assertSame(100, $payment->credits);
    }

    public function test_checkout_refuses_a_pack_that_is_not_for_sale(): void
    {
        $this->configureRazorpay();
        Http::fake();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('billing.checkout'), ['pack' => 'not-a-pack'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pack');

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_checkout_is_unavailable_without_razorpay_keys(): void
    {
        config(['services.razorpay.key_id' => '', 'services.razorpay.secret' => '']);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('billing.checkout'), ['pack' => 'starter'])
            ->assertStatus(503);

        Http::assertNothingSent();
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_a_valid_callback_credits_the_account_exactly_once(): void
    {
        $this->configureRazorpay();

        $user = User::factory()->withCredits(0)->create();
        $payment = Payment::factory()->for($user)->create(['razorpay_order_id' => 'order_abc123']);

        $callback = [
            'razorpay_order_id' => 'order_abc123',
            'razorpay_payment_id' => 'pay_abc123',
            'razorpay_signature' => $this->signature('order_abc123|pay_abc123'),
        ];

        $this->actingAs($user)
            ->post(route('billing.verify'), $callback)
            ->assertRedirect(route('billing.index'));

        $this->assertSame(100, $user->fresh()->credits);
        $this->assertTrue($payment->fresh()->isPaid());
        $this->assertSame('pay_abc123', $payment->fresh()->razorpay_payment_id);

        // A replay — the browser resubmitting, or the webhook arriving second —
        // must not sell the same credits twice.
        $this->actingAs($user)->post(route('billing.verify'), $callback);

        $this->assertSame(100, $user->fresh()->credits);
        $this->assertSame(1, UserNotification::query()->where('type', NotificationType::CreditsChanged)->count());
    }

    public function test_a_callback_with_a_bad_signature_credits_nothing(): void
    {
        $this->configureRazorpay();

        $user = User::factory()->withCredits(0)->create();
        $payment = Payment::factory()->for($user)->create(['razorpay_order_id' => 'order_abc123']);

        $this->actingAs($user)
            ->post(route('billing.verify'), [
                'razorpay_order_id' => 'order_abc123',
                'razorpay_payment_id' => 'pay_abc123',
                'razorpay_signature' => hash_hmac('sha256', 'forged', config('services.razorpay.secret')),
            ])
            ->assertRedirect(route('billing.index'))
            ->assertSessionHasErrors('billing');

        $this->assertSame(0, $user->fresh()->credits);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_a_callback_for_another_users_order_is_rejected(): void
    {
        $this->configureRazorpay();

        $owner = User::factory()->withCredits(0)->create();
        $stranger = User::factory()->withCredits(0)->create();

        Payment::factory()->for($owner)->create(['razorpay_order_id' => 'order_abc123']);

        $this->actingAs($stranger)
            ->post(route('billing.verify'), [
                'razorpay_order_id' => 'order_abc123',
                'razorpay_payment_id' => 'pay_abc123',
                'razorpay_signature' => $this->signature('order_abc123|pay_abc123'),
            ])
            ->assertSessionHasErrors('billing');

        $this->assertSame(0, $stranger->fresh()->credits);
        $this->assertSame(0, $owner->fresh()->credits);
    }

    public function test_the_webhook_credits_the_account_when_the_signature_matches(): void
    {
        $this->configureRazorpay();

        $user = User::factory()->withCredits(0)->create();
        Payment::factory()->for($user)->create(['razorpay_order_id' => 'order_abc123']);

        $this->postWebhook($this->capturedPayload())->assertOk();

        $this->assertSame(100, $user->fresh()->credits);
        $this->assertSame(PaymentStatus::Paid, Payment::query()->sole()->status);
    }

    public function test_the_webhook_rejects_a_bad_signature(): void
    {
        $this->configureRazorpay();

        $user = User::factory()->withCredits(0)->create();
        Payment::factory()->for($user)->create(['razorpay_order_id' => 'order_abc123']);

        $this->postWebhook($this->capturedPayload(), 'not-a-real-signature')->assertForbidden();

        $this->assertSame(0, $user->fresh()->credits);
        $this->assertSame(PaymentStatus::Pending, Payment::query()->sole()->status);
    }

    public function test_the_webhook_ignores_orders_it_never_created(): void
    {
        $this->configureRazorpay();

        $this->postWebhook($this->capturedPayload('order_unknown'))->assertOk();

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_a_failed_payment_webhook_marks_the_payment_failed(): void
    {
        $this->configureRazorpay();

        $user = User::factory()->withCredits(0)->create();
        Payment::factory()->for($user)->create(['razorpay_order_id' => 'order_abc123']);

        $payload = json_encode([
            'event' => 'payment.failed',
            'payload' => ['payment' => ['entity' => ['id' => 'pay_abc123', 'order_id' => 'order_abc123']]],
        ], JSON_THROW_ON_ERROR);

        $this->postWebhook($payload)->assertOk();

        $this->assertSame(PaymentStatus::Failed, Payment::query()->sole()->status);
        $this->assertSame(0, $user->fresh()->credits);
    }

    /**
     * Fire the webhook route with an exact raw body, the way Razorpay does:
     * the signature always covers precisely the bytes that arrive.
     */
    private function postWebhook(string $payload, ?string $signature = null)
    {
        $signature ??= hash_hmac('sha256', $payload, (string) config('services.razorpay.secret'));

        return $this->call('POST', '/webhooks/razorpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => $signature,
        ], $payload);
    }

    private function capturedPayload(string $orderId = 'order_abc123'): string
    {
        return json_encode([
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => ['id' => 'pay_abc123', 'order_id' => $orderId]]],
        ], JSON_THROW_ON_ERROR);
    }

    private function signature(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('services.razorpay.secret'));
    }

    private function configureRazorpay(): void
    {
        config([
            'services.razorpay.key_id' => 'rzp_test_key',
            'services.razorpay.secret' => 'razorpay_secret_test_value',
        ]);
    }
}
