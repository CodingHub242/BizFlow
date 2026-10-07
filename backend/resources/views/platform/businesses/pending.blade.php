@extends('platform.layouts.app')

@section('title', 'Pending Businesses')

@section('nav_pending', 'active')

@section('content')

    <style>
       
        .page {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            margin-bottom: 30px;
        }

        .header h1 {
            margin: 0 0 8px;
            font-size: 30px;
        }

        .header p {
            margin: 0;
            color: #687386;
        }

        .alert {
            padding: 14px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: #e8f7ee;
            color: #176b3a;
        }

        .alert-error {
            background: #fdecec;
            color: #9b1c1c;
        }

        .business-list {
            display: grid;
            gap: 18px;
        }

        .business-card {
            background: #ffffff;
            border: 1px solid #e3e8f0;
            border-radius: 14px;
            padding: 24px;
            box-shadow: 0 4px 16px rgba(20, 35, 60, 0.05);
        }

        .business-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            margin-bottom: 20px;
        }

        .business-header h2 {
            margin: 0 0 6px;
            font-size: 21px;
        }

        .business-header p {
            margin: 0;
            color: #687386;
        }

        .status {
            display: inline-flex;
            align-items: center;
            padding: 6px 11px;
            border-radius: 999px;
            background: #fff4d6;
            color: #8a5a00;
            font-size: 13px;
            font-weight: 600;
            white-space: nowrap;
        }

        .details {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 22px;
        }

        .detail {
            padding: 14px;
            background: #f8fafc;
            border-radius: 10px;
        }

        .detail-label {
            display: block;
            margin-bottom: 6px;
            color: #7a8494;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .detail-value {
            font-size: 14px;
            font-weight: 600;
            color: #263247;
        }

        .actions {
            display: flex;
            gap: 10px;
            padding-top: 18px;
            border-top: 1px solid #edf0f5;
        }

        .button {
            border: 0;
            border-radius: 8px;
            padding: 10px 16px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }

        .button-approve {
            background: #176b3a;
            color: white;
        }

        .button-reject {
            background: #fff0f0;
            color: #a32121;
        }

        .empty {
            background: #ffffff;
            border: 1px dashed #ccd4df;
            border-radius: 14px;
            padding: 50px 24px;
            text-align: center;
            color: #687386;
        }

        @media (max-width: 800px) {
            .details {
                grid-template-columns: repeat(2, 1fr);
            }

            .business-header {
                flex-direction: column;
            }
        }

        @media (max-width: 520px) {
            .page {
                padding: 24px 16px;
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

    <header class="header">
        <div>
            <h1>Pending Businesses</h1>
            <p>Review newly registered businesses awaiting platform approval.</p>
        </div>
    </header>

    @if (session('success'))
        <div class="alert alert-success">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->has('business'))
        <div class="alert alert-error">
            {{ $errors->first('business') }}
        </div>
    @endif

    @if ($businesses->isEmpty())

        <div class="empty">
            <strong>No pending businesses</strong>
            <p>There are currently no businesses waiting for approval.</p>
        </div>

    @else

        <div class="business-list">

            @foreach ($businesses as $business)

                <article class="business-card">

                    <div class="business-header">
                        <div>
                            <h2>{{ $business->name }}</h2>
                            <p>{{ $business->email }}</p>
                        </div>

                        <span class="status">
                            {{ $business->status->value }}
                        </span>
                    </div>

                    <div class="details">

                        <div class="detail">
                            <span class="detail-label">Phone</span>
                            <span class="detail-value">
                                {{ $business->phone ?: 'Not provided' }}
                            </span>
                        </div>

                        <div class="detail">
                            <span class="detail-label">Business Type</span>
                            <span class="detail-value">
                                {{ $business->business_type ?: 'Not specified' }}
                            </span>
                        </div>

                        <div class="detail">
                            <span class="detail-label">Slug</span>
                            <span class="detail-value">
                                {{ $business->slug }}
                            </span>
                        </div>

                        <div class="detail">
                            <span class="detail-label">Registered</span>
                            <span class="detail-value">
                                {{ $business->created_at?->format('M d, Y') }}
                            </span>
                        </div>

                    </div>

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
                                Approve
                            </button>
                        </form>

                        <form
                            method="POST"
                            action="{{ route('platform.businesses.reject', $business) }}"
                        >
                            @csrf

                            <input
                                type="hidden"
                                name="reason"
                                value="Business registration requires further review."
                            >

                            <button
                                type="submit"
                                class="button button-reject"
                            >
                                Reject
                            </button>
                        </form>

                    </div>

                </article>

            @endforeach

        </div>

    @endif

</div>
@endsection