<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Platform Dashboard | Bizflow</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: #f4f7fb;
            color: #172033;
        }

        .layout {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */

        .sidebar {
            width: 245px;
            background: #111827;
            color: #ffffff;
            padding: 26px 18px;
            flex-shrink: 0;
        }

        .brand {
            padding: 0 12px 30px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 24px;
        }

        .brand h1 {
            margin: 0;
            font-size: 23px;
            letter-spacing: -0.5px;
        }

        .brand span {
            display: block;
            margin-top: 5px;
            color: #9ca3af;
            font-size: 12px;
        }

        .nav-title {
            padding: 0 12px;
            margin-bottom: 8px;
            color: #6b7280;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .nav a {
            display: block;
            padding: 11px 12px;
            margin-bottom: 4px;
            border-radius: 8px;
            color: #d1d5db;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
        }

        .nav a:hover,
        .nav a.active {
            background: rgba(255,255,255,0.08);
            color: #ffffff;
        }

        .sidebar-footer {
            margin-top: 40px;
            padding: 16px 12px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }

        .sidebar-footer form {
            margin: 0;
        }

        .logout-button {
            width: 100%;
            border: 0;
            background: transparent;
            color: #d1d5db;
            padding: 10px 0;
            text-align: left;
            font-size: 14px;
            cursor: pointer;
        }

        .logout-button:hover {
            color: #ffffff;
        }

        /* Main */

        .main {
            flex: 1;
            min-width: 0;
        }

        .topbar {
            height: 72px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 34px;
            background: #ffffff;
            border-bottom: 1px solid #e3e8f0;
        }

        .topbar-title {
            font-size: 14px;
            color: #687386;
        }

        .admin {
            font-size: 14px;
            font-weight: 600;
            color: #263247;
        }

        .content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 34px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            margin-bottom: 30px;
        }

        .page-header h2 {
            margin: 0 0 7px;
            font-size: 29px;
        }

        .page-header p {
            margin: 0;
            color: #687386;
        }

        .primary-button {
            display: inline-block;
            padding: 11px 17px;
            border-radius: 9px;
            background: #176b3a;
            color: #ffffff;
            text-decoration: none;
            font-size: 14px;
            font-weight: 700;
        }

        .primary-button:hover {
            background: #12552e;
        }

        /* Metrics */

        .metrics {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 28px;
        }

        .metric {
            background: #ffffff;
            border: 1px solid #e3e8f0;
            border-radius: 14px;
            padding: 22px;
            box-shadow: 0 4px 16px rgba(20, 35, 60, 0.04);
        }

        .metric-label {
            display: block;
            margin-bottom: 13px;
            color: #687386;
            font-size: 13px;
            font-weight: 600;
        }

        .metric-value {
            display: block;
            font-size: 32px;
            font-weight: 700;
            color: #172033;
        }

        .metric-description {
            display: block;
            margin-top: 6px;
            color: #8a94a5;
            font-size: 12px;
        }

        /* Recent businesses */

        .card {
            background: #ffffff;
            border: 1px solid #e3e8f0;
            border-radius: 14px;
            box-shadow: 0 4px 16px rgba(20, 35, 60, 0.04);
            overflow: hidden;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            padding: 22px 24px;
            border-bottom: 1px solid #edf0f5;
        }

        .card-header h3 {
            margin: 0;
            font-size: 17px;
        }

        .card-header a {
            color: #52627a;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
        }

        .card-header a:hover {
            text-decoration: underline;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            padding: 13px 24px;
            background: #f8fafc;
            color: #7a8494;
            font-size: 11px;
            font-weight: 700;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        td {
            padding: 17px 24px;
            border-top: 1px solid #edf0f5;
            color: #354157;
            font-size: 14px;
        }

        .business-name {
            font-weight: 700;
            color: #172033;
        }

        .status {
            display: inline-flex;
            padding: 5px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            text-transform: capitalize;
        }

        .status-pending {
            background: #fff4d6;
            color: #8a5a00;
        }

        .status-approved {
            background: #e8f7ee;
            color: #176b3a;
        }

        .status-rejected {
            background: #fdecec;
            color: #9b1c1c;
        }

        .status-suspended {
            background: #eeeef8;
            color: #4d4d82;
        }

        .empty {
            padding: 50px 24px;
            text-align: center;
            color: #687386;
        }

        /* Responsive */

        @media (max-width: 1000px) {
            .sidebar {
                width: 210px;
            }

            .metrics {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 720px) {
            .layout {
                display: block;
            }

            .sidebar {
                width: 100%;
                min-height: auto;
            }

            .nav {
                display: flex;
                gap: 6px;
                overflow-x: auto;
            }

            .nav-title {
                display: none;
            }

            .nav a {
                white-space: nowrap;
            }

            .sidebar-footer {
                margin-top: 15px;
            }

            .topbar {
                padding: 0 18px;
            }

            .content {
                padding: 24px 16px;
            }

            .page-header {
                flex-direction: column;
            }

            .primary-button {
                width: 100%;
                text-align: center;
            }
        }

        @media (max-width: 500px) {
            .metrics {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<div class="layout">

    <aside class="sidebar">

        <div class="brand">
            <h1>Bizflow</h1>
            <span>Platform Control Plane</span>
        </div>

        <div class="nav-title">
            Platform
        </div>

        <nav class="nav">

            <a
                href="{{ route('platform.dashboard') }}"
                class="active"
            >
                Dashboard
            </a>

            <a href="{{ route('platform.businesses.pending') }}">
                Pending Businesses
            </a>

        </nav>

        <div class="sidebar-footer">

            <form
                method="POST"
                action="{{ route('platform.logout') }}"
            >
                @csrf

                <button
                    type="submit"
                    class="logout-button"
                >
                    Sign out
                </button>
            </form>

        </div>

    </aside>

    <main class="main">

        <header class="topbar">

            <span class="topbar-title">
                Platform Administration
            </span>

            <span class="admin">
                {{ auth('platform')->user()->name }}
            </span>

        </header>

        <div class="content">

            <header class="page-header">

                <div>
                    <h2>Platform Dashboard</h2>
                    <p>
                        Overview of businesses registered on Bizflow.
                    </p>
                </div>

                @if ($counts['pending'] > 0)
                    <a
                        href="{{ route('platform.businesses.pending') }}"
                        class="primary-button"
                    >
                        Review Pending Businesses
                    </a>
                @endif

            </header>

            <section class="metrics">

                <div class="metric">
                    <span class="metric-label">
                        Pending Businesses
                    </span>

                    <span class="metric-value">
                        {{ $counts['pending'] }}
                    </span>

                    <span class="metric-description">
                        Awaiting review
                    </span>
                </div>

                <div class="metric">
                    <span class="metric-label">
                        Approved Businesses
                    </span>

                    <span class="metric-value">
                        {{ $counts['approved'] }}
                    </span>

                    <span class="metric-description">
                        Active businesses
                    </span>
                </div>

                <div class="metric">
                    <span class="metric-label">
                        Rejected Businesses
                    </span>

                    <span class="metric-value">
                        {{ $counts['rejected'] }}
                    </span>

                    <span class="metric-description">
                        Registration decisions
                    </span>
                </div>

                <div class="metric">
                    <span class="metric-label">
                        Suspended Businesses
                    </span>

                    <span class="metric-value">
                        {{ $counts['suspended'] }}
                    </span>

                    <span class="metric-description">
                        Currently suspended
                    </span>
                </div>

            </section>

            <section class="card">

                <div class="card-header">

                    <h3>Recent Registrations</h3>

                    <a href="{{ route('platform.businesses.pending') }}">
                        View pending
                    </a>

                </div>

                @if ($recentBusinesses->isEmpty())

                    <div class="empty">
                        No businesses have been registered yet.
                    </div>

                @else

                    <div class="table-wrapper">

                        <table>

                            <thead>
                                <tr>
                                    <th>Business</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Registered</th>
                                </tr>
                            </thead>

                            <tbody>

                            @foreach ($recentBusinesses as $business)

                                <tr>

                                    <td>
                                        <span class="business-name">
                                            {{ $business->name }}
                                        </span>
                                    </td>

                                    <td>
                                        {{ $business->business_type ?: 'Not specified' }}
                                    </td>

                                    <td>
                                        <span
                                            class="status status-{{ $business->status->value }}"
                                        >
                                            {{ ucfirst($business->status->value) }}
                                        </span>
                                    </td>

                                    <td>
                                        {{ $business->created_at?->format('M d, Y') }}
                                    </td>

                                </tr>

                            @endforeach

                            </tbody>

                        </table>

                    </div>

                @endif

            </section>

        </div>

    </main>

</div>

</body>
</html>