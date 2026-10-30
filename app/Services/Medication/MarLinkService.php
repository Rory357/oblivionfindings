<?php

namespace App\Services\Medication;

use App\Models\Client;
use App\Models\User;
use App\Support\EmarUrl;
use App\Support\MedicationJourney;
use Illuminate\Support\Facades\Gate;

/**
 * "Open MAR" links for rows on Site- or roster-scoped surfaces. A row gets a
 * link only when its viewer may open that resident's chart
 * (ClientPolicy::viewMedications) — never one that 404s. Decisions are
 * memoised per viewer and client for this instance's lifetime (one request).
 */
final class MarLinkService
{
    /** @var array<string, bool> */
    private array $openable = [];

    /** @var array<int, int|null> */
    private array $clientSiteIds = [];

    public function urlFor(?User $viewer, mixed $clientId, ?string $date = null, array $context = []): ?string
    {
        $clientId = is_numeric($clientId) ? (int) $clientId : 0;
        if ($viewer === null || $clientId <= 0) {
            return null;
        }

        if (! $this->canOpen($viewer, $clientId)) {
            return null;
        }
        $siteId = filter_var($context['site_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($siteId !== false && $siteId !== ($this->clientSiteIds[$clientId] ?? null)) {
            return null;
        }

        return EmarUrl::mar($clientId, $date, [
            'site_id' => $siteId !== false ? $siteId : null,
            'return_to' => MedicationJourney::returnTo($context['return_to'] ?? null),
        ]);
    }

    public function canOpen(User $viewer, int $clientId): bool
    {
        $key = $viewer->getKey().':'.$clientId;
        if (! array_key_exists($key, $this->openable)) {
            $client = Client::query()->find($clientId);
            $this->clientSiteIds[$clientId] = $client?->site_id !== null ? (int) $client->site_id : null;
            $this->openable[$key] = $client !== null
                && Gate::forUser($viewer)->allows('viewMedications', $client);
        }

        return $this->openable[$key];
    }

    /**
     * The subset of $clientIds whose chart this viewer may open, so a
     * Site-scoped list can be narrowed to people: leads and medication
     * operations roles keep the whole Site, ordinary support workers keep the
     * residents they are assigned to or covering. Loads the unknown clients in
     * one query and seeds the memo, so the rows' urlFor() calls cost nothing.
     *
     * @param  iterable<int, mixed>  $clientIds
     * @return array<int, int>
     */
    public function openableClientIds(User $viewer, iterable $clientIds): array
    {
        $ids = collect($clientIds)
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $key = fn (int $id): string => $viewer->getKey().':'.$id;

        $unknown = $ids->reject(fn (int $id): bool => array_key_exists($key($id), $this->openable));
        if ($unknown->isNotEmpty()) {
            $clients = Client::query()
                ->whereIn('id', $unknown->all())
                ->get()
                ->keyBy(fn (Client $client): int => (int) $client->id);
            foreach ($unknown as $id) {
                $client = $clients->get($id);
                $this->clientSiteIds[$id] = $client?->site_id !== null ? (int) $client->site_id : null;
                $this->openable[$key($id)] = $client !== null
                    && Gate::forUser($viewer)->allows('viewMedications', $client);
            }
        }

        return $ids->filter(fn (int $id): bool => $this->openable[$key($id)])->values()->all();
    }
}
