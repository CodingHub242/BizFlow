<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Audit Logs | Bizflow Platform</title>

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
            color: #687386;
            font-size: 14px;
        }

        .admin {
            color: #263247;
            font-size: 14px;
            font-weight: 600;
        }

        .content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 34px;
        }

        .page-header {
            margin-bottom: 26px;
        }

        .page-header h2 {
            margin: 0 0 7px;
            font-size: 29px;
        }

        .page-header p {
            margin: 0;
            color: #687386;
        }

        .card {
            background: #ffffff;
            border: 1px solid #e3e8f0;
            border-radius: 14px;
            box-shadow: 0 4px 16px rgba(20, 35, 60, 0.04);
            overflow: hidden;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 760px;
        }

        th {
            padding: 14px 22px;
            background: #f8fafc;
            color: #7a8494;
            font-size: 11px;
            font-weight: 700;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        td {
            padding: 17px 22px;
            border-top: 1px solid #edf0f5;
            color: #354157;
            font-size: 14px;
            vertical-align: top;
        }

        .action {
            display: inline-flex;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        .action-approved {
            background: #e8f7ee;
            color: #176b3a;
        }

        .action-rejected {
            background: #fdecec;
            color: #9b1c1c;
        }

        .action-suspended {
            background: #eeeef8;
            color: #4d4d82;
        }

        .action-default {
            background: #eef1f5;
            color: #52627a;
        }

        .business-name {
            font-weight: 700;
            color: #172033;
        }

        .admin-name {
            font-weight: 600;
        }

        .reason {
            max-width: 320px;
            color: #687386;
            line-height: 1.5;
        }

        .timestamp {
            white-space: nowrap;
            color: #687386;
            font-size: 13px;
        }

        .empty {
            padding: 60px 24px;
            text-align: center;
            color: #687386;
        }

        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            padding: 18px 22px;
            border-top: 1px solid #edf0f5;
            color: #687386;
            font-size: 13px;
        }

        .pagination-links {
            display: flex;
            gap: 6px;
        }

        .pagination-links a,
        .pagination-links span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 34px;
            height: 34px;
            padding: 0 9px;
            border: 1px solid #dce2ea;
            border-radius: 7px;
            color: #52627a;
            text-decoration: none;
            background: #ffffff;
        }

        .pagination-links span[aria-current="page"] {
            background: #172033;
            color: #ffffff;
            border-color: #172033;
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

            .pagination {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        .filter-form {
            display: flex;
            align-items: flex-end;
            gap: 12px;
            margin-bottom: 22px;
        }

        .filter-field {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .filter-field label {
            color: #687386;
            font-size: 12px;
            font-weight: 700;
        }

        .filter-field select {
            min-width: 240px;
            padding: 10px 12px;
            border: 1px solid #ccd4df;
            border-radius: 8px;
            background: #ffffff;
            color: #263247;
            font: inherit;
            outline: none;
        }

        .filter-field select:focus {
            border-color: #52627a;
        }

        .clear-filter {
            padding: 10px 13px;
            color: #52627a;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
        }

        .clear-filter:hover {
            text-decoration: underline;
        }

        @media (max-width: 600px) {
            .filter-form {
                align-items: stretch;
                flex-direction: column;
            }

            .filter-field select {
                width: 100%;
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

            <a href="{{ route('platform.dashboard') }}">
                Dashboard
            </a>

            <a href="{{ route('platform.businesses.pending') }}">
                Pending Businesses
            </a>

            <a
                href="{{ route('platform.audit-logs.index') }}"
                class="active"
            >
                Audit Logs
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
                <h2>Audit Logs</h2>

                <p>
                    Review administrative actions performed across the Bizflow platform.
                </p>
            </header>

            <form
                method="GET"
                action="{{ route('platform.audit-logs.index') }}"
                class="filter-form"
            >
                <div class="filter-field">

                    <label for="action">
                        Filter by action
                    </label>

                    <select
                        id="action"
                        name="action"
                        onchange="this.form.submit()"
                    >
                        <option value="">
                            All actions
                        </option>

                        <option
                            value="business.approved"
                            @selected($action === 'business.approved')
                        >
                            Business approved
                        </option>

                        <option
                            value="business.rejected"
                            @selected($action === 'business.rejected')
                        >
                            Business rejected
                        </option>

                        <option
                            value="business.suspended"
                            @selected($action === 'business.suspended')
                        >
                            Business suspended
                        </option>
                    </select>

                </div>

                @if ($action)
                    <a
                        href="{{ route('platform.audit-logs.index') }}"
                        class="clear-filter"
                    >
                        Clear filter
                    </a>
                @endif
            </form>

            <section class="card">

                @if ($logs->isEmpty())

                    <div class="empty">
                        <strong>No audit events</strong>

                        <p>
                            Platform administrative activity will appear here.
                        </p>
                    </div>

                @else

                    <div class="table-wrapper">

                        <table>

                            <thead>
                                <tr>
                                    <th>Action</th>
                                    <th>Business</th>
                                    <th>Administrator</th>
                                    <th>Reason</th>
                                    <th>Date</th>
                                </tr>
                            </thead>

                            <tbody>

                            @foreach ($logs as $log)

                                @php
                                    $actionClass = match ($log->action) {
                                        'business.approved' => 'action-approved',
                                        'business.rejected' => 'action-rejected',
                                        'business.suspended' => 'action-suspended',
                                        default => 'action-default',
                                    };
                                @endphp

                                <tr>

                                    <td>
                                        <span
                                            class="action {{ $actionClass }}"
                                            title="{{ $log->action }}"
                                        >
                                            {{ str_replace('.', ' › ', $log->action) }}

                                            <span style="display:none;">
                                                {{ $log->action }}
                                            </span>
                                        </span>
                                    </td>

                                    <td>

                                        @if ($log->target)
                                            <span class="business-name">
                                                {{ $log->target->name }}
                                            </span>
                                        @else
                                            {{ $log->target_type }} #{{ $log->target_id }}
                                        @endif

                                    </td>

                                    <td>
                                        <span class="admin-name">
                                            {{ $log->platformAdmin->name }}
                                        </span>
                                    </td>

                                    <td>

                                        @if ($log->reason)
                                            <div class="reason">
                                                {{ $log->reason }}
                                            </div>
                                        @else
                                            <span>—</span>
                                        @endif

                                    </td>

                                    <td>
                                        <span class="timestamp">
                                            {{ $log->created_at?->format('M d, Y H:i') }}
                                        </span>
                                    </td>

                                </tr>

                            @endforeach

                            </tbody>

                        </table>

                    </div>

                    @if ($logs->hasPages())

                        <div class="pagination">

                            <div>
                                Showing
                                {{ $logs->firstItem() }}
                                –
                                {{ $logs->lastItem() }}
                                of
                                {{ $logs->total() }}
                                events
                            </div>

                            <div class="pagination-links">
                                {!! $logs->links()->render() !!}
                            </div>

                        </div>

                    @endif

                @endif

            </section>

        </div>

    </main>

</div>

</body>
</html>