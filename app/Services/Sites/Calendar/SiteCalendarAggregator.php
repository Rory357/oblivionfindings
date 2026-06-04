<?php

namespace App\Services\Sites\Calendar;

use App\Models\CalendarSyncBusyBlock;
use App\Services\Sites\Calendar\Contracts\CalendarObligationProvider;
use App\Services\Sites\Calendar\Providers\AssetMaintenanceObligationProvider;
use App\Services\Sites\Calendar\Providers\ChecklistObligationProvider;
use App\Services\Sites\Calendar\Providers\ComplianceObligationProvider;
use App\Services\Sites\Calendar\Providers\CredentialReminderProvider;
use App\Services\Sites\Calendar\Providers\DamageObligationProvider;
use App\Services\Sites\Calendar\Providers\EmergencyPlanObligationProvider;
use App\Services\Sites\Calendar\Providers\HazardObligationProvider;
use App\Services\Sites\Calendar\Providers\InspectionObligationProvider;
use App\Services\Sites\Calendar\Providers\MealPlanObligationProvider;
use App\Services\Sites\Calendar\Providers\VendorReminderProvider;
use App\Services\Sites\SiteCalendarService;
use Illuminate\Support\Carbon;

/**
 * The calendar's single source of truth: unions manually-created
 * SiteCalendarEvent occurrences (RRULE-expanded by {@see SiteCalendarService})
 * with read-only obligations auto-derived from other Sites modules
 * (inspections, compliance, checklists, hazards, damages, meals, vendor &
 * credential reminders).
 *
 * Returns normalised {@see CalendarItem}s. Obligations are never persisted as
 * calendar events — each links back to its source record.
 */
class SiteCalendarAggregator
{
    private const STATUS_MAP = [
        'draft' => 'scheduled',
    ];

    /** @var CalendarObligationProvider[] */
    private array $providers;

    /**
     * @param  CalendarObligationProvider[]|null  $providers  Defaults to the full registry.
     */
    public function __construct(
        private SiteCalendarService $manual,
        ?array $providers = null,
    ) {
        $this->providers = $providers ?? self::defaultProviders();
    }

    /**
     * The full obligation-provider registry.
     *
     * @return CalendarObligationProvider[]
     */
    public static function defaultProviders(): array
    {
        return [
            new InspectionObligationProvider(),
            new ComplianceObligationProvider(),
            new ChecklistObligationProvider(),
            new HazardObligationProvider(),
            new DamageObligationProvider(),
            new MealPlanObligationProvider(),
            new VendorReminderProvider(),
            new CredentialReminderProvider(),
            new AssetMaintenanceObligationProvider(),
            new EmergencyPlanObligationProvider(),
        ];
    }

    /**
     * Unified calendar items for the sites/range.
     *
     * @param  int[]  $siteIds
     * @param  array{sources?:string[]|null, event_types?:string[]|null, user_id?:int|null}  $filters
     * @return CalendarItem[]
     */
    public function itemsForRange(array $siteIds, Carbon $start, Carbon $end, array $filters = []): array
    {
        if ($siteIds === []) {
            return [];
        }

        $sources = $filters['sources'] ?? null;
        $items = [];

        // Manual events (source key "event")
        if ($this->sourceEnabled('event', $sources)) {
            $occurrences = $this->manual->getEventsForRange(
                $siteIds,
                $filters['event_types'] ?? null,
                $start,
                $end,
                $filters['user_id'] ?? null,
            );

            foreach ($occurrences as $occurrence) {
                $items[] = $this->fromManual($occurrence);
            }
        }

        // Auto-derived obligations
        foreach ($this->providers as $provider) {
            if (! $this->sourceEnabled($provider->sourceKey(), $sources)) {
                continue;
            }

            foreach ($provider->obligations($siteIds, $start, $end) as $item) {
                $items[] = $item;
            }
        }

        // External busy blocks pulled from two-way mapped resource calendars (Part D).
        if ($this->sourceEnabled('external', $sources)) {
            foreach ($this->externalBusy($siteIds, $start, $end) as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Read-only "external" busy blocks overlapping the range, normalised to
     * {@see CalendarItem}s. Pulled from resource calendars by the two-way sync.
     *
     * @param  int[]  $siteIds
     * @return CalendarItem[]
     */
    private function externalBusy(array $siteIds, Carbon $start, Carbon $end): array
    {
        $blocks = CalendarSyncBusyBlock::query()
            ->with('site:id,name,type')
            ->whereIn('site_id', $siteIds)
            ->where('is_busy', true)
            ->where('starts_at', '<', $end)
            ->where(function ($q) use ($start) {
                $q->where('ends_at', '>', $start)
                    ->orWhere(function ($q2) use ($start) {
                        $q2->whereNull('ends_at')->where('starts_at', '>=', $start);
                    });
            })
            ->orderBy('starts_at')
            ->get();

        return $blocks->map(function (CalendarSyncBusyBlock $block) {
            $site = $block->site;

            return new CalendarItem(
                id: 'external-'.$block->id,
                source: 'external',
                group: 'auto',
                title: $block->title ?: 'Busy',
                start: optional($block->starts_at)->toIso8601String(),
                end: optional($block->ends_at)->toIso8601String(),
                allDay: (bool) $block->all_day,
                status: 'scheduled',
                site: $site ? ['id' => $site->id, 'name' => $site->name, 'type' => $site->type] : null,
                editable: false,
            );
        })->all();
    }

    /**
     * As {@see itemsForRange()} but pre-serialised for a JSON response.
     *
     * @param  int[]  $siteIds
     * @return array<int, array<string, mixed>>
     */
    public function arrayForRange(array $siteIds, Carbon $start, Carbon $end, array $filters = []): array
    {
        return array_map(
            fn (CalendarItem $item) => $item->toArray(),
            $this->itemsForRange($siteIds, $start, $end, $filters),
        );
    }

    /**
     * @param  string[]|null  $sources
     */
    private function sourceEnabled(string $key, ?array $sources): bool
    {
        return $sources === null || $sources === [] || in_array($key, $sources, true);
    }

    private function fromManual(array $occ): CalendarItem
    {
        $site = $occ['site'] ?? null;
        $owner = $occ['owner'] ?? null;

        $status = $occ['status'] ?? 'scheduled';
        $status = self::STATUS_MAP[$status] ?? $status;
        if (($occ['approval_status'] ?? null) === 'pending') {
            $status = 'pending';
        }

        return new CalendarItem(
            id: (string) ($occ['occ_id'] ?? $occ['id']),
            source: 'event',
            group: 'manual',
            title: $occ['title'] ?? 'Untitled',
            start: $occ['start_at'] ?? null,
            end: $occ['end_at'] ?? null,
            allDay: (bool) ($occ['all_day'] ?? false),
            status: $status,
            owner: $owner ? ['id' => $owner->id, 'name' => $owner->name] : null,
            room: $occ['room'] ?? null,
            site: $site ? ['id' => $site->id, 'name' => $site->name, 'type' => $site->type] : null,
            link: isset($site) ? "/sites/{$site->id}/calendar" : null,
            editable: true,
            eventType: $occ['event_type'] ?? null,
            approvalStatus: $occ['approval_status'] ?? null,
            desc: $occ['description'] ?? null,
            recurrence: $this->parseRrule($occ['recurrence_rule'] ?? null),
            reminders: $occ['reminder_minutes'] ?? [],
            attendeeIds: array_map('intval', $occ['attendee_user_ids'] ?? []),
            seriesId: $occ['series_id'] ?? ($occ['id'] ?? null),
            isOccurrence: (bool) ($occ['is_occurrence'] ?? false),
        );
    }

    /**
     * Minimal RFC-5545 parse to the {freq, interval, count?, until?} object the
     * frontend expects (cal-recur.js shape).
     */
    private function parseRrule(?string $rrule): ?array
    {
        if (! $rrule) {
            return null;
        }

        $parts = [];
        foreach (explode(';', $rrule) as $segment) {
            [$key, $value] = array_pad(explode('=', $segment, 2), 2, null);
            if ($key !== null && $key !== '') {
                $parts[strtoupper($key)] = $value;
            }
        }

        if (empty($parts['FREQ'])) {
            return null;
        }

        $rule = [
            'freq' => strtoupper($parts['FREQ']),
            'interval' => isset($parts['INTERVAL']) ? max(1, (int) $parts['INTERVAL']) : 1,
        ];

        if (isset($parts['COUNT'])) {
            $rule['count'] = (int) $parts['COUNT'];
        }
        if (isset($parts['UNTIL'])) {
            $rule['until'] = $parts['UNTIL'];
        }

        return $rule;
    }
}
