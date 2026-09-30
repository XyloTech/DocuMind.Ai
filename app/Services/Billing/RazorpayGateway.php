<?php

namespace App\Services\Billing;

use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper over the Razorpay Orders API.
 *
 * Orders and signatures are all we need: the browser completes the payment
 * through Razorpay's own hosted checkout, so no card data ever touches this
 * application. Everything here is either a server-to-server call with the
 * key/secret basic-auth pair, or an HMAC check against the same secret.
 */
class RazorpayGateway
{
    private const ORDERS_URL = 'https://api.razorpay.com/v1/orders';

    public function isConfigured(): bool
    {
        return $this->keyId() !== '' && $this->secret() !== '';
    }

    public function keyId(): string
    {
        return (string) config('services.razorpay.key_id');
    }

    public function secret(): string
    {
        return (string) config('services.razorpay.secret');
    }

    /**
     * Register the order with Razorpay and stamp its id on the payment row.
     *
     * The amount, currency and receipt are read from the payment record we
     * created ourselves, so the client can never influence what it is charged
     * for.
     */
    public function createOrder(Payment $payment): Payment
    {
        $response = Http::withBasicAuth($this->keyId(), $this->secret())
            ->timeout(15)
            ->post(self::ORDERS_URL, [
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'receipt' => 'payment_'.$payment->getKey(),
                'notes' => [
                    'user_id' => (string) $payment->user_id,
                    'pack_id' => $payment->pack_id,
                ],
            ]);

        $response->throw();

        $orderId = (string) $response->json('id');

        if ($orderId === '') {
            throw new RuntimeException('Razorpay returned an order without an id.');
        }

        $payment->forceFill(['razorpay_order_id' => $orderId])->save();

        return $payment;
    }

    /**
     * Checkout callback check: Razorpay signs "order_id|payment_id" with the
     * key secret. The signature only exists if Razorpay processed the payment
     * against our order, so it doubles as proof the order id is genuine.
     */
    public function verifyCheckoutSignature(string $orderId, string $paymentId, string $signature): bool
    {
        return $this->signs($orderId.'|'.$paymentId, $signature);
    }

    /**
     * Webhook check: HMAC-SHA256 of the raw request body, so the payload
     * cannot be altered in transit even though the endpoint is public.
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        return $this->signs($payload, $signature);
    }

    private function signs(string $value, string $signature): bool
    {
        $secret = $this->secret();

        if ($signature === '' || $secret === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $value, $secret), $signature);
    }
}
