@extends('layouts.guest')

@section('title', 'Create account')

@section('content')
    <div class="mb-6">
        <h1 class="text-lg font-semibold text-slate-900">Create your account</h1>
        <p class="mt-1 text-sm text-slate-500">
            Start with
            <span class="font-medium text-indigo-600">{{ config('billing.welcome_credits') }} free credits</span>
            when you add your first support knowledge source.
        </p>
    </div>

    <form method="POST" action="{{ route('register.store') }}" class="space-y-4">
        @csrf

        <x-form.text-input
            name="name"
            label="Name"
            autocomplete="name"
            :value="old('name')"
            required
        />

        <x-form.text-input
            name="email"
            label="Email address"
            type="email"
            autocomplete="email"
            :value="old('email')"
            required
        />

        <x-form.text-input
            name="password"
            label="Password"
            type="password"
            autocomplete="new-password"
            required
        />

        <x-form.text-input
            name="password_confirmation"
            label="Confirm password"
            type="password"
            autocomplete="new-password"
            required
        />

        <button
            type="submit"
            class="btn btn-primary w-full"
        >
            Create account
        </button>
    </form>

    <p class="mt-6 text-center text-sm text-slate-500">
        Already have an account?
        <a href="{{ route('login') }}" class="font-medium text-indigo-600 hover:text-indigo-500">Sign in</a>
    </p>
@endsection
