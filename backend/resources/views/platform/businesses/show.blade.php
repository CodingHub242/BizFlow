@extends('platform.layouts.app')

@section('title', 'View Business')

@section('nav_businesses', 'active')

@section('content')

    <style>
       

        .page {
            max-width: 1100px;
            margin: 0 auto;
            padding: 10px 24px;
        }

        .back-link {
            display: inline-block;
            margin-bottom: 24px;
            color: #52627a;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 24px;
            margin-bottom: 24px;
        }

        .header h1 {
            margin: 0 0 8px;
            font-size: 30px;
        }

        .header p {
            margin: 0;
            color: #687386;
        }

        .status {
            display: inline-flex;
            align-items: center;
            padding: 7px 13px;
            border-radius: 999px;
            background: #fff4d6;
            color: #8a5a00;
            font-size: 13px;
            font-weight: 700;
            text-transform: capitalize;
            white-space: nowrap;
        }

        .card {
            background: #ffffff;
            border: 1px solid #e3e8f0;
            border-radius: 14px;
            padding: 28px;
            margin-bottom: 20px;
            box-shadow: 0 4px 16px rgba(20, 35, 60, 0.05);
        }

        .card h2 {
            margin: 0 0 22px;
            font-size: 18px;
        }

        .details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 18px 24px;
        }

        .detail {
            padding-bottom: 16px;
            border-bottom: 1px solid #edf0f5;
        }

        .detail-label {
            display: block;
            margin-bottom: 6px;
            color: #7a8494;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .detail-value {
            color: #263247;
            font-size: 15px;
            font-weight: 600;
            word-break: break-word;
        }

        .actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .button {
            border: 0;
            border-radius: 9px;
            padding: 11px 18px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
        }

        .button-approve {
            background: #176b3a;
            color: #ffffff;
        }

        .button-approve:hover {
            background: #12552e;
        }

        .button-reject {
            background: #fff0f0;
            color: #a32121;
        }

        .button-reject:hover {
            background: #fde0e0;
        }

        .rejection-form {
            display: none;
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid #edf0f5;
        }

        .rejection-form.visible {
            display: block;
        }

        .rejection-form label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 700;
        }

        .rejection-form textarea {
            width: 100%;
            min-height: 130px;
            padding: 12px;
            border: 1px solid #ccd4df;
            border-radius: 9px;
            font: inherit;
            resize: vertical;
            outline: none;
        }

        .rejection-form textarea:focus {
            border-color: #52627a;
        }

        .rejection-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 12px;
        }

        .button-secondary {
            background: #eef1f5;
            color: #354157;
        }

        .button-danger {
            background: #a32121;
            color: #ffffff;
        }

        .button-danger:hover {
            background: #861b1b;
        }

        @media (max-width: 700px) {
            .page {
                padding: 24px 16px;
            }

            .header {
                flex-direction: column;
            }

            .details {
                grid-template-columns: 1fr;
            }

            .actions {
                flex-direction: column;
            }

            .button {
                width: 100%;
            }
        }
    </style>


<div class="page">

    <a
        href="{{ route('platform.businesses.index') }}"
        class="back-link"
    >
        ← Back to Businesses
    </a>

    <header class="header">
        <div>
            <h1>{{ $business->name }}</h1>
            <p>Business registration review</p>
        </div>

        <span class="status">
            {{ ucfirst($business->status->value) }}
        </span>
    </header>

    <section class="card">

        <h2>Business Information</h2>

        <div class="details">

            <div class="detail">
                <span class="detail-label">Business Name</span>
                <span class="detail-value">
                    {{ $business->name }}
                </span>
            </div>

            <div class="detail">
                <span class="detail-label">Business Type</span>
                <span class="detail-value">
                    {{ $business->business_type ?: 'Not specified' }}
                </span>
            </div>

            <div class="detail">
                <span class="detail-label">Email</span>
                <span class="detail-value">
                    {{ $business->email ?: 'Not provided' }}
                </span>
            </div>

            <div class="detail">
                <span class="detail-label">Phone</span>
                <span class="detail-value">
                    {{ $business->phone ?: 'Not provided' }}
                </span>
            </div>

            <div class="detail">
                <span class="detail-label">Business Slug</span>
                <span class="detail-value">
                    {{ $business->slug }}
                </span>
            </div>

        </div>

    </section>

    <section class="card">

        <h2>Registration</h2>

        <div class="details">

            <div class="detail">
                <span class="detail-label">Registered</span>
                <span class="detail-value">
                    {{ $business->created_at?->format('M d, Y H:i') }}
                </span>
            </div>

            <div class="detail">
                <span class="detail-label">Current Status</span>
                <span class="detail-value">
                    {{ ucfirst($business->status->value) }}
                </span>
            </div>

        </div>

    </section>

    @if ($business->status === \App\TenantStatus::PENDING)

        <section class="card">

            <h2>Review Decision</h2>

            <div class="actions">

                <form
                    method="POST"
                    action="{{ route('platform.businesses.approve', $business) }}"
                >
                    @csrf

                    <button
                        type="submit"
                        class="button button-approve"
                    >
                        Approve Business
                    </button>
                </form>

                <button
                    type="button"
                    class="button button-reject"
                    onclick="document.getElementById('rejection-form').classList.add('visible')"
                >
                    Reject Business
                </button>

            </div>

            <div
                id="rejection-form"
                class="rejection-form"
            >

                <form
                    method="POST"
                    action="{{ route('platform.businesses.reject', $business) }}"
                >
                    @csrf

                    <label for="reason">
                        Reason for rejection
                    </label>

                    <textarea
                        id="reason"
                        name="reason"
                        required
                        maxlength="2000"
                        placeholder="Explain why this business registration is being rejected..."
                    ></textarea>

                    <div class="rejection-actions">

                        <button
                            type="button"
                            class="button button-secondary"
                            onclick="document.getElementById('rejection-form').classList.remove('visible')"
                        >
                            Cancel
                        </button>

                        <button
                            type="submit"
                            class="button button-danger"
                        >
                            Reject Business
                        </button>

                    </div>

                </form>

            </div>

        </section>

    @endif

</div>

@endsection