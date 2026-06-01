<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\CredentialType;
use App\Models\SiteCredential;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Manage the tenant's credential-type registry (powers the type tile picker).
 * Gated on credentials.manage — the same right needed to create credentials.
 */
class CredentialTypeController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless((bool) ($user?->canDo('credentials.manage') ?? false), 403);

        $tenantId = $user?->organization_id;
        $counts = $this->usageCounts($tenantId);

        $types = CredentialType::effectiveForTenant($tenantId)
            ->map(fn (array $type) => [
                ...$type,
                'count' => (int) ($counts[$type['key']] ?? 0),
            ])
            ->values();

        return response()->json([
            'types' => $types,
            'icons' => CredentialType::ICONS,
        ]);
    }

    public function bulkSave(Request $request)
    {
        $user = $request->user();
        abort_unless((bool) ($user?->canDo('credentials.manage') ?? false), 403);

        $tenantId = $user?->organization_id;
        abort_unless($tenantId, 422, 'No organization context is available for saving credential types.');

        $validated = $request->validate([
            'types' => 'required|array|min:1',
            'types.*.key' => 'required|string|max:50',
            'types.*.label' => 'required|string|max:100',
            'types.*.icon' => ['required', 'string', Rule::in(CredentialType::ICONS)],
            'types.*.description' => 'nullable|string|max:255',
            'types.*.active' => 'boolean',
        ]);

        $defaultKeys = CredentialType::defaultKeys();
        $usage = $this->usageCounts($tenantId);

        // Normalise + de-dupe the incoming keys (custom keys become snake_case).
        $rows = [];
        foreach ($validated['types'] as $type) {
            $isDefault = in_array($type['key'], $defaultKeys, true);
            $key = $isDefault ? $type['key'] : (Str::slug($type['key'], '_') ?: $type['key']);
            $rows[$key] = [
                'key' => $key,
                'label' => $type['label'],
                'icon' => $type['icon'],
                'description' => $type['description'] ?? null,
                // System types can never be hidden.
                'active' => CredentialType::isSystemKey($key) ? true : (bool) ($type['active'] ?? true),
                'is_system' => CredentialType::isSystemKey($key),
            ];
        }

        $incomingKeys = array_keys($rows);

        DB::transaction(function () use ($rows, $tenantId, $incomingKeys, $defaultKeys, $usage) {
            $order = 0;
            foreach ($rows as $row) {
                CredentialType::updateOrCreate(
                    ['tenant_id' => $tenantId, 'key' => $row['key']],
                    [
                        'label' => $row['label'],
                        'icon' => $row['icon'],
                        'description' => $row['description'],
                        'active' => $row['active'],
                        'sort_order' => $order++,
                        'is_system' => $row['is_system'],
                    ],
                );
            }

            // Reconcile deletes: stored custom (non-default, non-system) types
            // that were removed in the dialog AND are not referenced by any
            // credential. Defaults and in-use types are never deleted.
            CredentialType::query()
                ->where('tenant_id', $tenantId)
                ->where('is_system', false)
                ->whereNotIn('key', array_merge($incomingKeys, $defaultKeys))
                ->get()
                ->each(function (CredentialType $row) use ($usage) {
                    if ((int) ($usage[$row->key] ?? 0) === 0) {
                        $row->delete();
                    }
                });
        });

        return response()->json(['ok' => true]);
    }

    /**
     * @return \Illuminate\Support\Collection<string, int>
     */
    private function usageCounts(?int $tenantId)
    {
        return SiteCredential::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->selectRaw('credential_type, COUNT(*) as c')
            ->groupBy('credential_type')
            ->pluck('c', 'credential_type');
    }
}
