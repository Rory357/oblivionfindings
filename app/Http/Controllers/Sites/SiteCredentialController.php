<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\CredentialType;
use App\Models\Site;
use App\Models\SiteCredential;
use App\Models\SiteCredentialAuditLog;
use App\Models\SiteCredentialVersion;
use App\Models\User;
use App\Services\Sites\SiteCredentialAccess;
use App\Services\Sites\SiteCredentialEncryptionService;
use App\Services\Sites\SiteCredentialHistory;
use App\Services\Sites\SiteCredentialStepUp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

class SiteCredentialController extends Controller
{
    public function __construct(private SiteCredentialEncryptionService $encryptionService) {}

    public function index(Request $request, Site $site)
    {
        abort_unless(app(SiteCredentialAccess::class)->query($request->user())->where('site_id', $site->id)->exists()
            || ($request->user()->can('view', $site) && $request->user()->canDo('credentials.view')), 404);
        return redirect()->route('sites.vendors.global', ['site_id' => $site->id, 'tab' => 'credentials'], 302);
    }

    public function store(Request $request, Site $site)
    {
        abort_unless($request->user()->can('view', $site) && $request->user()->canDo('credentials.manage'), 404);
        abort_unless(app(SiteCredentialAccess::class)->ready(), 503, 'The reviewed vault database update is required.');
        $data = $request->validate($this->rules($site));
        $credential = DB::transaction(function () use ($request, $site, $data) {
            $site = Site::whereKey($site->id)->lockForUpdate()->firstOrFail();
            $actor = $this->currentActor($request);
            abort_unless($actor->can('view', $site) && $actor->canDo('credentials.manage'), 404);
            ksort($data);
            $digest = hash_hmac('sha256', json_encode([$actor->id, $site->id, $data], JSON_THROW_ON_ERROR), config('app.key'));
            if (! empty($data['creation_key']) && $existing = SiteCredential::where('creation_key', $data['creation_key'])->first()) {
                abort_unless(hash_equals($existing->creation_digest ?? '', $digest), 409);
                app(SiteCredentialAccess::class)->authorize($actor, $existing, 'manage', true);
                return $existing;
            }
            $encrypted = $this->encryptionService->encrypt($data['value']);
            $secret = $this->normaliseTotp($data['totp_secret'] ?? null);
            $credential = SiteCredential::create([
                ...collect($data)->except(['value', 'totp_secret', 'lock_version'])->all(),
                'site_id' => $site->id, 'encrypted_value' => $encrypted['value'], 'iv' => null,
                'totp_secret_encrypted' => $secret ? Crypt::encryptString($secret) : null,
                'totp_issuer' => $secret ? $site->name : null, 'totp_account' => $secret ? ($data['username'] ?? $data['label']) : null,
                'requires_reauth' => true, 'last_rotated_at' => null, 'last_rotated_by_user_id' => null,
                'lock_version' => 1,
                'creation_digest' => ! empty($data['creation_key']) ? $digest : null,
            ]);
            app(SiteCredentialHistory::class)->retain($credential, $actor, 'created');
            $this->audit($request, $credential, 'create');
            return $credential;
        });
        return response()->json(['id' => $credential->id, 'ok' => true], 201)->withHeaders($this->privateHeaders());
    }

    public function update(Request $request, Site $site, SiteCredential $credential)
    {
        $this->authorizeCredential($request, $site, $credential, 'manage');
        $data = $request->validate($this->rules($site, $credential));
        $this->transaction($request, $credential, function () use ($request, $site, $credential, $data) {
            $locked = $this->locked($request, $site, $credential, 'manage');
            app(SiteCredentialHistory::class)->check($locked, $data['lock_version']);
            app(SiteCredentialHistory::class)->retain($locked, $request->user(), 'before_edit');
            $changes = collect($data)->except(['value', 'totp_secret', 'lock_version'])->all();
            if (! empty($data['value'])) {
                $changes['encrypted_value'] = $this->encryptionService->encrypt($data['value'])['value'];
                $changes['iv'] = null;
                // A stored replacement is not evidence of an external change.
                $changes['rotation_kind'] = 'stored_replacement';
                $changes['last_rotated_at'] = null;
                $changes['last_rotated_by_user_id'] = null;
                $changes['rotation_evidence'] = null;
            }
            $secret = $this->normaliseTotp($data['totp_secret'] ?? null);
            if ($secret) {
                $changes['totp_secret_encrypted'] = Crypt::encryptString($secret);
                $changes['totp_issuer'] = $site->name;
                $changes['totp_account'] = $data['username'] ?? $data['label'];
            }
            $locked->fill([...$changes, 'requires_reauth' => true, 'lock_version' => $locked->lock_version + 1])->save();
            app(SiteCredentialHistory::class)->retain($locked, $request->user(), 'edited');
            $this->audit($request, $locked, ! empty($data['value']) ? 'stored_replacement' : 'edit');
        });
        return response()->json(['ok' => true])->withHeaders($this->privateHeaders());
    }

    public function reveal(Request $request, Site $site, SiteCredential $credential)
    {
        return $this->disclose($request, $site, $credential, 'reveal');
    }

    public function copy(Request $request, Site $site, SiteCredential $credential)
    {
        return $this->disclose($request, $site, $credential, 'copy');
    }

    public function totpCode(Request $request, Site $site, SiteCredential $credential)
    {
        return $this->disclose($request, $site, $credential, 'totp');
    }

    private function disclose(Request $request, Site $site, SiteCredential $credential, string $action)
    {
        $capability = $action === 'copy' ? 'copy' : 'reveal';
        $this->authorizeCredential($request, $site, $credential, $capability);
        $result = $this->transaction($request, $credential, function () use ($request, $site, $credential, $action, $capability) {
            $locked = $this->locked($request, $site, $credential, $capability);
            $this->stepUp($request, $locked);
            // Audit must commit before the response can disclose anything.
            $audit = $this->audit($request, $locked, $action === 'copy' ? 'copy_intent' : ($action === 'totp' ? 'totp_code' : 'reveal'));
            if ($action === 'totp') {
                abort_unless($locked->hasTotp(), 404);
                $code = (new Google2FA)->getCurrentOtp(Crypt::decryptString($locked->totp_secret_encrypted));
                return ['code' => $code, 'seconds_remaining' => 30 - (now()->timestamp % 30), 'period' => 30];
            }
            $result = ['value' => $this->encryptionService->decrypt($locked->encrypted_value), 'expires_in' => 30];
            if ($action === 'copy') {
                $request->session()->put('vault_copy.'.$audit->id, ['credential_id' => $locked->id, 'user_id' => $request->user()->id, 'version' => $locked->lock_version, 'expires' => now()->addMinute()->timestamp]);
                $result['intent_id'] = $audit->id;
            }
            return $result;
        });
        return response()->json($result)->withHeaders($this->privateHeaders());
    }

    public function copyResult(Request $request, Site $site, SiteCredential $credential)
    {
        $this->authorizeCredential($request, $site, $credential, 'copy');
        $data = $request->validate(['intent_id' => 'required|integer', 'outcome' => 'required|in:succeeded,failed']);
        $this->transaction($request, $credential, function () use ($request, $site, $credential, $data) {
            $locked = $this->locked($request, $site, $credential, 'copy');
            $outcome = SiteCredentialAuditLog::where('copy_intent_id', $data['intent_id'])->first();
            if ($outcome) {
                abort_unless((int) $outcome->credential_id === (int) $locked->id && (int) $outcome->user_id === (int) $request->user()->id
                    && $outcome->action === 'copy_reported_'.$data['outcome'], 409);
                return;
            }
            $intent = $request->session()->get('vault_copy.'.$data['intent_id']);
            abort_unless(is_array($intent) && $intent['credential_id'] === $locked->id && $intent['user_id'] === $request->user()->id
                && $intent['version'] === $locked->lock_version && $intent['expires'] >= now()->timestamp, 409);
            $this->audit($request, $locked, 'copy_reported_'.$data['outcome'], $data['intent_id']);
            $request->session()->forget('vault_copy.'.$data['intent_id']);
        });
        return response()->json(['ok' => true])->withHeaders($this->privateHeaders());
    }

    public function status(Request $request, Site $site, SiteCredential $credential)
    {
        $this->authorizeCredential($request, $site, $credential, 'view', true);
        return response()->json(app(SiteCredentialAccess::class)->presentation($request->user(), $credential))->withHeaders($this->privateHeaders());
    }

    public function destroy(Request $request, Site $site, SiteCredential $credential)
    {
        return $this->lifecycle($request, $site, $credential, 'retire');
    }

    public function restore(Request $request, Site $site, SiteCredential $credential)
    {
        return $this->lifecycle($request, $site, $credential, 'restore');
    }

    private function lifecycle(Request $request, Site $site, SiteCredential $credential, string $action)
    {
        $this->authorizeCredential($request, $site, $credential, 'manage', true);
        $data = $request->validate(['lock_version' => 'required|integer', 'evidence' => 'required|string|min:10|max:2000']);
        $this->transaction($request, $credential, function () use ($request, $site, $credential, $action, $data) {
            $locked = $this->locked($request, $site, $credential, 'manage', true);
            app(SiteCredentialHistory::class)->check($locked, $data['lock_version']);
            abort_unless(($locked->retired_at !== null) === ($action === 'restore'), 409);
            $this->stepUp($request, $locked);
            app(SiteCredentialHistory::class)->retain($locked, $request->user(), 'before_'.$action);
            $locked->fill(['retired_at' => $action === 'retire' ? now() : null, 'lock_version' => $locked->lock_version + 1])->save();
            app(SiteCredentialHistory::class)->retain($locked, $request->user(), $action, $data['evidence']);
            $this->audit($request, $locked, $action);
        });
        return response()->json(['ok' => true])->withHeaders($this->privateHeaders());
    }

    public function rotate(Request $request, Site $site, SiteCredential $credential)
    {
        $this->authorizeCredential($request, $site, $credential, 'manage');
        $data = $request->validate(['lock_version' => 'required|integer', 'rotation_kind' => 'required|in:external_attestation,external_replacement',
            'evidence' => 'required|string|min:10|max:2000', 'changed_at' => 'required|date|before_or_equal:now', 'value' => 'required_if:rotation_kind,external_replacement|nullable|string|max:65536']);
        $this->transaction($request, $credential, function () use ($request, $site, $credential, $data) {
            $locked = $this->locked($request, $site, $credential, 'manage');
            app(SiteCredentialHistory::class)->check($locked, $data['lock_version']);
            $this->stepUp($request, $locked);
            app(SiteCredentialHistory::class)->retain($locked, $request->user(), 'before_rotation');
            if ($data['rotation_kind'] === 'external_replacement') {
                $locked->encrypted_value = $this->encryptionService->encrypt($data['value'])['value'];
                $locked->iv = null;
            }
            $locked->fill(['rotation_kind' => $data['rotation_kind'], 'rotation_evidence' => $data['evidence'],
                'last_rotated_at' => $data['changed_at'], 'last_rotated_by_user_id' => $request->user()->id, 'lock_version' => $locked->lock_version + 1])->save();
            app(SiteCredentialHistory::class)->retain($locked, $request->user(), $data['rotation_kind'], $data['evidence']);
            $this->audit($request, $locked, $data['rotation_kind']);
        });
        return response()->json(['ok' => true])->withHeaders($this->privateHeaders());
    }

    public function recover(Request $request, Site $site, SiteCredential $credential)
    {
        $this->authorizeCredential($request, $site, $credential, 'manage', true);
        $data = $request->validate(['lock_version' => 'required|integer', 'version_id' => 'required|integer', 'evidence' => 'required|string|min:10|max:2000']);
        $this->transaction($request, $credential, function () use ($request, $site, $credential, $data) {
            $locked = $this->locked($request, $site, $credential, 'manage', true);
            app(SiteCredentialHistory::class)->check($locked, $data['lock_version']);
            $this->stepUp($request, $locked);
            $version = SiteCredentialVersion::where('credential_id', $locked->id)->findOrFail($data['version_id']);
            $snapshot = json_decode(Crypt::decryptString($version->encrypted_snapshot), true, 512, JSON_THROW_ON_ERROR);
            // Verify decryptability without disclosing or rewriting key formats.
            $this->encryptionService->decrypt($snapshot['encrypted_value']);
            app(SiteCredentialHistory::class)->retain($locked, $request->user(), 'before_recovery');
            $locked->fill(collect($snapshot)->only(['encrypted_value', 'iv', 'totp_secret_encrypted', 'totp_issuer', 'totp_account'])->all());
            // Recovery never reinstates old access grants or claims external rotation.
            $locked->fill(['lock_version' => $locked->lock_version + 1, 'rotation_kind' => 'recovered_storage',
                'last_rotated_at' => null, 'last_rotated_by_user_id' => null, 'rotation_evidence' => $data['evidence']])->save();
            app(SiteCredentialHistory::class)->retain($locked, $request->user(), 'recovered_storage', $data['evidence']);
            $this->audit($request, $locked, 'recovered_storage');
        });
        return response()->json(['ok' => true])->withHeaders($this->privateHeaders());
    }

    public function toggleReauth(Request $request, Site $site, SiteCredential $credential)
    {
        $this->authorizeCredential($request, $site, $credential, 'manage');
        throw ValidationException::withMessages(['requires_reauth' => 'Identity confirmation is required for shared vault disclosure. It cannot be disabled.']);
    }

    public function removeTotp(Request $request, Site $site, SiteCredential $credential)
    {
        $this->authorizeCredential($request, $site, $credential, 'manage');
        $request->validate(['lock_version' => 'required|integer']);
        $this->transaction($request, $credential, function () use ($request, $site, $credential) {
            $locked = $this->locked($request, $site, $credential, 'manage');
            app(SiteCredentialHistory::class)->check($locked, $request->input('lock_version'));
            $this->stepUp($request, $locked);
            app(SiteCredentialHistory::class)->retain($locked, $request->user(), 'before_totp_remove');
            $locked->fill(['totp_secret_encrypted' => null, 'totp_issuer' => null, 'totp_account' => null, 'lock_version' => $locked->lock_version + 1])->save();
            app(SiteCredentialHistory::class)->retain($locked, $request->user(), 'totp_removed');
            $this->audit($request, $locked, 'totp_remove');
        });
        return response()->json(['ok' => true])->withHeaders($this->privateHeaders());
    }

    public function versions(Request $request, Site $site, SiteCredential $credential)
    {
        $this->authorizeCredential($request, $site, $credential, 'manage', true);
        return response()->json(['versions' => $this->versionMetadata($credential)])->withHeaders($this->privateHeaders());
    }

    private function versionMetadata(SiteCredential $credential)
    {
        return SiteCredentialVersion::where('credential_id', $credential->id)->orderByDesc('version')->limit(100)->get(['id', 'version', 'action', 'user_id', 'created_at']);
    }

    public function auditLog(Request $request, Site $site, SiteCredential $credential)
    {
        $this->authorizeCredential($request, $site, $credential, 'audit', true);
        $logs = SiteCredentialAuditLog::where('credential_id', $credential->id)->with('user:id,name')->latest('created_at')->paginate(50);
        if ($request->expectsJson()) return response()->json(['logs' => $logs,
            'versions' => $this->versionMetadata($credential)])->withHeaders($this->privateHeaders());
        return inertia('sites/credentials/audit', ['site' => $site->only('id', 'name'), 'credential' => $credential->only('id', 'label'), 'logs' => $logs]);
    }

    private function rules(Site $site, ?SiteCredential $credential = null): array
    {
        return [
            'creation_key' => $credential ? 'prohibited' : 'nullable|uuid',
            'label' => 'required|string|max:255', 'credential_type' => ['required', 'string', Rule::in(array_unique([...CredentialType::pickerOptions()->pluck('key')->all(), ...($credential ? [$credential->credential_type] : [])]))],
            'value' => ($credential ? 'nullable' : 'required').'|string|max:65536', 'username' => 'nullable|string|max:255',
            'url' => ['nullable', 'url:http,https', 'max:2048'],
            'vendor_id' => ['nullable', Rule::exists('site_vendors', 'id')->where(fn ($q) => $q->where('site_id', $site->id))],
            'notes' => 'nullable|string|max:5000', 'requires_reauth' => 'boolean', 'is_shareable' => 'boolean',
            'visibility' => ['sometimes', Rule::in(['site', 'all_approved_sites'])], 'house_staff_access' => ['sometimes', 'boolean', function ($key, $value, $fail) use ($site) {
                if ($value && $site->type !== 'house') $fail('Assigned house staff access is available for a house credential only.');
            }],
            'lock_version' => $credential ? 'required|integer|min:1' : 'nullable|integer',
            'password_strength' => 'nullable|integer|min:0|max:4',
            'totp_secret' => ['nullable', 'string', 'max:128', function ($key, $value, $fail) {
                $normal = $this->normaliseTotp($value);
                if ($normal !== null && (strlen($normal) < 16 || ! preg_match('/^[A-Z2-7]+$/', $normal))) $fail('Choose a valid Base32 authenticator secret.');
            }],
        ];
    }

    private function normaliseTotp(?string $value): ?string
    {
        $normal = strtoupper(preg_replace('/\s+/', '', $value ?? ''));
        return $normal !== '' ? $normal : null;
    }

    private function currentActor(Request $request): User
    {
        $actor = User::findOrFail($request->user()->id);
        abort_unless($actor->approved_at, 403);
        $request->setUserResolver(fn () => $actor);
        return $actor;
    }

    private function authorizeCredential(Request $request, Site $site, SiteCredential $credential, string $action, bool $retired = false): void
    {
        abort_unless((int) $credential->site_id === (int) $site->id, 404);
        app(SiteCredentialAccess::class)->authorize($this->currentActor($request), $credential, $action, $retired);
    }

    private function locked(Request $request, Site $site, SiteCredential $credential, string $action, bool $retired = false): SiteCredential
    {
        $locked = SiteCredential::lockForUpdate()->findOrFail($credential->id);
        $this->authorizeCredential($request, $site, $locked, $action, $retired);
        return $locked;
    }

    private function stepUp(Request $request, SiteCredential $credential): void
    {
        try {
            if (app(SiteCredentialStepUp::class)->verify($request, $credential)) $this->audit($request, $credential, 'reauth_passed');
        } catch (\Throwable $e) {
            $request->session()->forget('vault_step_up.'.$request->user()->id.'.'.$credential->id);
            throw $e;
        }
    }

    private function audit(Request $request, SiteCredential $credential, string $action, ?int $copyIntentId = null): SiteCredentialAuditLog
    {
        return SiteCredentialAuditLog::create(['credential_id' => $credential->id, 'site_id' => $credential->site_id, 'copy_intent_id' => $copyIntentId,
            'credential_label' => $credential->label, 'credential_type' => $credential->credential_type, 'user_id' => $request->user()->id,
            'action' => $action, 'ip_address' => $request->ip(), 'user_agent' => mb_substr($request->userAgent() ?? '', 0, 1000), 'created_at' => now()]);
    }

    private function transaction(Request $request, SiteCredential $credential, \Closure $work): mixed
    {
        try {
            return DB::transaction($work);
        } catch (ValidationException $exception) {
            if (array_key_exists('verification_code', $exception->errors())) {
                $this->audit($request, $credential, 'reauth_failed');
            }
            throw $exception;
        }
    }

    private function privateHeaders(): array
    {
        return ['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer'];
    }
}
