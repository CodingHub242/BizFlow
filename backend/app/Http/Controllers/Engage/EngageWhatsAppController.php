<?php

namespace App\Http\Controllers\Engage;

use App\Http\Controllers\Controller;
use App\Models\EngageWhatsAppAccount;
use App\Models\EngageWhatsAppNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EngageWhatsAppController extends Controller
{
    public function accounts(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $accounts = EngageWhatsAppAccount::query()
            ->where('tenant_id', $tenantId)
            ->with(['numbers' => function ($query) use ($tenantId) {
                $query->where('tenant_id', $tenantId);
            }])
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $accounts,
        ]);
    }

    public function showAccount(Request $request, int $account)
    {
        $account = EngageWhatsAppAccount::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with('numbers')
            ->findOrFail($account);

        return response()->json([
            'success' => true,
            'data' => $account,
        ]);
    }

    public function numbers(Request $request)
    {
        $numbers = EngageWhatsAppNumber::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with('account:id,name,waba_id')
            ->latest()
            ->paginate(30);

        return response()->json([
            'success' => true,
            'data' => $numbers,
        ]);
    }

    public function setDefaultNumber(Request $request, int $number)
    {
        $tenantId = $request->user()->tenant_id;

        DB::transaction(function () use ($tenantId, $number) {
            $selectedNumber = EngageWhatsAppNumber::query()
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->whereHas('account', function ($query) use ($tenantId) {
                    $query->where('tenant_id', $tenantId)
                        ->where('status', 'active');
                })
                ->lockForUpdate()
                ->findOrFail($number);

            // Only one default number per tenant.
            EngageWhatsAppNumber::query()
                ->where('tenant_id', $tenantId)
                ->update(['is_default' => false]);

            $selectedNumber->update(['is_default' => true]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Default WhatsApp number updated.',
        ]);
    }

    public function updateAccountStatus(Request $request, int $account)
    {
        $validated = $request->validate([
            'status' => ['required', 'in:active,inactive'],
        ]);

        $whatsappAccount = EngageWhatsAppAccount::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($account);

        $whatsappAccount->update([
            'status' => $validated['status'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'WhatsApp account status updated.',
        ]);
    }

    public function updateNumberStatus(Request $request, int $number)
    {
        $validated = $request->validate([
            'status' => ['required', 'in:active,inactive'],
        ]);

        $whatsappNumber = EngageWhatsAppNumber::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($number);

        $whatsappNumber->update([
            'status' => $validated['status'],
            'is_default' => $validated['status'] === 'active'
                ? $whatsappNumber->is_default
                : false,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'WhatsApp number status updated.',
        ]);
    }
}