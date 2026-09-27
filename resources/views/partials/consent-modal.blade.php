{{--
    Blocking consent prompt shown until the account has answered it. Nothing is
    stored or trained before this is submitted, and both switches start off so
    the safe choice is the default one.
--}}
@if (auth()->check() && ! auth()->user()->hasGivenConsent())
    <div class="dm-consent" data-consent role="dialog" aria-modal="true" aria-labelledby="consent-title">
        <div class="dm-consent-card">
            <div class="p-6 sm:p-7">
                <span class="inline-flex items-center gap-1.5 rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-[11px] font-semibold text-indigo-600 dark:border-indigo-500/20 dark:bg-indigo-500/10 dark:text-indigo-300">
                    <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect width="18" height="11" x="3" y="11" rx="2" ry="2"/>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                    </svg>
                    Privacy
                </span>

                <h2 id="consent-title" class="mt-3 text-xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                    Your data, your choice
                </h2>

                <p class="mt-2 text-sm leading-relaxed text-slate-600 dark:text-slate-500">
                    Before your first conversation, here is exactly what DocuMind keeps and why. Both options
                    below start switched off: your conversations are not saved to your account and nothing is
                    used for training until you turn them on. The documents you upload are stored so the app
                    can answer from them, and you can delete those at any time.
                </p>

                <form method="POST" action="{{ route('settings.privacy.consent') }}" class="mt-5 space-y-3">
                    @csrf

                    <div class="dm-consent-option">
                        <input
                            type="checkbox"
                            name="store_chat_history"
                            value="1"
                            id="consent-history"
                            autofocus
                            class="dm-toggle mt-0.5"
                        >
                        <label for="consent-history" class="min-w-0 cursor-pointer">
                            <span class="block text-sm font-semibold text-slate-900 dark:text-white">
                                Save my chat history
                            </span>
                            <span class="mt-0.5 block text-xs leading-relaxed text-slate-500 dark:text-slate-500">
                                Required to chat. Keeps your questions and answers in your account so you can
                                reopen a conversation later. Turn it off and chats are not retained.
                            </span>
                        </label>
                    </div>

                    <div class="dm-consent-option">
                        <input
                            type="checkbox"
                            name="allow_model_training"
                            value="1"
                            id="consent-training"
                            class="dm-toggle mt-0.5"
                        >
                        <label for="consent-training" class="min-w-0 cursor-pointer">
                            <span class="block text-sm font-semibold text-slate-900 dark:text-white">
                                Use my conversations to improve the model
                            </span>
                            <span class="mt-0.5 block text-xs leading-relaxed text-slate-500 dark:text-slate-500">
                                Anonymised text only. Your email, name, credentials and other personal details
                                are removed first, and your account details never leave your account. Off by
                                default and revocable at any time.
                            </span>
                        </label>
                    </div>

                    <p class="pt-1 text-xs leading-relaxed text-slate-500 dark:text-slate-500">
                        We store the documents you upload and the answers we generate so the app works. We do
                        not sell your data, and you can export or permanently delete everything from your
                        privacy settings whenever you like.
                    </p>

                    <div class="flex flex-col gap-2 pt-2 sm:flex-row sm:items-center sm:justify-between">
                        <a
                            href="{{ route('privacy.policy') }}"
                            class="text-xs font-medium text-slate-500 underline decoration-slate-300 underline-offset-2 transition hover:text-slate-800 dark:text-slate-500 dark:decoration-slate-600 dark:hover:text-slate-200"
                        >
                            Read the privacy policy
                        </a>

                        <button
                            type="submit"
                            class="btn btn-primary inline-flex items-center justify-center gap-2"
                        >
                            Save my choices
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif
