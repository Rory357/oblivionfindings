<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\CredentialType;
use App\Models\SiteCredential;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Manage the application credential-type catalogue (powers the type picker).
 * Gated on credentials.manage — the same right needed to create credentials.
 */
class CredentialTypeController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless((bool) ($user?->canDo('credentials.manage') ?? false), 403);

        $counts = $this->usageCounts();

        $types = CredentialType::applicationCatalogue()
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

        $validated = $request->validate([
            'types' => 'required|array|min:1',
            'types.*.key' => 'required|string|max:30',
            'types.*.label' => 'required|string|max:100',
            'types.*.icon' => ['required', 'string', Rule::in(CredentialType::ICONS)],
            'types.*.description' => 'nullable|string|max:255',
            'types.*.active' => 'boolean',
        ]);

        $defaultKeys = CredentialType::defaultKeys();

        // Normalise + de-dupe the incoming keys (custom keys become snake_case).
        $rows = [];
        foreach ($validated['types'] as $type) {
            $isDefault = in_array($type['key'], $defaultKeys, true);
            $key = $isDefault ? $type['key'] : Str::slug($type['key'], '_');
            if ($key === '' || array_key_exists($key, $rows)) {
                throw ValidationException::withMessages([
                    'types' => 'Credential type keys must be non-empty and unique after normalisation.',
                ]);
            }

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

        DB::transaction(function () use ($rows, $incomingKeys, $defaultKeys): void {
            CredentialType::query()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $order = 0;
            foreach ($rows as $row) {
                CredentialType::updateOrCreate(
                    ['key' => $row['key']],
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
                ->where('is_system', false)
                ->whereNotIn('key', array_merge($incomingKeys, $defaultKeys))
                ->get()
                ->each(function (CredentialType $row): void {
                    if (! SiteCredential::query()->where('credential_type', $row->key)->exists()) {
                        $row->delete();
                    }
                });
        });

        return response()->json(['ok' => true]);
    }

    /**
     * @return Collection<string, int>
     */
    private function usageCounts()
    {
        return SiteCredential::query()
            ->selectRaw('credential_type, COUNT(*) as c')
            ->groupBy('credential_type')
            ->pluck('c', 'credential_type');
    }
}
