<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;
//use Illuminate\Validation\Rule;

class TenantSettingsController extends Controller
{
    private function authorizeSettings(Request $request,string $permission): void 
    {
        $user = $request->user();

        abort_unless(
            $user && $user->tenant_id,
            403,
            'Does Not Exist'
        );

        setPermissionsTeamId($user->tenant_id);

        abort_unless(
            $user->hasPermissionTo($permission, 'web'),
            403,
            'You do not have permission to perform this action.'
        );
    }

    public function show(Request $request): JsonResponse
    {
        $this->authorizeSettings($request, 'settings.view');

        $tenant = $this->currentTenant($request);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'email' => $tenant->email,
                'phone' => $tenant->phone,
                'business_type' => $tenant->business_type,
                'logo_path' => $tenant->logo_path,
                'logo_url' => $tenant->logo_path
                ? Storage::disk('public')->url($tenant->logo_path)
                : null,
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorizeSettings($request, 'settings.update');

        $tenant = $this->currentTenant($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'business_type' => ['nullable', 'string', 'max:100'],
        ]);

        $tenant->fill($validated);
        $tenant->save();

        return response()->json([
            'success' => true,
            'message' => 'Business profile updated successfully.',
            'data' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'email' => $tenant->email,
                'phone' => $tenant->phone,
                'business_type' => $tenant->business_type,
                'logo_path' => $tenant->logo_path,
                'logo_url' => $tenant->logo_path
                ? Storage::disk('public')->url($tenant->logo_path)
                : null,
            ],
        ]);
    }

    private function currentTenant(Request $request): Tenant
    {
        $user = $request->user();

        abort_unless($user && $user->tenant_id, 403, 'Account Does Not Exist.');

        return Tenant::query()
            ->whereKey($user->tenant_id)
            ->firstOrFail();
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $this->authorizeSettings($request, 'settings.update_logo');

        $tenant = $this->currentTenant($request);

        $request->validate([
            'logo' => [
                'required',
                File::image()
                    ->types(['jpg', 'jpeg', 'png', 'webp'])
                    ->max(2048),
            ],
        ]);

        $file = $request->file('logo');

        $path = $file->store(
            "tenant-logos/{$tenant->id}",
            'public'
        );

        if (!$path) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to store the business logo.',
            ], 500);
        }

        $oldPath = $tenant->logo_path;

        $tenant->logo_path = $path;
        $tenant->save();

        if ($oldPath && $oldPath !== $path) {
            Storage::disk('public')->delete($oldPath);
        }

        return response()->json([
            'success' => true,
            'message' => 'Business logo updated successfully.',
            'data' => [
                'logo_path' => $path,
                'logo_url' => Storage::disk('public')->url($path),
            ],
        ]);
    }


    public function removeLogo(Request $request): JsonResponse
    {
        $this->authorizeSettings($request, 'settings.update_logo');

        $tenant = $this->currentTenant($request);

        $oldPath = $tenant->logo_path;

        if (!$oldPath) {
            return response()->json([
                'success' => true,
                'message' => 'No business logo is currently set.',
                'data' => [
                    'logo_path' => null,
                    'logo_url' => null,
                ],
            ]);
        }

        // Only allow deletion from this tenant's logo directory.
        $expectedPrefix = "tenant-logos/{$tenant->id}/";

        abort_unless(
            str_starts_with($oldPath, $expectedPrefix),
            403,
            'The stored logo path is invalid.'
        );

        // Clear the database first so a file deletion failure
        // cannot leave the profile pointing to a missing file.
        $tenant->logo_path = null;
        $tenant->save();

        Storage::disk('public')->delete($oldPath);

        return response()->json([
            'success' => true,
            'message' => 'Business logo removed successfully.',
            'data' => [
                'logo_path' => null,
                'logo_url' => null,
            ],
        ]);
    }

}
