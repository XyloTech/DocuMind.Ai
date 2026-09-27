import { getApp, getApps, initializeApp } from 'firebase/app';
import { getAuth, GoogleAuthProvider, signInWithPopup, signOut } from 'firebase/auth';

function authFor(element) {
    const config = JSON.parse(element.dataset.firebaseConfig || '{}');
    const app = getApps().find((candidate) => candidate.name === '[DEFAULT]') || initializeApp(config);

    return getAuth(app);
}

function setStatus(status, message, isError = false) {
    status.textContent = message;
    status.classList.toggle('text-rose-600', isError);
    status.classList.toggle('dark:text-rose-400', isError);
    status.classList.toggle('text-slate-500', !isError);
    status.classList.toggle('dark:text-slate-400', !isError);

    // Stamped on the container so the button can pick up the error styling
    // (see [data-auth-state='error'] in app.css).
    const container = status.closest('[data-google-login]');

    if (container) container.dataset.authState = isError ? 'error' : 'info';
}

async function startGoogleSignIn(button) {
    const container = button.closest('[data-google-login]');
    const status = container.querySelector('[data-google-login-status]');
    const spinner = button.querySelector('[data-google-login-spinner]');
    const label = button.querySelector('[data-google-login-label]');
    const originalLabel = label.textContent;

    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    spinner.hidden = false;
    label.textContent = 'Connecting to Google…';
    setStatus(status, 'Waiting for Google verification.');

    try {
        const auth = authFor(container);
        const result = await signInWithPopup(auth, new GoogleAuthProvider());
        const idToken = await result.user.getIdToken();
        const response = await fetch(container.dataset.authUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({ id_token: idToken }),
        });
        const payload = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(payload.message || 'Google sign-in could not be completed. Try again.');
        }

        window.location.assign(payload.redirect || '/dashboard');
    } catch (error) {
        const message = error.code === 'auth/popup-closed-by-user'
            ? 'Sign-in was cancelled.'
            : error.code === 'auth/popup-blocked'
                ? 'Allow pop-ups for this site, then try again.'
                : error.code === 'auth/unauthorized-domain'
                    ? 'This domain is not authorized in Firebase Authentication.'
                    : error.message || 'Google sign-in could not be completed. Try again.';

        setStatus(status, message, true);
        button.disabled = false;
        button.removeAttribute('aria-busy');
        spinner.hidden = true;
        label.textContent = originalLabel;
    }
}

export function bindFirebaseAuth() {
    document.querySelectorAll('[data-google-login-button]').forEach((button) => {
        button.addEventListener('click', () => startGoogleSignIn(button));
    });

    document.querySelectorAll('[data-firebase-logout]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = form.querySelector('button[type="submit"]');

            if (button) button.disabled = true;

            try {
                await signOut(authFor(form));
            } catch {
                // The Laravel session is authoritative and must still be cleared.
            }

            HTMLFormElement.prototype.submit.call(form);
        });
    });
}