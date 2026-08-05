<?php

namespace App\Domain\It\Services;

use App\Models\ItSavedTicketFilter;
use App\Models\ItTicket;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Owns the allow-list for personal ticket queue filters.
 *
 * Saved JSON is never trusted as an authorisation decision. Every write,
 * render and application is re-normalised against the viewer's current Site
 * and option access, so revoked access cannot survive in a personal filter.
 */
final class ItSavedTicketFilterService
{
    public const PREDEFINED_VIEWS = [
        'all_open' => 'All open',
        'unassigned' => 'Unassigned',
        'mine' => 'Mine',
        'owned_by_me' => 'Owned by me',
        'my_team' => "My team's work",
        'breaching' => 'Breaching soon',
        'breached' => 'Breached',
        'awaiting_reply' => 'Awaiting reply',
        'waiting' => 'All waiting work',
        'recently_resolved' => 'Recently resolved',
    ];

    private ?array $siteIds = null;

    private ?array $agentIds = null;

    private ?array $serviceIds = null;

    public function __construct(
        private readonly ItWorkAccessService $workAccess,
        private readonly ItLinkedContextOptions $options,
    ) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function sanitize(User $user, array $filters): array
    {
        $clean = [];

        $this->copyEnum($clean, $filters, 'view', array_keys(self::PREDEFINED_VIEWS));
        $this->copyEnum($clean, $filters, 'ticket_status', ItTicket::STATUSES);
        $this->copyEnum($clean, $filters, 'ticket_priority', ItTicket::PRIORITIES);
        $this->copyEnum($clean, $filters, 'ticket_category', ItTicket::CATEGORIES);
        $this->copyEnum($clean, $filters, 'source', ItTicket::SOURCES);
        $this->copyEnum($clean, $filters, 'work_type', ItTicket::WORK_TYPES);
        $this->copyEnum($clean, $filters, 'sla', ItTicket::SLA_STATES);
        $this->copyEnum($clean, $filters, 'age', ['under_2', '2_7', '8_30', 'over_30']);
        $this->copyEnum($clean, $filters, 'missing', ['service', 'queue', 'team', 'assignee']);
        $this->copyEnum($clean, $filters, 'sort', ['reference', 'created', 'updated', 'priority', 'status']);
        $this->copyEnum($clean, $filters, 'dir', ['asc', 'desc']);

        if (array_key_exists('site_id', $filters)) {
            $this->copyAllowedId($clean, $filters, 'site_id', $this->allowedSiteIds($user));
        }
        if (array_key_exists('assignee', $filters)) {
            $this->copyAllowedId($clean, $filters, 'assignee', $this->allowedAgentIds($user));
        }
        if (array_key_exists('service', $filters)) {
            $this->copyAllowedId($clean, $filters, 'service', $this->allowedServiceIds());
        }

        foreach (['reopened', 'first_contact', 'open_only', 'device_linked'] as $key) {
            if (($filters[$key] ?? false) === true || ($filters[$key] ?? null) === 1 || ($filters[$key] ?? null) === '1') {
                $clean[$key] = true;
            }
        }

        foreach (['from', 'to', 'resolved_from', 'resolved_to'] as $key) {
            $date = $this->cleanDate($filters[$key] ?? null);
            if ($date !== null) {
                $clean[$key] = $date;
            }
        }

        $rawSearch = $filters['q'] ?? null;
        $search = is_string($rawSearch) || is_numeric($rawSearch)
            ? trim((string) $rawSearch)
            : '';
        if ($search !== '') {
            $clean['q'] = mb_substr($search, 0, 150);
        }

        return $clean;
    }

    /**
     * Return only safe display metadata and prune options that became
     * inaccessible since the filter was saved.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function ownedRows(User $user): array
    {
        if (! Schema::hasTable('it_saved_ticket_filters')) {
            return [];
        }

        return ItSavedTicketFilter::query()
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(function (ItSavedTicketFilter $savedFilter) use ($user): array {
                $safe = $this->sanitize($user, (array) $savedFilter->filters);
                if ($safe !== $savedFilter->filters) {
                    $savedFilter->forceFill(['filters' => $safe])->saveQuietly();
                }

                return [
                    'id' => (int) $savedFilter->id,
                    'name' => $savedFilter->name,
                ];
            })
            ->all();
    }

    private function copyEnum(array &$clean, array $filters, string $key, array $allowed): void
    {
        $value = $filters[$key] ?? null;
        if (is_string($value) && in_array($value, $allowed, true)) {
            $clean[$key] = $value;
        }
    }

    /** @param list<int> $allowed */
    private function copyAllowedId(array &$clean, array $filters, string $key, array $allowed): void
    {
        $value = $filters[$key] ?? null;
        if (is_numeric($value) && in_array((int) $value, $allowed, true)) {
            $clean[$key] = (int) $value;
        }
    }

    private function cleanDate(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            return $date && $date->format('Y-m-d') === $value ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<int> */
    private function allowedSiteIds(User $user): array
    {
        return $this->siteIds ??= $this->workAccess->approvedSiteIds($user);
    }

    /** @return list<int> */
    private function allowedAgentIds(User $user): array
    {
        return $this->agentIds ??= array_map(
            static fn (array $agent): int => (int) $agent['id'],
            $this->options->agents($user),
        );
    }

    /** @return list<int> */
    private function allowedServiceIds(): array
    {
        return $this->serviceIds ??= array_map(
            static fn (array $service): int => (int) $service['id'],
            $this->options->services(),
        );
    }
}
