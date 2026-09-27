@extends('layouts.guest')

@section('title', 'Choose a new password')

@section('content')
    <div class="mb-6">
        <h1 class="text-lg font-semibold text-slate-900 dark:text-white">Choose a new password</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Use at least 12 characters with uppercase, lowercase, a number, and a symbol.</p>
    </div>

    <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <x-form.text-input
            name="email"
            label="Email address"
            type="email"
            autocomplete="email"
            :value="old('email', $email)"
            required
        />

        <x-form.text-input name="password" label="New password" type="password" autocomplete="new-password" required />
        <x-form.text-input name="password_confirmation" label="Confirm new password" type="password" autocomplete="new-password" required />

        <button type="submit" class="btn btn-primary w-full">
            Update password
        </button>
    </form>
@endsection