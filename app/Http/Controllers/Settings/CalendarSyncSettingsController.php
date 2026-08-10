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
use App\Services\UserSiteAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CalendarSyncSettingsController extends Controller
{
    private const SITE_BYPASS_PERMISSIONS = ['integrations.manage_secrets'];

    private const SETTINGS_KEY = 'sites.calendar_sync.settings';

    private const DEFAULT_SETTINGS = [
        'cadence_minutes' => 15,
        'conflict_policy' => 'external_busy_counts', // external_busy_counts | ignore
    ];

    private const PROVIDERS = [
        CalendarSyncConnection::PROVIDER_GOOGLE => 'Google Workspace',
        CalendarSyncConnection::PROVIDER_MICROSOFT => 'Microsoft 365',
    ];

    public function __construct(
        private CalendarSyncService $syncService,
        private UserSiteAccessService $siteAccess,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeManage($request);

        $siteIds = $this->siteAccess->accessibleSiteIds(
            $request->user(),
            self::SITE_BYPASS_PERMISSIONS,
        );

        $connections = CalendarSyncConnection::query()
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

        $mappingGroups = CalendarSyncMapping::query()
            ->active()
            ->whereIn('site_id', $siteIds)
            ->get()
            ->groupBy(fn (CalendarSyncMapping $mapping): int => (int) $mapping->site_id);
        abort_if(
            $mappingGroups->contains(fn ($siteMappings): bool => $siteMappings->count() > 1),
            409,
            'Calendar mapping configuration requires reconciliation before it can be managed.',
        );
        $mappings = $mappingGroups->map(fn ($siteMappings) => $siteMappings->first());

        $sites = Site::query()
            ->active()
            ->notArchived()
            ->whereNull('archived_at')
            ->whereIn('id', $siteIds)
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
        $this->assertCanManageSite($request, $request->integer('site_id'));

        $data = $request->validate([
            'site_id' => ['required', 'integer'],
            'provider' => ['nullable', 'in:google,microsoft'],
            'external_calendar_id' => ['nullable', 'string', 'max:255'],
            'external_calendar_name' => ['nullable', 'string', 'max:255'],
            'sync_direction' => ['nullable', 'in:one_way,two_way'],
            'sources' => ['nullable', 'array'],
            'sources.*' => ['string', 'in:'.implode(',', array_column(CalendarSources::pushable(), 'key'))],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return DB::transaction(function () use ($data): RedirectResponse {
            $site = Site::query()
                ->active()
                ->notArchived()
                ->whereNull('archived_at')
                ->whereKey($data['site_id'])
                ->lockForUpdate()
                ->first();
            abort_unless($site, 403);

            if (empty($data['provider'])) {
                CalendarSyncMapping::query()
                    ->where('site_id', $site->id)
                    ->update(['is_active' => false]);

                return back()->with('success', 'House calendar sync disabled.');
            }

            $mapping = CalendarSyncMapping::firstOrNew([
                'site_id' => $site->id,
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
                ->where('site_id', $site->id)
                ->where('provider', '!=', $data['provider'])
                ->update(['is_active' => false]);

            return back()->with('success', 'House calendar mapping saved.');
        });
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
            $mapping = CalendarSyncMapping::query()->whereKey($mappingId)->first();
            abort_unless($mapping, 403);
            $this->assertCanManageSite($request, (int) $mapping->site_id);
        }
        SyncResourceCalendarsJob::dispatch($mappingId);

        return back()->with('success', 'Calendar sync queued.');
    }

    public function resetFeed(Request $request, int $mapping): RedirectResponse
    {
        $this->authorizeManage($request);
        $mapping = CalendarSyncMapping::query()
            ->whereKey($mapping)
            ->whereHas('site', fn ($site) => $site
                ->active()
                ->notArchived()
                ->whereNull('archived_at'))
            ->firstOrFail();
        $this->assertCanManageSite($request, (int) $mapping->site_id);

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

    private function assertCanManageSite(Request $request, int $siteId): void
    {
        $this->siteAccess->assertCanAccessSiteId(
            $request->user(),
            $siteId,
            self::SITE_BYPASS_PERMISSIONS,
        );

        abort_unless(
            Site::query()
                ->active()
                ->notArchived()
                ->whereNull('archived_at')
                ->whereKey($siteId)
                ->exists(),
            403,
        );
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()?->canDo('integrations.manage_secrets'), 403);
    }
}
