<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'pack_id' => 'starter',
            'credits' => 100,
            'amount' => 19900,
            'currency' => 'INR',
            'status' => PaymentStatus::Pending,
            'razorpay_order_id' => 'order_'.fake()->unique()->numerify('##########'),
        ];
    }

    /**
     * A payment Razorpay has already captured.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Paid,
            'razorpay_payment_id' => 'pay_'.fake()->unique()->numerify('##########'),
            'paid_at' => now(),
        ]);
    }
}
