<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        @yield('title', 'Platform Administration') | Bizflow
    </title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system,
                BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #f4f6f9;
            color: #263247;
        }

        .platform-layout {
            min-height: 100vh;
            display: flex;
        }

        .platform-sidebar {
            width: 262px;
            background: #101827;
            color: #fff;
            padding: 28px 18px;
            flex-shrink: 0;
        }

        .platform-brand {
            padding: 0 14px 28px;
            border-bottom: 1px solid #293346;
            margin-bottom: 24px;
        }

        .platform-brand strong {
            display: block;
            font-size: 21px;
            letter-spacing: -0.4px;
        }

        .platform-brand span {
            display: block;
            margin-top: 5px;
            color: #9aa6b8;
            font-size: 12px;
        }

        .platform-nav-label {
            padding: 0 14px;
            margin-bottom: 9px;
            color: #7f8a9d;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .7px;
            text-transform: uppercase;
        }

        .platform-nav {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .platform-nav a {
            display: flex;
            align-items: center;
            min-height: 42px;
            padding: 0 14px;
            border-radius: 8px;
            color: #c0c8d5;
            text-decoration: none;
            font-size: 14px;
            font-weight: 650;
        }

        .platform-nav a:hover,
        .platform-nav a.active {
            background: #252f41;
            color: #fff;
        }

        .platform-sidebar-footer {
            margin-top: 28px;
            padding-top: 24px;
            border-top: 1px solid #293346;
        }

        .platform-sidebar-footer form {
            margin: 0;
        }

        .platform-sidebar-footer button {
            width: 100%;
            padding: 11px 14px;
            border: 0;
            background: transparent;
            color: #c0c8d5;
            text-align: left;
            font: inherit;
            font-size: 14px;
            cursor: pointer;
            border-radius: 8px;
        }

        .platform-sidebar-footer button:hover {
            background: #252f41;
            color: #fff;
        }

        .platform-main {
            flex: 1;
            min-width: 0;
        }

        .platform-header {
            min-height: 76px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 34px;
            background: #fff;
            border-bottom: 1px solid #e1e6ed;
        }

        .platform-header-label {
            color: #66748a;
            font-size: 14px;
            font-weight: 600;
        }

        .platform-admin-name {
            color: #263247;
            font-size: 14px;
            font-weight: 750;
        }

        .platform-content {
            padding: 34px;
        }

        @media (max-width: 760px) {
            .platform-layout {
                display: block;
            }

            .platform-sidebar {
                width: 100%;
                padding: 18px;
            }

            .platform-brand {
                padding-bottom: 18px;
                margin-bottom: 18px;
            }

            .platform-nav {
                flex-direction: row;
                overflow-x: auto;
            }

            .platform-nav a {
                white-space: nowrap;
            }

            .platform-header {
                padding: 0 20px;
            }

            .platform-content {
                padding: 22px 18px;
            }
        }
    </style>
</head>

<body>

<div class="platform-layout">

    <aside class="platform-sidebar">

        <div class="platform-brand">
            <strong>Bizflow</strong>
            <span>Platform Control Plane</span>
        </div>

        <div class="platform-nav-label">
            Platform
        </div>

        <nav class="platform-nav">

            <a
                href="{{ route('platform.dashboard') }}"
                class="@yield('nav_dashboard')"
            >
                Dashboard
            </a>

            <a
                href="{{ route('platform.businesses.index') }}"
                class="@yield('nav_businesses')"
            >
                Businesses
            </a>

            <a
                href="{{ route('platform.businesses.pending') }}"
                class="@yield('nav_pending')"
            >
                Pending Businesses
            </a>

            <a
                href="{{ route('platform.audit-logs.index') }}"
                class="@yield('nav_audit_logs')"
            >
                Audit Logs
            </a>

        </nav>

        <div class="platform-sidebar-footer">

            <form
                method="POST"
                action="{{ route('platform.logout') }}"
            >
                @csrf

                <button type="submit">
                    Sign out
                </button>
            </form>

        </div>

    </aside>

    <div class="platform-main">

        <header class="platform-header">

            <div class="platform-header-label">
                Platform Administration
            </div>

            <div class="platform-admin-name">
                {{ auth('platform')->user()->name }}
            </div>

        </header>

        <main class="platform-content">
            @yield('content')
        </main>

    </div>

</div>

</body>
</html>