@extends('platform.layouts.app')

@section('title', 'Businesses')

@section('nav_businesses', 'active')

@section('content')

    <style>
       
        .layout {
            min-height: 100vh;
            display: flex;
        }

       

        .brand {
            padding: 0 12px 28px;
            font-size: 20px;
            font-weight: 800;
            letter-spacing: -0.4px;
        }

        .brand span {
            color: #91a3bd;
            font-size: 11px;
            display: block;
            margin-top: 3px;
            font-weight: 600;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .nav {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .nav a {
            display: flex;
            align-items: center;
            padding: 11px 12px;
            border-radius: 8px;
            color: #b9c4d4;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
        }

        .nav a:hover,
        .nav a.active {
            background: #26344c;
            color: #fff;
        }

        /* Main */
        .main {
            flex: 1;
            min-width: 0;
            padding: 15px;
        }

        .page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 26px;
        }

        .page-header h1 {
            margin: 0;
            font-size: 27px;
            letter-spacing: -0.6px;
        }

        .page-header p {
            margin: 7px 0 0;
            color: #788398;
            font-size: 14px;
        }

        .business-count {
            padding: 8px 12px;
            border-radius: 8px;
            background: #fff;
            border: 1px solid #e0e5ec;
            color: #5d697c;
            font-size: 13px;
            font-weight: 700;
        }

        /* Filters */
        .filter-card {
            background: #fff;
            border: 1px solid #e1e6ed;
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 20px;
        }

        .filter-form {
            display: flex;
            align-items: flex-end;
            gap: 14px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .field.search-field {
            flex: 1;
        }

        .field label {
            color: #687386;
            font-size: 12px;
            font-weight: 700;
        }

        .field input,
        .field select {
            min-height: 42px;
            border: 1px solid #ccd4df;
            border-radius: 8px;
            background: #fff;
            color: #263247;
            padding: 9px 12px;
            font: inherit;
            font-size: 14px;
            outline: none;
        }

        .field input:focus,
        .field select:focus {
            border-color: #52627a;
        }

        .search-button {
            min-height: 42px;
            padding: 0 18px;
            border: 0;
            border-radius: 8px;
            background: #26344c;
            color: #fff;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }

        .search-button:hover {
            background: #1d293d;
        }

        .clear-filter {
            min-height: 42px;
            display: inline-flex;
            align-items: center;
            padding: 0 12px;
            color: #687386;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
        }

        .clear-filter:hover {
            text-decoration: underline;
        }

        /* Table */
        .table-card {
            background: #fff;
            border: 1px solid #e1e6ed;
            border-radius: 12px;
            overflow: hidden;
        }

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 850px;
        }

        th {
            padding: 13px 18px;
            text-align: left;
            background: #f8f9fb;
            border-bottom: 1px solid #e1e6ed;
            color: #788398;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .4px;
        }

        td {
            padding: 16px 18px;
            border-bottom: 1px solid #edf0f4;
            font-size: 13px;
            vertical-align: middle;
        }

        tbody tr:last-child td {
            border-bottom: 0;
        }

        tbody tr:hover {
            background: #fafbfd;
        }

        .business-name {
            color: #263247;
            font-weight: 750;
        }

        .business-slug {
            color: #8a94a5;
            font-size: 11px;
            margin-top: 3px;
        }

        .muted {
            color: #778297;
        }

        .status {
            display: inline-flex;
            align-items: center;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .35px;
        }

        .status.pending {
            background: #fff5dc;
            color: #936900;
        }

        .status.approved {
            background: #e8f6ee;
            color: #28764b;
        }

        .status.rejected {
            background: #fdebec;
            color: #a33a42;
        }

        .status.suspended {
            background: #eceff4;
            color: #5f6979;
        }

        .view-link {
            color: #52627a;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
        }

        .view-link:hover {
            text-decoration: underline;
        }

        /* Empty state */
        .empty-state {
            padding: 58px 24px;
            text-align: center;
        }

        .empty-state h3 {
            margin: 0;
            font-size: 17px;
        }

        .empty-state p {
            margin: 7px 0 0;
            color: #7b8698;
            font-size: 13px;
        }

        /* Mobile */
        @media (max-width: 850px) {
            .sidebar {
                width: 190px;
            }

            .main {
                padding: 24px;
            }

            .filter-form {
                flex-wrap: wrap;
            }

            .field.search-field {
                flex-basis: 100%;
            }
        }

        @media (max-width: 620px) {
            .layout {
                display: block;
            }

            .sidebar {
                width: 100%;
                padding: 16px;
            }

            .brand {
                padding-bottom: 16px;
            }

            .nav {
                flex-direction: row;
                overflow-x: auto;
            }

            .nav a {
                white-space: nowrap;
            }

            .main {
                padding: 18px;
            }

            .page-header {
                flex-direction: column;
            }

            .filter-form {
                flex-direction: column;
                align-items: stretch;
            }

            .field.search-field {
                flex-basis: auto;
            }

            .search-button,
            .clear-filter {
                width: 100%;
                justify-content: center;
            }
        }

        .pagination {
            margin-top: 18px;
            padding: 0 4px;
        }

        .pagination nav {
            display: flex;
            justify-content: center;
        }

        .pagination svg {
            width: 18px;
            height: 18px;
        }

        .pagination a,
        .pagination span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            min-height: 36px;
            margin: 0 3px;
            padding: 0 10px;
            border: 1px solid #dce2e9;
            border-radius: 7px;
            background: #fff;
            color: #52627a;
            font-size: 13px;
            text-decoration: none;
        }

        .pagination a:hover {
            background: #f4f6f9;
        }

        .pagination span[aria-current="page"] {
            background: #26344c;
            color: #fff;
            border-color: #26344c;
        }
    </style>




<div class="layout">

    <main class="main">

        <header class="page-header">
            <div>
                <h1>Businesses</h1>
                <p>Manage and review businesses registered on Bizflow.</p>
            </div>

            <div class="business-count">
                {{ $businesses->count() }}
                {{ $businesses->count() === 1 ? 'business' : 'businesses' }}
            </div>
        </header>

        <section class="filter-card">

            <form
                method="GET"
                action="{{ route('platform.businesses.index') }}"
                class="filter-form"
            >

                <div class="field search-field">
                    <label for="search">Search businesses</label>

                    <input
                        type="search"
                        id="search"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Name, slug, email or phone"
                    >
                </div>

                <div class="field">
                    <label for="status">Filter by status</label>

                    <select id="status" name="status">
                        <option value="">All statuses</option>

                        <option value="pending" @selected($status === 'pending')}>
                            Pending
                        </option>

                        <option value="approved" @selected($status === 'approved')}>
                            Approved
                        </option>

                        <option value="rejected" @selected($status === 'rejected')}>
                            Rejected
                        </option>

                        <option value="suspended" @selected($status === 'suspended')}>
                            Suspended
                        </option>
                    </select>
                </div>

                <button type="submit" class="search-button">
                    Search
                </button>

                @if ($search || $status)
                    <a
                        href="{{ route('platform.businesses.index') }}"
                        class="clear-filter"
                    >
                        Clear
                    </a>
                @endif

            </form>

        </section>

        <section class="table-card">
            @if ($businesses->hasPages())
                <div class="pagination">
                    {{ $businesses->links() }}
                </div>
            @endif
            @if ($businesses->isEmpty())

                <div class="empty-state">
                    <h3>No businesses found</h3>

                    <p>
                        Try changing your search or status filter.
                    </p>
                </div>

            @else

                <div class="table-wrapper">

                    <table>

                        <thead>
                        <tr>
                            <th>Business</th>
                            <th>Contact</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Registered</th>
                            <th></th>
                        </tr>
                        </thead>

                        <tbody>

                        @foreach ($businesses as $business)

                            <tr>

                                <td>
                                    <div class="business-name">
                                        {{ $business->name }}
                                    </div>

                                    <div class="business-slug">
                                        {{ $business->slug }}
                                    </div>
                                </td>

                                <td>
                                    <div>{{ $business->email ?: '—' }}</div>

                                    <div class="muted">
                                        {{ $business->phone ?: '—' }}
                                    </div>
                                </td>

                                <td class="muted">
                                    {{ $business->business_type ?: '—' }}
                                </td>

                                <td>
                                    <span class="status {{ $business->status->value }}">
                                        {{ $business->status->value }}
                                    </span>
                                </td>

                                <td class="muted">
                                    {{ $business->created_at?->format('M d, Y') }}
                                </td>

                                <td>
                                    <a
                                        href="{{ route('platform.businesses.show', $business) }}"
                                        class="view-link"
                                    >
                                        View
                                    </a>
                                </td>

                            </tr>

                        @endforeach

                        </tbody>

                    </table>

                </div>

            @endif

        </section>

        @if ($businesses->hasPages())
            <div class="pagination">
                {{ $businesses->links() }}
            </div>
        @endif

    </main>

</div>

@endsection