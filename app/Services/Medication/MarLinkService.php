<?php

namespace App\Services\Medication;

use App\Models\Client;
use App\Models\User;
use App\Support\EmarUrl;
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

    public function urlFor(?User $viewer, mixed $clientId, ?string $date = null): ?string
    {
        $clientId = is_numeric($clientId) ? (int) $clientId : 0;
        if ($viewer === null || $clientId <= 0) {
            return null;
        }

        return $this->canOpen($viewer, $clientId) ? EmarUrl::mar($clientId, $date) : null;
    }

    public function canOpen(User $viewer, int $clientId): bool
    {
        $key = $viewer->getKey().':'.$clientId;
        if (! array_key_exists($key, $this->openable)) {
            $client = Client::query()->find($clientId);
            $this->openable[$key] = $client !== null
                && Gate::forUser($viewer)->allows('viewMedications', $client);
        }

        return $this->openable[$key];
    }
}
