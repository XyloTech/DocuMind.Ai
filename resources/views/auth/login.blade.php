@extends('layouts.guest')

@section('title', 'Sign in')

@section('content')
    {{-- The assistant perches on the card's top edge. Hydrated by
         hydrateBlobatars(): idle bob/blink from the blobatar package,
         eyes follow the cursor, both disabled under reduced motion. --}}
    <div class="-mt-16 flex justify-center">
        <span
            class="auth-blob"
            data-blobatar-name="DocuMind"
            data-blobatar-background="circle"
            data-blobatar-animate="live"
            data-blobatar-expression="happy"
            data-blobatar-follow="true"
            aria-hidden="true"
        ></span>
    </div>

    <div class="text-center">
        <span class="auth-eyebrow">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
            </svg>
            Secure sign-in
        </span>

        <h1 class="mt-3 text-[1.6rem] font-extrabold tracking-tight text-slate-900 dark:text-white">Welcome back</h1>
        <p class="mt-1.5 text-sm leading-6 text-slate-500 dark:text-slate-400">Continue securely to your support workspace.</p>
    </div>

    <div
        data-google-login
        data-auth-url="{{ route('login.firebase') }}"
        data-firebase-config="{{ json_encode(config('services.firebase.web'), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG) }}"
        class="mt-6"
    >
        <button
            type="button"
            data-google-login-button
            class="btn btn-google w-full gap-3 disabled:cursor-wait"
        >
            {{-- Official Google "G" — the four-colour 48px mark. --}}
            <svg class="h-5 w-5 shrink-0" viewBox="0 0 48 48" aria-hidden="true">
                <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
                <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
            </svg>
            <span data-google-login-label>Continue with Google</span>
            <span data-google-login-spinner hidden class="h-4 w-4 animate-spin rounded-full border-2 border-current border-r-transparent" aria-hidden="true"></span>
        </button>
        <p data-google-login-status role="status" aria-live="polite" class="mt-3 min-h-6 text-center text-sm leading-6 text-slate-500 dark:text-slate-400"></p>
    </div>

    <p class="mt-4 flex items-start justify-center gap-1.5 text-center text-xs leading-5 text-slate-500 dark:text-slate-400">
        <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-500 dark:text-emerald-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
        </svg>
        <span>Workspace invitations are matched to your verified Google email.</span>
    </p>
@endsection
