
FIXED////////
3. MEDIUM — Sanctum tokens never expire (Fixed)
config/sanctum.php:53 — 'expiration' => null. There is no login/token issuance route in routes/api.php at all, so tokens are minted outside this codebase and live forever. A token leaked via a proxy log, a shared .env, a browser extension, or a stolen laptop grants permanent API access with no revocation path short of manually deleting rows in personal_access_tokens.

Fix: set 'expiration' => env('SANCTUM_EXPIRATION', 60*24) and implement a token-revocation endpoint.

4.MEDIUM — No rate limiting anywhere on the API
bootstrap/app.php:16-22 never calls $middleware->throttleApi(), and Middleware::getMiddlewareGroups() only injects throttle: when apiLimiter is set (vendor/.../Configuration/Middleware.php:497). AppServiceProvider is empty, so no RateLimiter::for('api', ...) exists. Every route is unthrottled.

Impact: the upload endpoint (UploadMigrationFileRequest allows 10 MB per file) is an unauthenticated-cost DoS vector once a token is held; and when a login endpoint is added in future, it will ship with no brute-force protection by default.

Fix: $middleware->throttleApi(60, 1); plus a tighter limiter (e.g. 10/min) on /migration-sessions/*/upload.

5. MEDIUM — Cross-tenant FK references accepted at the validation layer (Fixed)
The exists: rules are global, not tenant-scoped:

app/Http/Requests/StoreInvoiceRequest.php:26-28,41-46 — exists:branches,id, exists:customers,id, exists:orders,id, exists:catalog_items,id
app/Http/Requests/UpdateExpenseRequest.php:19-35 — exists:branches,id, exists:categories,id
app/Http/Requests/RunMigrationImportRequest.php:19 — exists:branches,id
app/Http/Requests/StoreEmployeeRequest.php:19-29 — exists:users,id, exists:branches,id
The write paths mostly re-validate tenant (good), but MigrationInvoiceCreator::create (app/Services/MigrationInvoiceCreator.php:31) and MigrationExpenseCreator write branch_id straight from the request without a tenant check. A Tenant-A user can therefore create invoices/expenses pointing at a Tenant-B branch ID, and — more usefully to an attacker — use the differing error responses as a branch/category ID oracle to map a competitor's physical store layout.

Fix: replace with Rule::exists('branches','id')->where('tenant_id', $auth->user()->tenant_id), ideally centralised in a helper.

6. LOW-MEDIUM — setPermissionsTeamId is request-global mutable state(Fixed)
app/Http/Middleware/CheckPermission.php:19 and SetTenantPermissionContext.php:19 write to Spatie's static team resolver. The alias tenant.permission (bootstrap/app.php:18) is never applied to any route — it exists but is dead. This means the team context is only ever set by CheckPermission, and any route that performs a permission-sensitive operation without that middleware operates against whatever team ID was last set in the process. Under php artisan octane:start or any long-lived worker, a stale team ID from a previous tenant's request can persist and grant cross-tenant permission grants. Also, RoleController (app/Http/Controllers/RoleController.php:15) and CatalogItemController::update (:147) hand-roll the same call instead of using middleware.

Fix: apply tenant.permission to the whole auth:sanctum group in routes/api.php and delete the inline calls; call setPermissionsTeamId(null) in a finally block.
//////FIXED/////





7.LOW — Upload handling hygiene
MigrationSessionService::attachFile (app/Services/MigrationSessionService.php:48) calls $file->store('migration-imports') with a random generated name (no path traversal) and stores the file privately (good), but:

The prior file is never deleted when a session is re-uploaded, so old tenant data accumulates in storage/app/private indefinitely.
array_combine($headers, $row) (MigrationCsvAnalyzer.php:37, and every importer) is called on attacker-controlled CSV. A row with more fields than the header — trivially produced by an unquoted comma — makes array_combine emit a warning/ValueError. It is caught, so it is not a crash, but it means a single malformed row fails the entire batch with an opaque error surfaced to the user.
CSV cell contents are stored verbatim. If any admin later exports these to CSV/Excel from the Angular UI, cells beginning with =, +, -, @ become formula injection (CSV injection / DDE). Sanitise on export, not on ingest.

1.The permission reports.view_profit is defined (BizFlowRolePermissionSeeder.php:62) and assigned only alongside reports.view in every seeded role — so today no shipped role is affected. The moment an admin creates a read-only "Auditor" or "Supervisor" role with reports.view, that user silently receives margin percentages and inventory valuation. A permission that gates nothing is worse than no permission, because reviewers assume it is enforced.

Fix: split DashboardService::summary into a public/profit view keyed off hasPermissionTo('reports.view_profit') and omit the sensitive keys otherwise.

2.The permission reports.view_profit is defined (BizFlowRolePermissionSeeder.php:62) and assigned only alongside reports.view in every seeded role — so today no shipped role is affected. The moment an admin creates a read-only "Auditor" or "Supervisor" role with reports.view, that user silently receives margin percentages and inventory valuation. A permission that gates nothing is worse than no permission, because reviewers assume it is enforced.

Fix: split DashboardService::summary into a public/profit view keyed off hasPermissionTo('reports.view_profit') and omit the sensitive keys otherwise.


php artisan tinker
$tenant = \App\Models\PlatformAdmin::create([
    'name' => 'Jonathan Attram',
    'email' => 'jonathan@bizflow.app',
   'password' => 'nhyiraba12'
]);

$tenant->id;

\Spatie\Permission\Models\Role::where('tenant_id', $tenant->id)->pluck('name'); 
> \Spatie\Permission\Models\Permission::count();                                                        
$user = \App\Models\User::create([
    'name' => 'Siobhan CEO',
    'email' => 'maame@bizflow.test',
    'password' => \Illuminate\Support\Facades\Hash::make('password'),
    'tenant_id' => 2,
]);
setPermissionsTeamId(2);

$user->assignRole('Owner');


$user->hasRole('Owner');
$user->getRoleNames();


Get-ChildItem src\app\features -Directory