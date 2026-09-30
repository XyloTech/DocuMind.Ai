@extends('layouts.app')

@section('title', 'Billing & credits · '.config('app.name'))

@section('content')
    @php
        $currency = (string) config('billing.currency', 'INR');
        $symbols = ['INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'];
        $symbol = $symbols[$currency] ?? '';
        $decimals = $currency === 'INR' ? 0 : 2;
    @endphp

    <div class="mx-auto w-full max-w-4xl">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                    Billing &amp; credits
                </h1>
                <p class="mt-1.5 max-w-xl text-sm leading-relaxed text-slate-500 dark:text-slate-500">
                    Every chat message spends one credit. Top up instantly with UPI, cards or netbanking —
                    credits land in your balance as soon as the payment is confirmed.
                </p>
            </div>

            <div class="inline-flex items-center gap-2 rounded-xl border border-slate-200/80 bg-white px-4 py-2.5 text-sm text-slate-600 shadow-xs dark:border-white/10 dark:bg-white/5 dark:text-slate-600">
                <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                <span class="font-bold text-slate-900 dark:text-white" data-credits-count>{{ $user->credits }}</span>
                {{ \Illuminate\Support\Str::plural('credit', $user->credits) }} available
            </div>
        </div>

        @unless ($configured)
            <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300" role="status">
                Card payments are not enabled on this installation yet. Add your Razorpay
                <code class="font-mono text-xs">RAZORPAY_KEY_ID</code> and
                <code class="font-mono text-xs">RAZORPAY_KEY_SECRET</code> to start selling credits.
            </div>
        @endunless

        <p
            data-billing-error
            hidden
            class="mt-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300"
            role="alert"
        ></p>

        <section class="mt-6">
            <div class="mb-3 flex items-end justify-between gap-2">
                <div>
                    <h2 class="text-sm font-bold text-slate-800 dark:text-slate-200">Credit packs</h2>
                    <p class="mt-0.5 text-xs text-slate-400 dark:text-slate-500">One-time purchases — credits never expire.</p>
                </div>
            </div>

            <div
                data-billing
                data-checkout-url="{{ route('billing.checkout') }}"
                data-verify-url="{{ route('billing.verify') }}"
                data-csrf="{{ csrf_token() }}"
                data-user-name="{{ $user->name }}"
                data-user-email="{{ $user->email }}"
                data-app-name="{{ config('app.name') }}"
                class="grid gap-4 sm:grid-cols-3"
            >
                @foreach ($packs as $id => $pack)
                    <div class="dm-card flex flex-col rounded-2xl border border-slate-200 bg-white p-5 shadow-xs dark:border-white/10 dark:bg-white/[0.02]">
                        <p class="text-xs font-bold uppercase tracking-wide text-indigo-600 dark:text-indigo-400">{{ $pack['name'] }}</p>
                        <p class="mt-2 text-3xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                            {{ $pack['credits'] }}
                            <span class="text-sm font-semibold text-slate-500 dark:text-slate-400">credits</span>
                        </p>
                        <p class="mt-1 text-sm font-semibold text-slate-700 dark:text-slate-300">
                            {{ $symbol }}{{ number_format($pack['amount'] / 100, $decimals) }}
                            <span class="text-xs font-normal text-slate-400">{{ $currency }}</span>
                        </p>

                        <button
                            type="button"
                            data-buy-pack="{{ $id }}"
                            class="btn btn-primary mt-5 w-full"
                            @unless ($configured && $pack['amount'] > 0) disabled @endunless
                        >
                            Buy pack
                        </button>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="mt-8 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs dark:border-white/10 dark:bg-white/[0.02]">
            <div class="border-b border-slate-100 bg-slate-50/70 px-5 py-4 dark:border-white/10 dark:bg-white/[0.02]">
                <h2 class="text-sm font-bold text-slate-900 dark:text-white">Purchase history</h2>
            </div>

            @if ($payments->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-slate-500 dark:text-slate-500">No purchases yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-slate-100 text-xs uppercase tracking-wide text-slate-400 dark:border-white/10 dark:text-slate-500">
                                <th scope="col" class="px-5 py-3 font-semibold">Date</th>
                                <th scope="col" class="px-5 py-3 font-semibold">Pack</th>
                                <th scope="col" class="px-5 py-3 font-semibold">Amount</th>
                                <th scope="col" class="px-5 py-3 font-semibold">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                            @foreach ($payments as $payment)
                                <tr class="text-slate-600 dark:text-slate-400">
                                    <td class="whitespace-nowrap px-5 py-3">{{ $payment->created_at->format('d M Y, H:i') }}</td>
                                    <td class="px-5 py-3">
                                        <span class="font-semibold text-slate-900 dark:text-white">{{ $payment->pack_id }}</span>
                                        <span class="ml-1.5 text-xs">+{{ $payment->credits }} credits</span>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-3">
                                        {{ $symbol }}{{ $payment->formattedAmount() }} {{ $payment->currency }}
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-3">
                                        @php($statusClass = match ($payment->status->value) {
                                            'paid' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
                                            'pending' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
                                            default => 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
                                        })
                                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $statusClass }}">
                                            {{ $payment->status->label() }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>

    @if ($configured)
        <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
        <script>
            (function () {
                const root = document.querySelector('[data-billing]');
                if (!root) return;

                const csrf = root.dataset.csrf;
                const errorBox = document.querySelector('[data-billing-error]');

                function showError(message) {
                    if (!errorBox) return;
                    errorBox.textContent = message;
                    errorBox.hidden = false;
                }

                function reset(button) {
                    button.disabled = false;
                }

                document.querySelectorAll('[data-buy-pack]').forEach((button) => {
                    button.addEventListener('click', async () => {
                        if (errorBox) errorBox.hidden = true;
                        button.disabled = true;

                        try {
                            const response = await fetch(root.dataset.checkoutUrl, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    Accept: 'application/json',
                                    'X-CSRF-TOKEN': csrf,
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                                body: JSON.stringify({ pack: button.dataset.buyPack }),
                            });

                            const payload = await response.json().catch(() => ({}));

                            if (!response.ok) {
                                throw new Error(payload.message || 'Checkout could not be started.');
                            }

                            openCheckout(payload, button);
                        } catch (error) {
                            showError(error.message || 'Checkout could not be started.');
                            reset(button);
                        }
                    });
                });

                function openCheckout(order, button) {
                    if (typeof Razorpay === 'undefined') {
                        showError('The payment window failed to load. Refresh the page and try again.');
                        reset(button);
                        return;
                    }

                    const checkout = new Razorpay({
                        key: order.key,
                        order_id: order.order_id,
                        amount: order.amount,
                        currency: order.currency,
                        name: root.dataset.appName,
                        description: order.pack.credits + ' credits · ' + order.pack.name,
                        prefill: {
                            name: root.dataset.userName,
                            email: root.dataset.userEmail,
                        },
                        theme: { color: '#4f46e5' },
                        handler: function (response) {
                            submitVerification(response);
                        },
                        modal: {
                            ondismiss: function () {
                                reset(button);
                            },
                        },
                    });

                    checkout.on('payment.failed', function (response) {
                        showError((response.error && response.error.description) || 'The payment did not go through.');
                        reset(button);
                    });

                    checkout.open();
                }

                function submitVerification(response) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = root.dataset.verifyUrl;

                    const fields = {
                        _token: csrf,
                        razorpay_order_id: response.razorpay_order_id,
                        razorpay_payment_id: response.razorpay_payment_id,
                        razorpay_signature: response.razorpay_signature,
                    };

                    Object.keys(fields).forEach((name) => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = name;
                        input.value = fields[name];
                        form.appendChild(input);
                    });

                    document.body.appendChild(form);
                    form.submit();
                }
            })();
        </script>
    @endif
@endsection
