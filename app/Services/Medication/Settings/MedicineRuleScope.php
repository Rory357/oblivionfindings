<?php

namespace App\Services\Medication\Settings;

use App\Models\ClientMedication;
use App\Models\MedicationAdminRule;
use App\Models\User;
use App\Services\Medication\MarLinkService;
use App\Services\MedicationRuleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who and what a medicine rule touches, as a given person may see it (eMAR
 * P11 B1 chunk 3).
 *
 * - A rule is "controlled" when it names a controlled medicine: a name or
 *   product rule that matches a current controlled order. Its details are
 *   concealed from anyone without controlled-medicine access (EM-12). The
 *   "any controlled medicine" match and route rules name no medicine.
 * - The preview and the overlap warning use the same person scope as MAR &
 *   medicines: current orders at the houses this person can see, for people
 *   whose chart they can open, without controlled orders unless they can see
 *   them. Matching is the same test doses use.
 */
class MedicineRuleScope
{
    public const PREVIEW_ROWS = 50;

    public function __construct(
        private readonly MedicationRuleService $rules,
        private readonly MarLinkService $marLinks,
    ) {}

    public function canSeeControlled(User $viewer): bool
    {
        return $viewer->canDo('medications.controlled.view');
    }

    /**
     * Ids of the rules that name a controlled medicine.
     *
     * @param  iterable<int, MedicationAdminRule>  $rules
     * @return list<int>
     */
    public function controlledRuleIds(iterable $rules): array
    {
        $candidates = collect($rules)
            ->filter(fn (MedicationAdminRule $rule): bool => in_array($rule->match_type, ['medicine_name', 'nzulm_code'], true));
        if ($candidates->isEmpty()) {
            return [];
        }
        $controlled = ClientMedication::query()
            ->active()
            ->where('controlled_drug', true)
            ->get(['id', 'name', 'route', 'nzulm_code', 'controlled_drug']);

        return $candidates
            ->filter(fn (MedicationAdminRule $rule): bool => $controlled->contains(
                fn (ClientMedication $order): bool => $this->rules->matchesOrder($rule, $order),
            ))
            ->map(fn (MedicationAdminRule $rule): int => (int) $rule->id)
            ->values()
            ->all();
    }

    public function isControlled(MedicationAdminRule $rule): bool
    {
        return $this->controlledRuleIds([$rule]) !== [];
    }

    /**
     * Current orders the viewer may see, at the given houses (null = every
     * house), for people whose chart they can open.
     *
     * @param  list<int>|null  $siteIds
     * @return Collection<int, ClientMedication>
     */
    public function visibleOrders(User $viewer, ?array $siteIds): Collection
    {
        if ($siteIds === []) {
            return collect();
        }
        $orders = ClientMedication::query()
            ->active()
            ->with(['client:id,first_name,last_name,preferred_name,site_id', 'client.site:id,name'])
            ->whereHas('client', fn (Builder $client) => $client
                ->when($siteIds !== null, fn (Builder $scoped) => $scoped->whereIn('site_id', $siteIds)))
            ->when(! $this->canSeeControlled($viewer), fn (Builder $query) => $query->where('controlled_drug', false))
            ->orderBy('id')
            ->get(['id', 'client_id', 'name', 'route', 'nzulm_code', 'controlled_drug']);
        $openable = array_flip($this->marLinks->openableClientIds($viewer, $orders->pluck('client_id')));

        return $orders
            ->filter(fn (ClientMedication $order): bool => isset($openable[(int) $order->client_id]))
            ->values();
    }

    /**
     * The visible orders this rule applies to (its house, or every house).
     *
     * @param  Collection<int, ClientMedication>  $orders
     * @return Collection<int, ClientMedication>
     */
    public function matchesIn(Collection $orders, MedicationAdminRule $rule): Collection
    {
        return $orders
            ->filter(fn (ClientMedication $order): bool => ($rule->site_id === null || (int) $order->client?->site_id === (int) $rule->site_id)
                && $this->rules->matchesOrder($rule, $order))
            ->values();
    }

    /**
     * "Would apply now to N medicines for M people", listing person, medicine
     * and house. Only what this viewer may see is counted.
     *
     * @param  Collection<int, ClientMedication>  $orders
     * @return array{medicines: int, people: int, rows: list<array{person: string, medicine: string, house: string|null}>, more: int}
     */
    public function preview(Collection $orders, MedicationAdminRule $rule): array
    {
        $matches = $this->matchesIn($orders, $rule)
            ->sortBy(fn (ClientMedication $order): string => mb_strtolower($this->personName($order).' '.$order->name))
            ->values();

        return [
            'medicines' => $matches->pluck('name')->map(fn (mixed $name): string => mb_strtolower(trim((string) $name)))->unique()->count(),
            'people' => $matches->pluck('client_id')->unique()->count(),
            'rows' => $matches->take(self::PREVIEW_ROWS)->map(fn (ClientMedication $order): array => [
                'person' => $this->personName($order),
                'medicine' => (string) $order->name,
                'house' => $order->client?->site?->name,
            ])->all(),
            'more' => max(0, $matches->count() - self::PREVIEW_ROWS),
        ];
    }

    /**
     * Ids of the other active rules that apply to at least one of the same
     * visible orders as this one: both apply there.
     *
     * @param  Collection<int, ClientMedication>  $orders
     * @param  iterable<int, MedicationAdminRule>  $others
     * @return list<int>
     */
    public function overlaps(Collection $orders, MedicationAdminRule $rule, iterable $others): array
    {
        $mine = $this->matchesIn($orders, $rule)->pluck('id')->flip();
        if ($mine->isEmpty()) {
            return [];
        }

        return collect($others)
            ->filter(fn (MedicationAdminRule $other): bool => $other->active
                && (int) $other->id !== (int) $rule->id
                && $this->matchesIn($orders, $other)->contains(fn (ClientMedication $order): bool => $mine->has($order->id)))
            ->map(fn (MedicationAdminRule $other): int => (int) $other->id)
            ->values()
            ->all();
    }

    private function personName(ClientMedication $order): string
    {
        $client = $order->client;
        if ($client === null) {
            return 'Unknown person';
        }

        return trim(((string) ($client->preferred_name ?: $client->first_name)).' '.$client->last_name);
    }
}
