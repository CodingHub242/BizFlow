<?php

namespace App\Http\Controllers;

use App\Services\Platform\PlatformAdminAuthService;
use Illuminate\Http\RedirectResponse;
use App\PlatformLoginResult;
use Illuminate\Http\Request;

class PlatformAuthController extends Controller
{
    public function __construct(
        private readonly PlatformAdminAuthService $authService,
    ) {
    }

    public function showLogin()
    {
        return view('platform.auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $result = $this->authService->attempt(
            $credentials['email'],
            $credentials['password']
        );

       if ($result === PlatformLoginResult::LOCKED) {
            return back()
                ->withInput($request->only('email'))
                ->with('login_locked', true)
                ->with(
                    'lockout_remaining_seconds',
                    $this->authService->lockoutRemainingSeconds(
                        $credentials['email']
                    )
                )
                ->withErrors([
                    'email' => 'Sign-in is temporarily unavailable. Please try again later.',
                ]);
        }

        if ($result === PlatformLoginResult::INVALID) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors([
                    'email' => 'The provided credentials are invalid.',
                ]);
        }

        $request->session()->regenerate();

        return redirect()->route('platform.dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->authService->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('platform.login');
    }
}