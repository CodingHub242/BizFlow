<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Platform\BusinessApprovalService;
use App\TenantStatus;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PlatformBusinessApprovalController extends Controller
{
    public function __construct(
        private readonly BusinessApprovalService $approvalService,
    ) {
    }

    public function pending(): View
    {
        $businesses = Tenant::query()
            ->where('status', TenantStatus::PENDING)
            ->latest()
            ->get();

        return view('platform.businesses.pending', [
            'businesses' => $businesses,
        ]);
    }

    public function approve(Tenant $tenant): RedirectResponse
    {
        $admin = auth('platform')->user();

        try {
            $this->approvalService->approve($tenant, $admin);
        } catch (\DomainException $exception) {
            return redirect()
                ->route('platform.businesses.pending')
                ->withErrors([
                    'business' => $exception->getMessage(),
                ]);
        }

        return redirect()
            ->route('platform.businesses.pending')
            ->with('success', 'Business approved successfully.');
    }

    public function reject(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $admin = auth('platform')->user();

        try {
            $this->approvalService->reject(
                $tenant,
                $admin,
                $validated['reason']
            );
        } catch (\DomainException $exception) {
            return redirect()
                ->route('platform.businesses.pending')
                ->withErrors([
                    'business' => $exception->getMessage(),
                ]);
        }

        return redirect()
            ->route('platform.businesses.pending')
            ->with('success', 'Business rejected successfully.');
    }

    public function suspend(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $admin = auth('platform')->user();

        try {
            $this->approvalService->suspend(
                $tenant,
                $admin,
                $validated['reason']
            );
        } catch (\DomainException $exception) {
            return redirect()
                ->route('platform.businesses.pending')
                ->withErrors([
                    'business' => $exception->getMessage(),
                ]);
        }

        return redirect()
            ->route('platform.businesses.pending')
            ->with('success', 'Business suspended successfully.');
    }

    public function show(Tenant $tenant): View
    {
        return view('platform.businesses.show', [
            'business' => $tenant,
        ]);
    }
}