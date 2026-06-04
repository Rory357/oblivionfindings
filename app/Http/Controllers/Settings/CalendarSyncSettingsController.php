<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\SyncResourceCalendarsJob;
use App\Models\AppSetting;
use App\Models\CalendarSyncConnection;
use App\Models\CalendarSyncMapping;
use App\Models\Site;
use App\Services\Sites\Calendar\CalendarSources;
use App\Services\Sites\Calendar\CalendarSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CalendarSyncSettingsController extends Controller
{
    private const SETTINGS_KEY = 'sites.calendar_sync.settings';

    private const DEFAULT_SETTINGS = [
        'cadence_minutes' => 15,
        'conflict_policy' => 'external_busy_counts', // external_busy_counts | ignore
    ];

    private const PROVIDERS = [
        CalendarSyncConnection::PROVIDER_GOOGLE => 'Google Workspace',
        CalendarSyncConnection::PROVIDER_MICROSOFT => 'Microsoft 365',
    ];

    public function __construct(private CalendarSyncService $syncService) {}

    public function index(Request $request): Response
    {
        $this->authorizeManage($request);

        $tenantId = $this->tenantId($request);

        $connections = CalendarSyncConnection::query()
            ->where('tenant_id', $tenantId)
            ->get()
            ->keyBy('provider');

        $providers = collect(self::PROVIDERS)->map(function (string $label, string $key) use ($connections) {
            $conn = $connections->get($key);

            return [
                'key' => $key,
                'label' => $label,
                'configured' => $this->isConfigured($key),
                'connected' => (bool) $conn?->isConnected(),
                'accountEmail' => $conn?->account_email,
                'accountName' => $conn?->account_name,
                'lastSyncedAt' => $conn?->last_synced_at?->toIso8601String(),
                'status' => $conn?->status ?? CalendarSyncConnection::STATUS_DISCONNECTED,
            ];
        })->values()->all();

        $mappings = CalendarSyncMapping::query()
            ->where('tenant_id', $tenantId)
            ->get()
            ->keyBy('site_id');

        $sites = Site::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'type'])
            ->map(function (Site $site) use ($mappings) {
                $mapping = $mappings->get($site->id);

                return [
                    'id' => $site->id,
                    'name' => $site->name,
                    'type' => $site->type,
                    'mapping' => $mapping ? $this->mappingPayload($mapping) : null,
                ];
            })
            ->values()
            ->all();

        return Inertia::render('settings/calendar-sync', [
            'providers' => $providers,
            'sites' => $sites,
            'sources' => CalendarSources::pushable(),
            'directions' => [
                ['key' => CalendarSyncMapping::DIRECTION_ONE_WAY, 'label' => 'One-way (push out)'],
                ['key' => CalendarSyncMapping::DIRECTION_TWO_WAY, 'label' => 'Two-way (push + pull busy)'],
            ],
            'settings' => $this->loadSettings(),
            'anyConnected' => collect($providers)->contains('connected', true),
        ]);
    }

    /**
     * Upsert the per-house mapping. An empty provider deactivates the house's mappings.
     */
    public function updateMapping(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);

        $data = $request->validate([
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'provider' => ['nullable', 'in:google,microsoft'],
            'external_calendar_id' => ['nullable', 'string', 'max:255'],
            'external_calendar_name' => ['nullable', 'string', 'max:255'],
            'sync_direction' => ['nullable', 'in:one_way,two_way'],
            'sources' => ['nullable', 'array'],
            'sources.*' => ['string', 'in:'.implode(',', array_column(CalendarSources::pushable(), 'key'))],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $tenantId = $this->tenantId($request);

        if (empty($data['provider'])) {
            CalendarSyncMapping::query()
                ->where('tenant_id', $tenantId)
                ->where('site_id', $data['site_id'])
                ->update(['is_active' => false]);

            return back()->with('success', 'House calendar sync disabled.');
        }

        $mapping = CalendarSyncMapping::firstOrNew([
            'tenant_id' => $tenantId,
            'site_id' => $data['site_id'],
            'provider' => $data['provider'],
        ]);

        $mapping->fill([
            'external_calendar_id' => $data['external_calendar_id'] ?? null,
            'external_calendar_name' => $data['external_calendar_name'] ?? null,
            'sync_direction' => $data['sync_direction'] ?? CalendarSyncMapping::DIRECTION_ONE_WAY,
            'sources' => $data['sources'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        if (! $mapping->ical_feed_token) {
            $mapping->ical_feed_token = Str::random(48);
        }

        $mapping->save();

        // Other providers for the same site become inactive (one live mapping per house).
        CalendarSyncMapping::query()
            ->where('tenant_id', $tenantId)
            ->where('site_id', $data['site_id'])
            ->where('provider', '!=', $data['provider'])
            ->update(['is_active' => false]);

        return back()->with('success', 'House calendar mapping saved.');
    }

    public function updateGlobal(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);

        $data = $request->validate([
            'cadence_minutes' => ['required', 'integer', 'in:5,15,30,60,180,360'],
            'conflict_policy' => ['required', 'in:external_busy_counts,ignore'],
        ]);

        AppSetting::updateOrCreate(
            ['key' => self::SETTINGS_KEY],
            ['value' => $data],
        );

        return back()->with('success', 'Calendar sync settings saved.');
    }

    /**
     * AJAX: list the resource calendars / room mailboxes for a connected provider.
     */
    public function resources(Request $request, string $provider): JsonResponse
    {
        $this->authorizeManage($request);
        abort_unless(isset(self::PROVIDERS[$provider]), 404);

        $connection = CalendarSyncConnection::query()
            ->where('tenant_id', $this->tenantId($request))
            ->where('provider', $provider)
            ->first();

        if (! $connection || ! $connection->isConnected()) {
            return response()->json(['resources' => [], 'connected' => false]);
        }

        return response()->json([
            'resources' => $this->syncService->listResources($connection),
            'connected' => true,
        ]);
    }

    public function syncNow(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);

        $mappingId = $request->integer('mapping_id') ?: null;
        if ($mappingId !== null) {
            abort_unless(
                CalendarSyncMapping::query()
                    ->whereKey($mappingId)
                    ->where('tenant_id', $this->tenantId($request))
                    ->exists(),
                403,
            );
        }
        SyncResourceCalendarsJob::dispatch($mappingId);

        return back()->with('success', 'Calendar sync queued.');
    }

    public function resetFeed(Request $request, CalendarSyncMapping $mapping): RedirectResponse
    {
        $this->authorizeManage($request);
        abort_unless($mapping->tenant_id === $this->tenantId($request), 403);

        $mapping->update(['ical_feed_token' => Str::random(48)]);

        return back()->with('success', 'House feed link reset.');
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function mappingPayload(CalendarSyncMapping $mapping): array
    {
        return [
            'id' => $mapping->id,
            'provider' => $mapping->provider,
            'externalCalendarId' => $mapping->external_calendar_id,
            'externalCalendarName' => $mapping->external_calendar_name,
            'syncDirection' => $mapping->sync_direction,
            'sources' => $mapping->sources,
            'isActive' => $mapping->is_active,
            'lastSyncedAt' => $mapping->last_synced_at?->toIso8601String(),
            'lastError' => $mapping->last_error,
            'feedUrl' => $mapping->ical_feed_token
                ? url("/calendar/site/{$mapping->site_id}/feed/{$mapping->ical_feed_token}.ics")
                : null,
        ];
    }

    /**
     * @return array{cadence_minutes:int,conflict_policy:string}
     */
    private function loadSettings(): array
    {
        $stored = AppSetting::query()->where('key', self::SETTINGS_KEY)->value('value');

        return array_merge(self::DEFAULT_SETTINGS, is_array($stored) ? $stored : []);
    }

    private function isConfigured(string $provider): bool
    {
        return ! empty(config("services.{$provider}.client_id"))
            && ! empty(config("services.{$provider}.client_secret"));
    }

    private function tenantId(Request $request): int
    {
        return (int) ($request->user()->tenant_id ?? 0);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()?->canDo('integrations.manage_tenant_secrets'), 403);
    }
}
