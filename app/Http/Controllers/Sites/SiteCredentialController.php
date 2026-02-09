<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteCredential;
use App\Models\SiteCredentialAuditLog;
use App\Services\Sites\SiteCredentialEncryptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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
                'credential_type' => $c->credential_type,
                'vendor' => $c->vendor,
                'notes' => $c->notes,
                'last_rotated_at' => $c->last_rotated_at?->toDateTimeString(),
                'requires_reauth' => $c->requires_reauth,
                'created_at' => $c->created_at->toDateTimeString(),
                // Never send encrypted_value in list view
                'value_preview' => '••••••••',
            ]);

        return inertia('sites/credentials/index', [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'type' => $site->type,
            ],
            'credentials' => $credentials,
            'canReveal' => $request->user()->canDo('credentials.reveal'),
            'canManage' => $request->user()->canDo('credentials.manage'),
        ]);
    }

    public function store(Request $request, Site $site)
    {
        $this->authorize('update', $site);
        $request->user()->canDo('credentials.manage') || abort(403);

        $validated = $request->validate([
            'label' => 'required|string|max:255',
            'credential_type' => 'required|string|max:30',
            'value' => 'required|string',
            'vendor_id' => 'nullable|exists:site_vendors,id',
            'notes' => 'nullable|string',
            'requires_reauth' => 'boolean',
        ]);

        $encrypted = $this->encryptionService->encrypt($validated['value']);

        $credential = SiteCredential::create([
            'site_id' => $site->id,
            'vendor_id' => $validated['vendor_id'] ?? null,
            'label' => $validated['label'],
            'credential_type' => $validated['credential_type'],
            'encrypted_value' => $encrypted['encrypted'],
            'iv' => $encrypted['iv'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'requires_reauth' => $validated['requires_reauth'] ?? false,
            'last_rotated_at' => now(),
            'last_rotated_by_user_id' => $request->user()->id,
        ]);

        return redirect()
            ->route('sites.credentials.index', $site)
            ->with('success', 'Credential added successfully.');
    }

    public function reveal(Request $request, Site $site, SiteCredential $credential)
    {
        $this->authorize('view', $site);
        $request->user()->canDo('credentials.reveal') || abort(403);

        // Check if re-authentication is required
        if ($credential->requires_reauth) {
            $request->validate([
                'password' => 'required|string',
            ]);

            // Verify user's password
            if (!Auth::validate(['email' => $request->user()->email, 'password' => $request->input('password')])) {
                return response()->json(['error' => 'Invalid password'], 403);
            }
        }

        // Audit log
        SiteCredentialAuditLog::create([
            'credential_id' => $credential->id,
            'user_id' => $request->user()->id,
            'action' => 'reveal',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        $value = $this->encryptionService->decrypt(
            $credential->encrypted_value,
            $credential->iv
        );

        return response()->json([
            'value' => $value,
        ]);
    }

    public function update(Request $request, Site $site, SiteCredential $credential)
    {
        $this->authorize('update', $site);
        $request->user()->canDo('credentials.manage') || abort(403);

        $validated = $request->validate([
            'label' => 'required|string|max:255',
            'credential_type' => 'required|string|max:30',
            'value' => 'nullable|string',
            'vendor_id' => 'nullable|exists:site_vendors,id',
            'notes' => 'nullable|string',
            'requires_reauth' => 'boolean',
        ]);

        $updateData = [
            'label' => $validated['label'],
            'credential_type' => $validated['credential_type'],
            'vendor_id' => $validated['vendor_id'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'requires_reauth' => $validated['requires_reauth'] ?? false,
        ];

        // If value provided, re-encrypt
        if (!empty($validated['value'])) {
            $encrypted = $this->encryptionService->encrypt($validated['value']);
            $updateData['encrypted_value'] = $encrypted['encrypted'];
            $updateData['iv'] = $encrypted['iv'] ?? null;
            $updateData['last_rotated_at'] = now();
            $updateData['last_rotated_by_user_id'] = $request->user()->id;

            // Audit rotation
            SiteCredentialAuditLog::create([
                'credential_id' => $credential->id,
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
                'user_id' => $request->user()->id,
                'action' => 'edit',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);
        }

        $credential->update($updateData);

        return redirect()
            ->route('sites.credentials.index', $site)
            ->with('success', 'Credential updated successfully.');
    }

    public function destroy(Request $request, Site $site, SiteCredential $credential)
    {
        $this->authorize('update', $site);
        $request->user()->canDo('credentials.manage') || abort(403);

        // Audit deletion
        SiteCredentialAuditLog::create([
            'credential_id' => $credential->id,
            'user_id' => $request->user()->id,
            'action' => 'delete',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        $credential->delete();

        return redirect()
            ->route('sites.credentials.index', $site)
            ->with('success', 'Credential deleted successfully.');
    }

    public function auditLog(Request $request, Site $site, SiteCredential $credential)
    {
        $this->authorize('view', $site);

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
}
