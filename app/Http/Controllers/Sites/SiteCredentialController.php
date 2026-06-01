<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\CredentialType;
use App\Models\Site;
use App\Models\SiteCredential;
use App\Models\SiteCredentialAuditLog;
use App\Models\SiteVendor;
use App\Services\Sites\SiteCredentialEncryptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use PragmaRX\Google2FA\Google2FA;

class SiteCredentialController extends Controller
{
    public function __construct(
        private SiteCredentialEncryptionService $encryptionService
    ) {}

    public function index(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        $credentials = SiteCredential::where('site_id', $site->id)
            ->with('vendor:id,company_name,service_type')
            ->orderBy('label')
            ->get()
            ->map(fn($c) => [
                'id' => $c->id,
                'label' => $c->label,
                'username' => $c->username,
                'url' => $c->url,
                'credential_type' => $c->credential_type,
                'vendor_id' => $c->vendor_id,
                'vendor_name' => $c->vendor?->company_name,
                'notes' => $c->notes,
                'last_rotated_at' => $c->last_rotated_at?->toDateTimeString(),
                'requires_reauth' => (bool) $c->requires_reauth,
                'is_shareable' => (bool) $c->is_shareable,
                'password_strength' => $c->password_strength,
                'has_totp' => $c->hasTotp(),
                'created_at' => $c->created_at->toDateTimeString(),
                // Never send encrypted_value in list view
                'value_preview' => '********',
            ]);

        // Single audit entry per page load (not per credential)
        if ($credentials->isNotEmpty()) {
            SiteCredentialAuditLog::create([
                'credential_id' => $credentials->first()['id'],
                'tenant_id' => $site->tenant_id,
                'user_id' => $request->user()->id,
                'action' => 'view_list',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);
        }

        $vendors = SiteVendor::where('site_id', $site->id)
            ->orderBy('company_name')
            ->get(['id', 'site_id', 'company_name', 'service_type'])
            ->map(fn (SiteVendor $vendor) => [
                'id' => $vendor->id,
                'site_id' => $vendor->site_id,
                'company_name' => $vendor->company_name,
                'service_type' => $vendor->service_type,
            ]);

        return inertia('sites/credentials/index', [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'type' => $site->type,
            ],
            'credentials' => $credentials,
            'vendors' => $vendors,
            'credentialTypeOptions' => CredentialType::pickerOptionsForTenant($site->tenant_id),
            'canReveal' => $request->user()->canDo('credentials.reveal'),
            'canManage' => $request->user()->canDo('credentials.manage'),
        ]);
    }

    public function store(Request $request, Site $site)
    {
        $this->authorize('view', $site);
        $request->user()->canDo('credentials.manage') || abort(403);

        $validated = $request->validate([
            'label' => 'required|string|max:255',
            'credential_type' => 'required|string|max:30',
            'value' => 'required|string',
            'username' => 'nullable|string|max:255',
            'url' => 'nullable|string|max:2048',
            'vendor_id' => [
                'nullable',
                Rule::exists('site_vendors', 'id')->where(fn ($query) => $query->where('site_id', $site->id)),
            ],
            'notes' => 'nullable|string',
            'requires_reauth' => 'boolean',
            'is_shareable' => 'boolean',
            'password_strength' => 'nullable|integer|min:0|max:4',
            // Operator pastes an existing TOTP secret (Base32) from the
            // external service. Oblivion becomes the authenticator app
            // for that secret; we never generate one ourselves.
            'totp_secret' => 'nullable|string|max:512',
        ]);

        $encrypted = $this->encryptionService->encrypt($validated['value']);
        $totpSecret = $this->normalizeTotpSecret($validated['totp_secret'] ?? null);

        $credential = SiteCredential::create([
            'site_id' => $site->id,
            'tenant_id' => $site->tenant_id,
            'vendor_id' => $validated['vendor_id'] ?? null,
            'label' => $validated['label'],
            'username' => $validated['username'] ?? null,
            'url' => $validated['url'] ?? null,
            'credential_type' => $validated['credential_type'],
            'encrypted_value' => $encrypted['value'],
            'iv' => null,
            'notes' => $validated['notes'] ?? null,
            'requires_reauth' => $validated['requires_reauth'] ?? false,
            'is_shareable' => $validated['is_shareable'] ?? false,
            'password_strength' => $validated['password_strength'] ?? null,
            'totp_secret_encrypted' => $totpSecret
                ? Crypt::encryptString($totpSecret)
                : null,
            'totp_issuer' => $totpSecret ? $site->name : null,
            'totp_account' => $totpSecret
                ? ($validated['username'] ?? $validated['label'])
                : null,
            'last_rotated_at' => now(),
            'last_rotated_by_user_id' => $request->user()->id,
        ]);

        SiteCredentialAuditLog::create([
            'credential_id' => $credential->id,
            'tenant_id' => $site->tenant_id,
            'user_id' => $request->user()->id,
            'action' => 'create',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return back(303)->with('success', 'Credential added successfully.');
    }

    public function reveal(Request $request, Site $site, SiteCredential $credential)
    {
        $this->authorize('view', $site);
        $request->user()->canDo('credentials.reveal') || abort(403);
        $this->assertCredentialBelongsToSite($site, $credential);

        // Check if re-authentication is required
        if ($credential->requires_reauth) {
            $request->validate([
                'password' => 'required|string',
            ]);

            // Verify user's password
            if (!Auth::validate(['email' => $request->user()->email, 'password' => $request->input('password')])) {
                // Record the denied step-up attempt so failed unlocks leave a
                // forensic trail (and surface in the Reveal & audit log's
                // "Denied" view). Best-effort: never let a logging issue turn a
                // clean 403 into a 500.
                try {
                    SiteCredentialAuditLog::create([
                        'credential_id' => $credential->id,
                        'tenant_id' => $site->tenant_id,
                        'user_id' => $request->user()->id,
                        'action' => 'reauth_failed',
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'created_at' => now(),
                    ]);
                } catch (\Throwable $e) {
                    report($e);
                }

                return response()->json(['error' => 'Invalid password'], 403);
            }
        }

        // Audit log
        SiteCredentialAuditLog::create([
            'credential_id' => $credential->id,
            'tenant_id' => $site->tenant_id,
            'user_id' => $request->user()->id,
            'action' => 'reveal',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        $value = $this->encryptionService->decrypt($credential->encrypted_value);

        return response()->json([
            'value' => $value,
        ]);
    }

    public function update(Request $request, Site $site, SiteCredential $credential)
    {
        $this->authorize('view', $site);
        $request->user()->canDo('credentials.manage') || abort(403);
        $this->assertCredentialBelongsToSite($site, $credential);

        $validated = $request->validate([
            'label' => 'required|string|max:255',
            'credential_type' => 'required|string|max:30',
            'value' => 'nullable|string',
            'username' => 'nullable|string|max:255',
            'url' => 'nullable|string|max:2048',
            'vendor_id' => [
                'nullable',
                Rule::exists('site_vendors', 'id')->where(fn ($query) => $query->where('site_id', $site->id)),
            ],
            'notes' => 'nullable|string',
            'requires_reauth' => 'boolean',
            'is_shareable' => 'boolean',
            'password_strength' => 'nullable|integer|min:0|max:4',
            // Blank = keep existing TOTP secret; non-blank = replace.
            // Removal is via the dedicated DELETE /totp endpoint.
            'totp_secret' => 'nullable|string|max:512',
        ]);

        $updateData = [
            'label' => $validated['label'],
            'credential_type' => $validated['credential_type'],
            'username' => $validated['username'] ?? null,
            'url' => $validated['url'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'requires_reauth' => $validated['requires_reauth'] ?? false,
            'is_shareable' => $validated['is_shareable'] ?? false,
        ];

        // Only touch the vendor link when the form actually sent the key, so a
        // dialog that omits vendor_id never silently unlinks an existing vendor.
        if ($request->has('vendor_id')) {
            $updateData['vendor_id'] = $validated['vendor_id'] ?? null;
        }

        $newTotpSecret = $this->normalizeTotpSecret($validated['totp_secret'] ?? null);
        if ($newTotpSecret !== null) {
            $updateData['totp_secret_encrypted'] = Crypt::encryptString($newTotpSecret);
            $updateData['totp_issuer'] = $credential->totp_issuer ?? $site->name;
            $updateData['totp_account'] = $credential->totp_account
                ?? ($validated['username'] ?? $validated['label']);
        }

        // If value provided, re-encrypt
        if (!empty($validated['value'])) {
            $encrypted = $this->encryptionService->encrypt($validated['value']);
            $updateData['encrypted_value'] = $encrypted['value'];
            $updateData['iv'] = null;
            $updateData['last_rotated_at'] = now();
            $updateData['last_rotated_by_user_id'] = $request->user()->id;
            $updateData['password_strength'] = $validated['password_strength'] ?? null;

            // Audit rotation
            SiteCredentialAuditLog::create([
                'credential_id' => $credential->id,
                'tenant_id' => $site->tenant_id,
                'user_id' => $request->user()->id,
                'action' => 'rotate',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);
        } else {
            // Audit edit
            SiteCredentialAuditLog::create([
                'credential_id' => $credential->id,
                'tenant_id' => $site->tenant_id,
                'user_id' => $request->user()->id,
                'action' => 'edit',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);
        }

        $credential->update($updateData);

        return back(303)->with('success', 'Credential updated successfully.');
    }

    public function destroy(Request $request, Site $site, SiteCredential $credential)
    {
        $this->authorize('view', $site);
        $request->user()->canDo('credentials.manage') || abort(403);
        $this->assertCredentialBelongsToSite($site, $credential);

        // Audit deletion
        SiteCredentialAuditLog::create([
            'credential_id' => $credential->id,
            'tenant_id' => $site->tenant_id,
            'user_id' => $request->user()->id,
            'action' => 'delete',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        $credential->delete();

        return back(303)->with('success', 'Credential deleted successfully.');
    }

    /**
     * Mark a credential as rotated *now* without changing the stored secret —
     * the "Mark rotated now" quick action. Records a rotate audit entry so the
     * rotation-health badge resets. Reuses the credentials.manage gate.
     */
    public function rotate(Request $request, Site $site, SiteCredential $credential)
    {
        $this->authorize('view', $site);
        $request->user()->canDo('credentials.manage') || abort(403);
        $this->assertCredentialBelongsToSite($site, $credential);

        $credential->update([
            'last_rotated_at' => now(),
            'last_rotated_by_user_id' => $request->user()->id,
        ]);

        SiteCredentialAuditLog::create([
            'credential_id' => $credential->id,
            'tenant_id' => $site->tenant_id,
            'user_id' => $request->user()->id,
            'action' => 'rotate',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return back(303)->with('success', 'Marked as rotated today.');
    }

    /**
     * Toggle the "require re-auth to reveal" flag — the quick action from the
     * row context menu. Records an edit audit entry.
     */
    public function toggleReauth(Request $request, Site $site, SiteCredential $credential)
    {
        $this->authorize('view', $site);
        $request->user()->canDo('credentials.manage') || abort(403);
        $this->assertCredentialBelongsToSite($site, $credential);

        $validated = $request->validate([
            'requires_reauth' => 'required|boolean',
        ]);

        $credential->update(['requires_reauth' => $validated['requires_reauth']]);

        SiteCredentialAuditLog::create([
            'credential_id' => $credential->id,
            'tenant_id' => $site->tenant_id,
            'user_id' => $request->user()->id,
            'action' => 'edit',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return back(303)->with(
            'success',
            $validated['requires_reauth'] ? 'Re-auth now required to reveal.' : 'Re-auth requirement removed.',
        );
    }

    public function totpCode(Request $request, Site $site, SiteCredential $credential)
    {
        $this->authorize('view', $site);
        $request->user()->canDo('credentials.reveal') || abort(403);
        $this->assertCredentialBelongsToSite($site, $credential);

        if (empty($credential->totp_secret_encrypted)) {
            abort(404, 'No authenticator configured for this credential.');
        }

        $secret = Crypt::decryptString($credential->totp_secret_encrypted);
        $google2fa = new Google2FA();
        $code = $google2fa->getCurrentOtp($secret);

        $window = 30;
        $secondsRemaining = $window - (now()->timestamp % $window);

        SiteCredentialAuditLog::create([
            'credential_id' => $credential->id,
            'tenant_id' => $site->tenant_id,
            'user_id' => $request->user()->id,
            'action' => 'totp_code',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return response()->json([
            'code' => $code,
            'seconds_remaining' => $secondsRemaining,
            'period' => $window,
        ]);
    }

    public function removeTotp(Request $request, Site $site, SiteCredential $credential)
    {
        $this->authorize('view', $site);
        $request->user()->canDo('credentials.manage') || abort(403);
        $this->assertCredentialBelongsToSite($site, $credential);

        $credential->update([
            'totp_secret_encrypted' => null,
            'totp_issuer' => null,
            'totp_account' => null,
        ]);

        SiteCredentialAuditLog::create([
            'credential_id' => $credential->id,
            'tenant_id' => $site->tenant_id,
            'user_id' => $request->user()->id,
            'action' => 'totp_remove',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return back(303)->with('success', 'Authenticator removed.');
    }

    /**
     * Normalize a pasted Base32 TOTP secret: strip whitespace, uppercase.
     * Returns null when the input is empty so callers can skip update.
     */
    private function normalizeTotpSecret(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $clean = strtoupper(preg_replace('/\s+/', '', $raw));
        return $clean === '' ? null : $clean;
    }

    public function auditLog(Request $request, Site $site, SiteCredential $credential)
    {
        $this->authorize('view', $site);
        $this->assertCredentialBelongsToSite($site, $credential);

        $logs = SiteCredentialAuditLog::where('credential_id', $credential->id)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->paginate(50);

        return inertia('sites/credentials/audit', [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
            ],
            'credential' => [
                'id' => $credential->id,
                'label' => $credential->label,
            ],
            'logs' => $logs,
        ]);
    }

    public function copy(Request $request, Site $site, SiteCredential $credential)
    {
        $this->authorize('view', $site);
        $request->user()->canDo('credentials.reveal') || abort(403);
        $this->assertCredentialBelongsToSite($site, $credential);

        SiteCredentialAuditLog::create([
            'credential_id' => $credential->id,
            'tenant_id' => $site->tenant_id,
            'user_id' => $request->user()->id,
            'action' => 'copy',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    private function assertCredentialBelongsToSite(Site $site, SiteCredential $credential): void
    {
        if ($credential->site_id !== $site->id) {
            abort(404);
        }
    }
}
