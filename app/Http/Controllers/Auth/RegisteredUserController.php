<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Show the registration form.
     */
    public function create(): View
    {
        return view('auth.register', [
            'registrationEnabled' => (bool) config('billing.registration_enabled'),
        ]);
    }

    /**
     * Create a new account, grant the welcome credits, and log it in.
     */
    public function store(Request $request): RedirectResponse
    {
        if (! config('billing.registration_enabled')) {
            throw ValidationException::withMessages([
                'email' => 'Registration is currently disabled. Please contact the site administrator.',
            ]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', 'string', 'min:8', 'max:255'],
        ]);

        $user = User::create($validated);

        $user->grantCredits((int) config('billing.welcome_credits'));

        Auth::login($user);

        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
