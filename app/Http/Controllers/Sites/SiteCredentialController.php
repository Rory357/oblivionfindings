<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\CredentialType;
use App\Models\Site;
use App\Models\SiteCredential;
use App\Models\SiteCredentialAuditLog;
use App\Services\Sites\SiteCredentialEncryptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use PragmaRX\Google2FA\Google2FA;

class SiteCredentialController extends Controller
{
    public function __construct(
        private SiteCredentialEncryptionService $encryptionService
    ) {}

    public function index(Request $request, Site $site)
    {
        $this->concealSite($request, $site);

        // The per-site credentials index has been retired in favour of the
        // unified Vendor Directory & Access Vault (sites.vendors.global). The
        // reveal/rotate/reauth/audit/store endpoints below stay live — the new
        // page posts to them — but the list view now lives at /vendors.
        return redirect()->route('sites.vendors.global', [
            'site_id' => $site->id,
            'tab' => 'credentials',
        ], 301);
    }

    public function store(Request $request, Site $site)
    {
        $this->concealSite($request, $site);
        $request->user()->canDo('credentials.manage') || abort(403);

        $validated = $request->validate([
            'label' => 'required|string|max:255',
            'credential_type' => [
                'required',
                'string',
                'max:30',
                Rule::in(CredentialType::pickerOptions()->pluck('key')->all()),
            ],
            'value' => 'required|string',
            'username' => 'nullable|string|max:255',
            'url' => ['nullable', 'string', 'max:2048', $this->credentialHttpUrlRule()],
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
            'totp_secret' => ['nullable', 'string', 'max:128', $this->credentialTotpSecretRule()],
        ]);

        $encrypted = $this->encryptionService->encrypt($validated['value']);
        $totpSecret = $this->normalizeTotpSecret($validated['totp_secret'] ?? null);

        DB::transaction(function () use ($encrypted, $request, $site, $totpSecret, $validated): void {
            $credential = SiteCredential::query()->create([
                'site_id' => $site->id,
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

            $this->recordCredentialAudit($request, $site, $credential, 'create');
        }, attempts: 1);

        return back(303)->with('success', 'Credential added successfully.');
    }

    public function reveal(Request $request, Site $site, SiteCredential $credential)
    {
        $this->concealSite($request, $site);
        $request->user()->canDo('credentials.reveal') || abort(403);
        $this->assertCredentialBelongsToSite($site, $credential);

        return DB::transaction(function () use ($credential, $request, $site) {
            $locked = $this->lockedCredential($site, (int) $credential->id);
            if ($reauthResponse = $this->ensureCredentialReauth($request, $site, $locked)) {
                return $reauthResponse;
            }

            $value = $this->encryptionService->decrypt($locked->encrypted_value);
            $this->recordCredentialAudit($request, $site, $locked, 'reveal');

            return response()->json(['value' => $value]);
        }, attempts: 1);
    }

    public function update(Request $request, Site $site, SiteCredential $credential)
    {
        $this->concealSite($request, $site);
        $request->user()->canDo('credentials.manage') || abort(403);
        $this->assertCredentialBelongsToSite($site, $credential);

        $validated = $request->validate([
            'label' => 'required|string|max:255',
            'credential_type' => [
                'required',
                'string',
                'max:30',
                Rule::in(array_values(array_unique([
                    ...CredentialType::pickerOptions()->pluck('key')->all(),
                    $credential->credential_type,
                ]))),
            ],
            'value' => 'nullable|string',
            'username' => 'nullable|string|max:255',
            'url' => ['nullable', 'string', 'max:2048', $this->credentialHttpUrlRule()],
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
            'totp_secret' => ['nullable', 'string', 'max:128', $this->credentialTotpSecretRule()],
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

        $auditAction = 'edit';
        if (! empty($validated['value'])) {
            $encrypted = $this->encryptionService->encrypt($validated['value']);
            $updateData['encrypted_value'] = $encrypted['value'];
            $updateData['iv'] = null;
            $updateData['last_rotated_at'] = now();
            $updateData['last_rotated_by_user_id'] = $request->user()->id;
            $updateData['password_strength'] = $validated['password_strength'] ?? null;
            $auditAction = 'rotate';
        }

        DB::transaction(function () use ($auditAction, $credential, $request, $site, $updateData): void {
            $locked = $this->lockedCredential($site, (int) $credential->id);
            $locked->update($updateData);
            $this->recordCredentialAudit($request, $site, $locked->refresh(), $auditAction);
        }, attempts: 1);

        return back(303)->with('success', 'Credential updated successfully.');
    }

    public function destroy(Request $request, Site $site, SiteCredential $credential)
    {
        $this->concealSite($request, $site);
        $request->user()->canDo('credentials.manage') || abort(403);
        $this->assertCredentialBelongsToSite($site, $credential);

        DB::transaction(function () use ($credential, $request, $site): void {
            $locked = $this->lockedCredential($site, (int) $credential->id);
            $this->recordCredentialAudit($request, $site, $locked, 'delete');
            $locked->delete();
        }, attempts: 1);

        return back(303)->with('success', 'Credential deleted successfully.');
    }

    /**
     * Mark a credential as rotated *now* without changing the stored secret —
     * the "Mark rotated now" quick action. Records a rotate audit entry so the
     * rotation-health badge resets. Reuses the credentials.manage gate.
     */
    public function rotate(Request $request, Site $site, SiteCredential $credential)
    {
        $this->concealSite($request, $site);
        $request->user()->canDo('credentials.manage') || abort(403);
        $this->assertCredentialBelongsToSite($site, $credential);

        DB::transaction(function () use ($credential, $request, $site): void {
            $locked = $this->lockedCredential($site, (int) $credential->id);
            $locked->update([
                'last_rotated_at' => now(),
                'last_rotated_by_user_id' => $request->user()->id,
            ]);
            $this->recordCredentialAudit($request, $site, $locked->refresh(), 'rotate');
        }, attempts: 1);

        return back(303)->with('success', 'Marked as rotated today.');
    }

    /**
     * Toggle the "require re-auth to reveal" flag — the quick action from the
     * row context menu. Records an edit audit entry.
     */
    public function toggleReauth(Request $request, Site $site, SiteCredential $credential)
    {
        $this->concealSite($request, $site);
        $request->user()->canDo('credentials.manage') || abort(403);
        $this->assertCredentialBelongsToSite($site, $credential);

        $validated = $request->validate([
            'requires_reauth' => 'required|boolean',
        ]);

        DB::transaction(function () use ($credential, $request, $site, $validated): void {
            $locked = $this->lockedCredential($site, (int) $credential->id);
            $locked->update(['requires_reauth' => $validated['requires_reauth']]);
            $this->recordCredentialAudit($request, $site, $locked->refresh(), 'edit');
        }, attempts: 1);

        return back(303)->with(
            'success',
            $validated['requires_reauth'] ? 'Re-auth now required to reveal.' : 'Re-auth requirement removed.',
        );
    }

    public function totpCode(Request $request, Site $site, SiteCredential $credential)
    {
        $this->concealSite($request, $site);
        $request->user()->canDo('credentials.reveal') || abort(403);
        $this->assertCredentialBelongsToSite($site, $credential);

        return DB::transaction(function () use ($credential, $request, $site) {
            $locked = $this->lockedCredential($site, (int) $credential->id);
            if (empty($locked->totp_secret_encrypted)) {
                abort(404, 'No authenticator configured for this credential.');
            }

            if ($reauthResponse = $this->ensureCredentialReauth($request, $site, $locked)) {
                return $reauthResponse;
            }

            $secret = Crypt::decryptString($locked->totp_secret_encrypted);
            $google2fa = new Google2FA;
            $code = $google2fa->getCurrentOtp($secret);
            $window = 30;
            $secondsRemaining = $window - (now()->timestamp % $window);

            $this->recordCredentialAudit($request, $site, $locked, 'totp_code');

            return response()->json([
                'code' => $code,
                'seconds_remaining' => $secondsRemaining,
                'period' => $window,
            ]);
        }, attempts: 1);
    }

    public function removeTotp(Request $request, Site $site, SiteCredential $credential)
    {
        $this->concealSite($request, $site);
        $request->user()->canDo('credentials.manage') || abort(403);
        $this->assertCredentialBelongsToSite($site, $credential);

        DB::transaction(function () use ($credential, $request, $site): void {
            $locked = $this->lockedCredential($site, (int) $credential->id);
            $locked->update([
                'totp_secret_encrypted' => null,
                'totp_issuer' => null,
                'totp_account' => null,
            ]);
            $this->recordCredentialAudit($request, $site, $locked->refresh(), 'totp_remove');
        }, attempts: 1);

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

    private function credentialHttpUrlRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            $scheme = is_string($value) ? parse_url($value, PHP_URL_SCHEME) : null;
            if (
                ! is_string($value)
                || ! filter_var($value, FILTER_VALIDATE_URL)
                || ! in_array(strtolower((string) $scheme), ['http', 'https'], true)
            ) {
                $fail('The :attribute must be a valid http or https URL.');
            }
        };
    }

    private function credentialTotpSecretRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            $normalized = is_string($value)
                ? strtoupper((string) preg_replace('/\s+/', '', $value))
                : '';
            if (strlen($normalized) < 16 || preg_match('/^[A-Z2-7]+$/', $normalized) !== 1) {
                $fail('The :attribute must be a valid Base32 authenticator secret.');
            }
        };
    }

    private function ensureCredentialReauth(Request $request, Site $site, SiteCredential $credential)
    {
        if (! $credential->requires_reauth) {
            return null;
        }

        if ($this->hasFreshCredentialReauth($request, $credential)) {
            return null;
        }

        $request->validate([
            'password' => 'required|string',
        ]);

        if (! Auth::validate(['email' => $request->user()->email, 'password' => $request->input('password')])) {
            $this->recordCredentialAuditSafely($request, $site, $credential, 'reauth_failed');

            return response()->json(['error' => 'Invalid password'], 403);
        }

        $request->session()->put($this->credentialReauthSessionKey($request, $credential), now()->timestamp);
        $this->recordCredentialAuditSafely($request, $site, $credential, 'reauth_passed');

        return null;
    }

    private function hasFreshCredentialReauth(Request $request, SiteCredential $credential): bool
    {
        $unlockedAt = (int) $request->session()->get($this->credentialReauthSessionKey($request, $credential), 0);

        return $unlockedAt >= now()->subMinutes(5)->timestamp;
    }

    private function credentialReauthSessionKey(Request $request, SiteCredential $credential): string
    {
        return "site_credential_reauth.{$request->user()->id}.{$credential->id}";
    }

    private function recordCredentialAuditSafely(Request $request, Site $site, SiteCredential $credential, string $action): void
    {
        try {
            $this->recordCredentialAudit($request, $site, $credential, $action);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function auditLog(Request $request, Site $site, SiteCredential $credential)
    {
        $this->concealSite($request, $site);
        $request->user()->canDo('credentials.reveal') || abort(403);
        $this->assertCredentialBelongsToSite($site, $credential);

        $logs = SiteCredentialAuditLog::query()
            ->where('site_id', $site->id)
            ->where('credential_id', $credential->id)
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
        $this->concealSite($request, $site);
        $request->user()->canDo('credentials.reveal') || abort(403);
        $this->assertCredentialBelongsToSite($site, $credential);

        DB::transaction(function () use ($credential, $request, $site): void {
            $locked = $this->lockedCredential($site, (int) $credential->id);
            $this->recordCredentialAudit($request, $site, $locked, 'copy');
        }, attempts: 1);

        return response()->json(['ok' => true]);
    }

    private function assertCredentialBelongsToSite(Site $site, SiteCredential $credential): void
    {
        if ($credential->site_id !== $site->id) {
            abort(404);
        }
    }

    private function concealSite(Request $request, Site $site): void
    {
        abort_unless($request->user()?->can('view', $site) === true, 404);
    }

    private function lockedCredential(Site $site, int $credentialId): SiteCredential
    {
        return SiteCredential::query()
            ->where('site_id', $site->id)
            ->lockForUpdate()
            ->findOrFail($credentialId);
    }

    private function recordCredentialAudit(
        Request $request,
        Site $site,
        SiteCredential $credential,
        string $action,
    ): SiteCredentialAuditLog {
        return SiteCredentialAuditLog::query()->create([
            'credential_id' => $credential->id,
            'site_id' => $site->id,
            'credential_label' => $credential->label,
            'credential_type' => $credential->credential_type,
            'user_id' => $request->user()->id,
            'action' => $action,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);
    }
}
