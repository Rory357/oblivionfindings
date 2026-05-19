<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\AssetGeofence;
use App\Models\Site;
use App\Services\AuditLogger;
use App\Services\Fleet\GeofenceStateCleanupService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SiteGeofenceController extends Controller
{
    public function store(Request $request, Site $site)
    {
        $data = $this->validatedData($request, $site);

        $geofence = AssetGeofence::query()
            ->where('site_id', $site->id)
            ->whereNull('asset_id')
            ->first() ?? new AssetGeofence;

        $geofence->fill($this->geofenceAttributes($data, $site));
        $geofence->save();
        $geofence->assignedAssets()->sync($data['asset_ids'] ?? []);

        AuditLogger::log('site.geofence.save', $geofence, [
            'site_id' => $site->id,
            'geofence_id' => $geofence->id,
        ]);

        return back()->with('success', 'Site geofence saved.');
    }

    public function update(Request $request, Site $site, AssetGeofence $geofence)
    {
        $this->ensureSiteScopedGeofence($site, $geofence);

        $data = $this->validatedData($request, $site);

        $geofence->update($this->geofenceAttributes($data, $site));
        $geofence->assignedAssets()->sync($data['asset_ids'] ?? []);

        AuditLogger::log('site.geofence.save', $geofence, [
            'site_id' => $site->id,
            'geofence_id' => $geofence->id,
        ]);

        return back()->with('success', 'Site geofence saved.');
    }

    public function destroy(Request $request, Site $site, AssetGeofence $geofence, GeofenceStateCleanupService $cleanup)
    {
        $this->ensureSiteScopedGeofence($site, $geofence);

        $cleanup->cleanup($geofence);

        AuditLogger::log('site.geofence.delete', $geofence, [
            'site_id' => $site->id,
            'geofence_id' => $geofence->id,
        ]);

        $geofence->delete();

        return back()->with('success', 'Site geofence deleted.');
    }

    private function validatedData(Request $request, Site $site): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:circle,polygon'],
            'shape' => ['required', 'array'],
            'breach_type' => ['required', 'string', 'in:enter,exit,both'],
            'is_active' => ['boolean'],
            'asset_ids' => ['array'],
            'asset_ids.*' => [
                'integer',
                Rule::exists('assets', 'id')->where(fn ($query) => $query->where('site_id', $site->id)),
            ],
        ]);
    }

    private function geofenceAttributes(array $data, Site $site): array
    {
        return [
            'asset_id' => null,
            'site_id' => $site->id,
            'name' => $data['name'],
            'type' => $data['type'],
            'scope' => $this->scopeForSite($site),
            'shape' => $data['shape'],
            'breach_type' => $data['breach_type'],
            'alert_config' => null,
            'time_rules' => null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * Derive a sensible default scope from the site's type. Residential
     * houses get `house`; facilities get `asset`; everything else stays
     * `site`. Operators can override later via the editor's scope field.
     */
    private function scopeForSite(Site $site): string
    {
        return match ($site->type) {
            'house', 'residential' => 'house',
            'facility' => 'asset',
            default => 'site',
        };
    }

    private function ensureSiteScopedGeofence(Site $site, AssetGeofence $geofence): void
    {
        abort_unless(
            (int) $geofence->site_id === (int) $site->id && $geofence->asset_id === null,
            404
        );
    }
}
