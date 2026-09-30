<?php

namespace App\Http\Controllers;

use App\Enums\NotificationType;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\CreditLedger;
use App\Services\Billing\RazorpayGateway;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class BillingController extends Controller
{
    public function __construct(
        private readonly RazorpayGateway $razorpay,
        private readonly CreditLedger $ledger,
    ) {}

    /**
     * Credit packs and the purchase history for the current account.
     */
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('billing', [
            'user' => $user,
            'packs' => $this->packs(),
            'configured' => $this->razorpay->isConfigured(),
            'payments' => Payment::query()
                ->where('user_id', $user->getKey())
                ->orderByDesc('id')
                ->limit(20)
                ->get(),
        ]);
    }

    /**
     * Create the Razorpay order the browser opens checkout with.
     */
    public function checkout(Request $request): JsonResponse
    {
        abort_unless($this->razorpay->isConfigured(), 503, 'Card payments are not configured on this installation.');

        $validated = $request->validate([
            'pack' => ['required', 'string', Rule::in(array_keys($this->packs()))],
        ]);

        /** @var User $user */
        $user = $request->user();
        $pack = $this->packs()[$validated['pack']];

        $payment = Payment::create([
            'user_id' => $user->getKey(),
            'pack_id' => $validated['pack'],
            'credits' => $pack['credits'],
            'amount' => $pack['amount'],
            'currency' => (string) config('billing.currency', 'INR'),
        ]);

        try {
            $this->razorpay->createOrder($payment);
        } catch (Throwable $exception) {
            Payment::query()
                ->whereKey($payment->getKey())
                ->where('status', PaymentStatus::Pending->value)
                ->update(['status' => PaymentStatus::Failed->value]);

            report($exception);

            return response()->json([
                'message' => 'We could not start the checkout. Please try again in a moment.',
            ], 502);
        }

        return response()->json([
            'key' => $this->razorpay->keyId(),
            'order_id' => $payment->razorpay_order_id,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'pack' => [
                'id' => $validated['pack'],
                'name' => $pack['name'],
                'credits' => $pack['credits'],
            ],
        ]);
    }

    /**
     * Confirm the browser callback: check the signature, then credit the
     * account. The Razorpay webhook runs the same capture path, so whichever
     * arrives first wins and the other becomes a no-op.
     */
    public function verify(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'razorpay_order_id' => ['required', 'string', 'max:64'],
            'razorpay_payment_id' => ['required', 'string', 'max:64'],
            'razorpay_signature' => ['required', 'string', 'max:255'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $payment = Payment::query()
            ->where('user_id', $user->getKey())
            ->where('razorpay_order_id', $validated['razorpay_order_id'])
            ->first();

        $trusted = $payment !== null && $this->razorpay->verifyCheckoutSignature(
            $validated['razorpay_order_id'],
            $validated['razorpay_payment_id'],
            $validated['razorpay_signature'],
        );

        if (! $trusted) {
            return redirect()
                ->route('billing.index')
                ->withErrors(['billing' => 'We could not verify that payment. If you were charged, it will be credited automatically.']);
        }

        if ($this->capture($payment, $validated['razorpay_payment_id'])) {
            return redirect()
                ->route('billing.index')
                ->with('status', 'Payment confirmed — '.$payment->credits.' credits added.');
        }

        return redirect()
            ->route('billing.index')
            ->with('status', 'This purchase was already credited.');
    }

    /**
     * Razorpay's server-to-server callback. Public by definition, so the
     * signature over the raw body is the only thing admitting it.
     */
    public function webhook(Request $request): Response
    {
        $signature = (string) $request->header('X-Razorpay-Signature', '');

        abort_unless(
            $this->razorpay->verifyWebhookSignature($request->getContent(), $signature),
            403,
            'Invalid webhook signature.',
        );

        $event = (string) $request->json('event');
        $orderId = (string) $request->json('payload.payment.entity.order_id');
        $paymentId = (string) $request->json('payload.payment.entity.id');

        $payment = $orderId === ''
            ? null
            : Payment::query()->where('razorpay_order_id', $orderId)->first();

        if ($payment === null) {
            return response('ignored', 200);
        }

        if ($event === 'payment.captured') {
            $this->capture($payment, $paymentId);
        } elseif ($event === 'payment.failed') {
            Payment::query()
                ->whereKey($payment->getKey())
                ->where('status', PaymentStatus::Pending->value)
                ->update([
                    'status' => PaymentStatus::Failed->value,
                    'razorpay_payment_id' => $paymentId === '' ? null : $paymentId,
                ]);
        }

        return response('ok', 200);
    }

    /**
     * Mark the payment paid and release the credits exactly once.
     *
     * The conditional UPDATE on a still-pending row is the race guard: the
     * browser callback and the webhook may both call this, but only one
     * claims the row, and the credit grant rides along in the same
     * transaction so a crash can never leave a paid row uncredited.
     */
    private function capture(Payment $payment, string $paymentId): bool
    {
        $credited = DB::transaction(function () use ($payment, $paymentId): bool {
            $claimed = Payment::query()
                ->whereKey($payment->getKey())
                ->where('status', PaymentStatus::Pending->value)
                ->update([
                    'status' => PaymentStatus::Paid->value,
                    'razorpay_payment_id' => $paymentId,
                    'paid_at' => now(),
                ]);

            if ($claimed === 0) {
                return false;
            }

            /** @var User $user */
            $user = User::query()->findOrFail($payment->user_id);

            $this->ledger->grant($user, $payment->credits);

            Notifier::make()
                ->type(NotificationType::CreditsChanged)
                ->to($user)
                ->title('Credits added')
                ->body(sprintf(
                    'Your purchase added %d %s. New balance: %d %s.',
                    $payment->credits,
                    Str::plural('credit', $payment->credits),
                    $user->credits,
                    Str::plural('credit', $user->credits),
                ))
                ->send();

            return true;
        });

        $payment->refresh();

        return $credited;
    }

    /**
     * Buyable packs from config, with unusable entries (no credits, no price)
     * filtered out so a typo can never sell something for free.
     *
     * @return array<string, array{name: string, credits: int, amount: int}>
     */
    private function packs(): array
    {
        $packs = [];

        foreach ((array) config('billing.packs', []) as $id => $pack) {
            if (! is_array($pack)) {
                continue;
            }

            $credits = (int) ($pack['credits'] ?? 0);
            $amount = (int) ($pack['amount'] ?? 0);

            if ($credits <= 0 || $amount <= 0) {
                continue;
            }

            $packs[(string) $id] = [
                'name' => (string) ($pack['name'] ?? $credits.' credits'),
                'credits' => $credits,
                'amount' => $amount,
            ];
        }

        return $packs;
    }
}
