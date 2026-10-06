<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\TenantStatus;
use Illuminate\View\View;

class PlatformDashboardController extends Controller
{
    public function index(): View
    {
        $counts = [
            'pending' => Tenant::query()
                ->where('status', TenantStatus::PENDING)
                ->count(),

            'approved' => Tenant::query()
                ->where('status', TenantStatus::APPROVED)
                ->count(),

            'rejected' => Tenant::query()
                ->where('status', TenantStatus::REJECTED)
                ->count(),

            'suspended' => Tenant::query()
                ->where('status', TenantStatus::SUSPENDED)
                ->count(),
        ];

        $recentBusinesses = Tenant::query()
            ->latest()
            ->limit(10)
            ->get();

        return view('platform.dashboard', [
            'counts' => $counts,
            'recentBusinesses' => $recentBusinesses,
        ]);
    }
}