<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <meta
        name="csrf-token"
        content="{{ csrf_token() }}"
    >

    <title>Platform Administration | Bizflow</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family:
                Inter,
                ui-sans-serif,
                system-ui,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;
            background: #f4f6f8;
            color: #17202a;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .login-wrapper {
            width: 100%;
            max-width: 440px;
        }

        .brand {
            text-align: center;
            margin-bottom: 24px;
        }

        .brand-mark {
            width: 52px;
            height: 52px;
            margin: 0 auto 14px;
            border-radius: 14px;
            background: #17202a;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            font-weight: 800;
        }

        .brand h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 750;
            letter-spacing: -0.4px;
        }

        .brand p {
            margin: 7px 0 0;
            color: #68727d;
            font-size: 14px;
        }

        .card {
            background: #ffffff;
            border: 1px solid #e3e7eb;
            border-radius: 16px;
            padding: 32px;
            box-shadow:
                0 12px 30px rgba(23, 32, 42, 0.08);
        }

        .card-header {
            margin-bottom: 24px;
        }

        .card-header h2 {
            margin: 0 0 7px;
            font-size: 20px;
        }

        .card-header p {
            margin: 0;
            color: #68727d;
            font-size: 14px;
            line-height: 1.5;
        }

        .alert {
            margin-bottom: 20px;
            padding: 12px 14px;
            border-radius: 10px;
            background: #fff4f4;
            border: 1px solid #f0caca;
            color: #a32929;
            font-size: 13px;
            line-height: 1.45;
        }

        .form-group {
            margin-bottom: 18px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-size: 13px;
            font-weight: 650;
        }

        input {
            width: 100%;
            height: 46px;
            padding: 0 13px;
            border: 1px solid #ccd3da;
            border-radius: 9px;
            background: #ffffff;
            color: #17202a;
            font-size: 14px;
            outline: none;
            transition:
                border-color 0.15s ease,
                box-shadow 0.15s ease;
        }

        input:focus {
            border-color: #17202a;
            box-shadow: 0 0 0 3px rgba(23, 32, 42, 0.08);
        }

        .submit-button {
            width: 100%;
            height: 46px;
            border: 0;
            border-radius: 9px;
            background: #17202a;
            color: #ffffff;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition:
                background 0.15s ease,
                transform 0.05s ease;
        }

        .submit-button:hover {
            background: #273440;
        }

        .submit-button:active {
            transform: translateY(1px);
        }

        .security-note {
            margin-top: 20px;
            padding-top: 18px;
            border-top: 1px solid #edf0f2;
            color: #7a848e;
            font-size: 12px;
            line-height: 1.5;
            text-align: center;
        }

        @media (max-width: 480px) {
            body {
                padding: 16px;
            }

            .card {
                padding: 24px;
            }
        }

        .lockout-box {
        margin-bottom: 20px;
        padding: 14px;
        border-radius: 10px;
        background: #fff7e6;
        border: 1px solid #f0d39a;
        color: #7a5410;
        text-align: center;
    }

    .lockout-message {
        margin: 0 0 6px;
        font-size: 13px;
        font-weight: 650;
    }

    .lockout-countdown {
        margin: 0;
        font-size: 13px;
    }

    .locked-input {
        background: #f1f3f5;
        cursor: not-allowed;
    }

    .submit-button:disabled {
        background: #aeb5bb;
        cursor: not-allowed;
    }
    </style>
</head>

<body>

<div class="login-wrapper">

    <div class="brand">
        <!-- <div class="brand-mark">
            B
        </div> -->

        <h1>Bizflow</h1>

        <p>Platform Administration</p>
    </div>

    <div class="card">

        <div class="card-header">
            <h2>Sign in</h2>

            <p>
                Access the internal Bizflow platform control panel.
            </p>
        </div>

        @if ($errors->any())
            <div
                class="alert"
                role="alert"
            >
                {{ $errors->first() }}
            </div>
        @endif

        @php
            $isLocked = session('login_locked', false);
            $lockoutSeconds = (int) session('lockout_remaining_seconds', 0);
        @endphp

        @if ($isLocked && $lockoutSeconds > 0)
            <div
                class="lockout-box"
                id="lockout-box"
                data-remaining="{{ $lockoutSeconds }}"
                role="status"
                aria-live="polite"
            >
                <p class="lockout-message">
                    Sign-in is temporarily unavailable.
                </p>

                <p class="lockout-countdown">
                    Try again in
                    <strong id="lockout-countdown">
                        --:--
                    </strong>
                </p>
            </div>
        @endif

        <form
            method="POST"
            action="{{ route('platform.login.submit') }}"
        >
            @csrf

            <div class="form-group">
                <label for="email">
                    Email address
                </label>

                <input
                    id="email"
                    type="email"
                    name="email"
                    value="{{ old('email') }}"
                    autocomplete="username"
                    autofocus
                    required
                    @disabled($isLocked && $lockoutSeconds > 0)
                    @class(['locked-input' => $isLocked && $lockoutSeconds > 0])
                >
            </div>

            <div class="form-group">
                <label for="password">
                    Password
                </label>

                <input
                    id="password"
                    type="password"
                    name="password"
                    autocomplete="current-password"
                    required
                    @disabled($isLocked && $lockoutSeconds > 0)
                    @class(['locked-input' => $isLocked && $lockoutSeconds > 0])
                >
            </div>

           <button
                type="submit"
                class="submit-button"
                id="login-button"
                @disabled($isLocked && $lockoutSeconds > 0)
            >
                Sign in to Xavier Bizflow Control Panel
            </button>
        </form>

        <div class="security-note">
            This is a restricted Bizflow platform administration area.
        </div>

    </div>

</div>

<script>
    (() => {
        const lockoutBox = document.getElementById('lockout-box');

        if (!lockoutBox) {
            return;
        }

        const countdown = document.getElementById('lockout-countdown');
        const loginButton = document.getElementById('login-button');
        const emailInput = document.getElementById('email');
        const passwordInput = document.getElementById('password');

        let remaining = Number(
            lockoutBox.dataset.remaining || 0
        );

        const formatTime = (seconds) => {
            const minutes = Math.floor(seconds / 60);
            const secondsPart = seconds % 60;

            return `${String(minutes).padStart(2, '0')}:${String(secondsPart).padStart(2, '0')}`;
        };

        const update = () => {
            if (remaining <= 0) {
                countdown.textContent = '00:00';

                lockoutBox.innerHTML = `
                    <p class="lockout-message">
                        You can try signing in again.
                    </p>
                `;

                loginButton.disabled = false;
                emailInput.disabled = false;
                passwordInput.disabled = false;

                emailInput.classList.remove('locked-input');
                passwordInput.classList.remove('locked-input');

                return;
            }

            countdown.textContent = formatTime(remaining);

            remaining -= 1;

            window.setTimeout(update, 1000);
        };

        update();
    })();
</script>

</body>
</html>