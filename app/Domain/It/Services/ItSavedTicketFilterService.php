<?php

namespace App\Domain\It\Services;

use App\Models\ItSavedTicketFilter;
use App\Models\ItTicket;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
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
        'unowned' => 'Unowned',
        'mine' => 'Mine',
        'owned_by_me' => 'Owned by me',
        'my_team' => "My team's work",
        'breaching' => 'Breaching soon',
        'breached' => 'Breached',
        'awaiting_it' => 'Awaiting IT',
        'awaiting_reply' => 'Awaiting first reply',
        'waiting' => 'All waiting work',
        'waiting_requester' => 'Awaiting requester',
        'waiting_vendor' => 'Awaiting vendor',
        'waiting_approver' => 'Awaiting approval',
        'unmeasured' => 'Unmeasured',
        'recently_resolved' => 'Recently resolved',
    ];

    public function __construct(
        private readonly ItWorkAccessService $workAccess,
        private readonly ItLinkedContextOptions $options,
    ) {}

    /** @param array<string, mixed> $filters */
    public function store(User $actor, string $name, array $filters): ItSavedTicketFilter
    {
        try {
            return DB::transaction(function () use ($actor, $name, $filters): ItSavedTicketFilter {
                // All saves for one user serialize on a stable parent row;
                // locking existing filters cannot protect an empty collection.
                $current = User::query()->whereKey($actor->getKey())->lockForUpdate()->first();
                if (! $current || $current->approved_at === null || ! $current->canDo('it.view')) {
                    throw new AuthorizationException('Your ticket view access is no longer available.');
                }

                $name = trim($name);
                Validator::make(['name' => $name], ['name' => ['required', 'string', 'max:80']])->validate();
                $owned = ItSavedTicketFilter::query()->where('user_id', $current->id);
                if ((clone $owned)->where('name', $name)->exists()) {
                    throw ValidationException::withMessages(['name' => 'You already have a ticket view with this name.']);
                }
                if ($owned->count() >= 25) {
                    throw ValidationException::withMessages([
                        'filters' => 'You can keep up to 25 personal ticket filters. Delete one before saving another.',
                    ]);
                }
                $safe = $this->sanitize($current, $filters);
                if ($safe === []) {
                    throw ValidationException::withMessages(['filters' => 'Choose at least one ticket filter before saving this view.']);
                }

                return ItSavedTicketFilter::query()->create([
                    'user_id' => $current->id, 'name' => $name, 'filters' => $safe,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // The database remains the last uniqueness guard if another
            // canonical caller races a compatibility path.
            throw ValidationException::withMessages(['name' => 'You already have a ticket view with this name.']);
        }
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function sanitize(User $user, array $filters): array
    {
        return $this->sanitizeWithOptions($filters, $this->currentOptions($user, array_keys($filters)));
    }

    /** @param array<string, mixed> $filters @param array<string, list<int>> $options */
    private function sanitizeWithOptions(array $filters, array $options): array
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
            $this->copyAllowedId($clean, $filters, 'site_id', $options['site_id'] ?? []);
        }
        if (array_key_exists('assignee', $filters)) {
            $this->copyAllowedId($clean, $filters, 'assignee', $options['assignee'] ?? []);
        }
        if (array_key_exists('service', $filters)) {
            $this->copyAllowedId($clean, $filters, 'service', $options['service'] ?? []);
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

        $savedFilters = ItSavedTicketFilter::query()
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->orderBy('id')
            ->get();
        // Resolve once for this operation, never retain a permission-sensitive
        // option set on the service across actors or later requests.
        $keys = $savedFilters->flatMap(fn (ItSavedTicketFilter $saved): array => array_keys((array) $saved->filters))
            ->unique()->all();
        $options = $this->currentOptions($user, $keys);

        return $savedFilters->map(function (ItSavedTicketFilter $savedFilter) use ($options): array {
            $safe = $this->sanitizeWithOptions((array) $savedFilter->filters, $options);
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

    /** @param list<string> $keys @return array<string, list<int>> */
    private function currentOptions(User $user, array $keys): array
    {
        $options = [];
        if (in_array('site_id', $keys, true)) {
            $options['site_id'] = $this->workAccess->approvedSiteIds($user);
        }
        if (in_array('assignee', $keys, true)) {
            $options['assignee'] = array_map(static fn (array $agent): int => (int) $agent['id'], $this->options->agents($user));
        }
        if (in_array('service', $keys, true)) {
            $options['service'] = array_map(static fn (array $service): int => (int) $service['id'], $this->options->services());
        }

        return $options;
    }
}
