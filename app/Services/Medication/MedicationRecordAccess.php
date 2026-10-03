<?php

namespace App\Services\Medication;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * The one per-person gate for a medication record (eMAR P02). Every entry
 * point — the record and its views, the MAR PDF, the register filtered to a
 * person, a medicine's details, the people a list shows — answers the same:
 *
 * - 403 without medication read access (the page; it reveals nothing);
 * - 404 when the person is missing, at a house the reader can't open, or not
 *   readable under ClientPolicy::viewMedications. Access follows the person's
 *   current house, so after a move the old house gets "not found" too. The
 *   three are indistinguishable.
 */
final class MedicationRecordAccess
{
    public function __construct(
        private readonly MedicationGovernanceScopeService $scope,
        private readonly MarLinkService $links,
    ) {}

    /** The person whose record $actor opens, or abort (403 / 404). */
    public function client(User $actor, int $clientId): Client
    {
        $siteIds = $this->scope->readerSiteIds(
            $actor,
            MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
        );
        $client = Client::query()->whereIn('site_id', $siteIds)->find($clientId);
        $this->assertReadable($actor, $client);

        return $client;
    }

    /**
     * The per-person check alone, for an entry that has already applied its
     * own capability and Site scope (e.g. the PDF's reporting scope).
     *
     * @phpstan-assert Client $client
     */
    public function assertReadable(User $actor, ?Client $client): void
    {
        abort_unless(
            $client !== null && Gate::forUser($actor)->allows('viewMedications', $client),
            404,
            'The requested medication record was not found.',
        );
    }

    /**
     * A person in a report or export (the MAR PDF, the dose CSV), after the
     * entry's own reporting scope has limited the house. A report-only
     * reader (reports.viewAny / medications.reports.export without
     * medication read access) keeps that scope; anyone who reads medication
     * records also passes the per-person rule. 404 otherwise.
     *
     * @phpstan-assert Client $client
     */
    public function assertReportable(User $actor, ?Client $client): void
    {
        abort_unless($client !== null, 404, 'The requested medication record was not found.');
        if ($actor->canDo(MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY)) {
            $this->assertReadable($actor, $client);
        }
    }

    /** A medicine on a record $actor may open, or abort (403 / 404). */
    public function medication(User $actor, int $medicationId): ClientMedication
    {
        $medication = $this->scope->readableMedication($actor, $medicationId);
        $this->assertReadable($actor, $medication->client);

        return $medication;
    }

    /**
     * The people among $clientIds whose record $actor may open, so a
     * Site-scoped list shows people, not a whole house: leads and medication
     * operations roles keep the house, ordinary support workers keep the
     * residents they are assigned to or covering.
     *
     * @param  iterable<int, mixed>  $clientIds
     * @return array<int, int>
     */
    public function readableClientIds(User $actor, iterable $clientIds): array
    {
        return $this->links->openableClientIds($actor, $clientIds);
    }
}
