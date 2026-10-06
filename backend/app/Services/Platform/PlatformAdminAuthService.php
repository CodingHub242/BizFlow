<?php

namespace App\Services\Platform;

use App\Models\PlatformAdmin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use App\PlatformLoginResult;

class PlatformAdminAuthService
{
    private const MAX_FAILED_ATTEMPTS = 3;

    private const LOCKOUT_MINUTES = 15;

    private const ATTEMPT_WINDOW_MINUTES = 15;

    public function attempt(string $email,string $password): PlatformLoginResult 
    {
        $email = strtolower(trim($email));

        $key = $this->attemptKey($email);

        if ($this->isLocked($key)) {
            return PlatformLoginResult::LOCKED;
        }

        $admin = PlatformAdmin::whereRaw(
            'LOWER(email) = ?',
            [$email]
        )->first();

        //return same generic error which does not indicate whether any exists
        if (
            !$admin ||
            !$admin->is_active ||
            !password_verify($password, $admin->password)
        ) {
            $this->recordFailure($key);

            if ($this->isLocked($key)) {
                return PlatformLoginResult::LOCKED;
            }

            return PlatformLoginResult::INVALID;
        }

        Cache::forget($key);

        Auth::guard('platform')->login($admin);

        return PlatformLoginResult::SUCCESS;
    }

    public function logout(): void
    {
        Auth::guard('platform')->logout();
    }

    public function admin(): ?PlatformAdmin
    {
        $admin = Auth::guard('platform')->user();

        return $admin instanceof PlatformAdmin ? $admin : null;
    }

    private function attemptKey(string $email): string
    {
        return 'platform-login:' . hash('sha256', $email);
    }

    private function isLocked(string $key): bool
    {
        $state = Cache::get($key);

        return is_array($state)
            && isset($state['locked_until'])
            && $state['locked_until'] > now()->timestamp;
    }

    private function recordFailure(string $key): void
    {
        $state = Cache::get($key, [
            'attempts' => 0,
        ]);

        $state['attempts'] = ($state['attempts'] ?? 0) + 1;

        if ($state['attempts'] >= self::MAX_FAILED_ATTEMPTS) {
            $state['locked_until'] = now()
                ->addMinutes(self::LOCKOUT_MINUTES)
                ->timestamp;
        }

        Cache::put(
            $key,
            $state,
            now()->addMinutes(self::ATTEMPT_WINDOW_MINUTES)
        );
    }

    public function lockoutRemainingSeconds(string $email): int
    {
        $key = $this->attemptKey(
            strtolower(trim($email))
        );

        $state = Cache::get($key);

        if (
            !is_array($state) ||
            !isset($state['locked_until'])
        ) {
            return 0;
        }

        return max(
            0,
            $state['locked_until'] - now()->timestamp
        );
    }
}   