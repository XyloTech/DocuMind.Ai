@extends('layouts.guest')

@section('title', 'Reset password')

@section('content')
    <div class="mb-6">
        <h1 class="text-lg font-semibold text-slate-900 dark:text-white">Reset your password</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">We will send a secure, time-limited reset link if an account matches.</p>
    </div>

    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <x-form.text-input
            name="email"
            label="Email address"
            type="email"
            autocomplete="email"
            :value="old('email')"
            required
        />

        <button type="submit" class="btn btn-primary w-full">
            Send reset link
        </button>
    </form>

    <p class="mt-6 text-center text-sm text-slate-500 dark:text-slate-400">
        <a href="{{ route('login') }}" class="font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">Back to sign in</a>
    </p>
@endsection