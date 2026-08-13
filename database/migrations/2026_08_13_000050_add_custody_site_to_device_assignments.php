<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_assignments', function (Blueprint $table): void {
            $table->unsignedBigInteger('custody_site_id')
                ->nullable()
                ->after('assignable_id')
                ->comment('Immutable Site custody snapshot; NULL is unresolved/quarantined history.');
            $table->index(
                ['custody_site_id', 'assigned_at', 'released_at'],
                'device_assignments_custody_window_idx',
            );
        });

        DB::table('device_assignments')
            ->orderBy('id')
            ->chunkById(200, function ($assignments): void {
                foreach ($assignments as $assignment) {
                    $siteId = $this->resolveLegacyCustodySite(
                        (string) $assignment->assignable_type,
                        (int) $assignment->assignable_id,
                        $assignment->released_at !== null,
                    );

                    DB::table('device_assignments')
                        ->where('id', $assignment->id)
                        ->update(['custody_site_id' => $siteId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('device_assignments', function (Blueprint $table): void {
            $table->dropIndex('device_assignments_custody_window_idx');
            $table->dropColumn('custody_site_id');
        });
    }

    private function resolveLegacyCustodySite(string $type, int $id, bool $released): ?int
    {
        // A mutable target's current Site must never be presented as historical
        // fact. Only a direct Site assignment is intrinsically stable; older
        // indirect released rows remain explicitly unresolved/quarantined.
        if ($released && $type !== 'site') {
            return null;
        }

        $siteIds = match ($type) {
            'site' => collect([$id]),
            'room' => collect([DB::table('site_rooms')->where('id', $id)->value('site_id')]),
            'client' => collect([DB::table('clients')
                ->where('id', $id)
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->value('site_id')]),
            'staff' => collect([DB::table('hr_employee_profiles')
                ->where('user_id', $id)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->where(fn ($dates) => $dates->whereNull('start_date')->orWhereDate('start_date', '<=', today()))
                ->where(fn ($dates) => $dates->whereNull('end_date')->orWhereDate('end_date', '>=', today()))
                ->value('primary_site_id')]),
            'vehicle' => $this->legacyAssetSiteIds($id),
            default => collect(),
        };

        $siteIds = $siteIds
            ->filter(fn (mixed $siteId): bool => is_numeric($siteId) && (int) $siteId > 0)
            ->map(fn (mixed $siteId): int => (int) $siteId)
            ->unique()
            ->values();

        return $siteIds->count() === 1 ? (int) $siteIds->first() : null;
    }

    private function legacyAssetSiteIds(int $assetId)
    {
        $asset = DB::table('assets')
            ->where('id', $assetId)
            ->where('status', 'active')
            ->where(function ($vehicle): void {
                $vehicle->whereRaw('LOWER(category) = ?', ['vehicle'])
                    ->orWhereExists(fn ($categories) => $categories
                        ->selectRaw('1')
                        ->from('asset_categories')
                        ->whereColumn('asset_categories.id', 'assets.asset_category_id')
                        ->whereRaw('LOWER(asset_categories.slug) = ?', ['vehicle']));
            })
            ->first(['site_id', 'home_site_id', 'client_id']);
        if (! $asset) {
            return collect();
        }

        $siteIds = collect([$asset->site_id, $asset->home_site_id]);
        if ($asset->client_id) {
            $siteIds->push(DB::table('clients')
                ->where('id', $asset->client_id)
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->value('site_id'));
        }

        return $siteIds;
    }
};
