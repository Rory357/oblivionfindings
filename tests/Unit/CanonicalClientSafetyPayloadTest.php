<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\ClientMedicalProfile;
use App\Models\ClientRisk;
use App\Models\MedicationAllergy;
use App\Models\User;
use App\Services\Clients\ClientProfileSectionAccess;
use App\Services\Medication\ClientAllergyRecordService;
use App\Support\ClientSafetyPayload;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

class CanonicalClientSafetyPayloadTest extends TestCase
{
    public function test_ribbon_and_list_use_current_canonical_and_uncopied_entries_without_resurrecting_removed_evidence(): void
    {
        $profile = $this->profile([
            'allergies' => ['penicillin'],
            'allergies_canonical_at' => '2026-10-08 01:00:00',
            'allergy_records' => [
                ['key' => 'register-7', 'allergen' => 'Penicillin', 'removed_at' => '2026-10-08T01:00:00Z', 'source_register_ids' => [7]],
                ['key' => 'current-peanuts', 'allergen' => 'Peanuts', 'severity' => 'life_threatening', 'reaction' => 'Anaphylaxis'],
            ],
        ]);
        $removed = new MedicationAllergy(['allergen' => 'Penicillin', 'severity' => 'severe']);
        $removed->id = 7;
        $uncopied = new MedicationAllergy(['allergen' => 'Latex', 'severity' => 'moderate', 'reaction' => 'Rash']);
        $uncopied->id = 8;
        $record = (new ClientAllergyRecordService)->summaryFromEvidence(42, collect([$removed, $uncopied]), $profile);
        $client = $this->client($profile);
        $full = ClientSafetyPayload::forClient($client, includeRisks: false, allergyRecord: $record);
        $summary = ClientSafetyPayload::summaryForClient($client, includeRisks: false, allergyRecord: $record);

        $this->assertSame(['Peanuts', 'Latex'], array_column($full['allergies'], 'label'));
        $this->assertSame('current-peanuts', $full['allergies'][0]['key']);
        $this->assertSame('Food', $full['allergies'][0]['group']);
        $this->assertSame('life_threatening', $full['allergies'][0]['severity']);
        $this->assertSame('Anaphylaxis', $full['allergies'][0]['reaction']);
        $this->assertSame('recorded', $record['status']);
        $this->assertNull($record['reviewed']);
        $this->assertSame($record, $full['allergy_record']);
        $this->assertSame($record, $summary['allergy_record']);
        $this->assertSame(2, $summary['allergies_count']);
        $this->assertSame('Peanuts', $summary['top_allergy']);
        $this->assertSame(['penicillin'], $profile->allergies);
        $this->assertSame('Penicillin', $removed->allergen);
    }

    public function test_reviewed_no_known_is_distinct_from_unreviewed_empty_and_a_stale_review(): void
    {
        $records = new ClientAllergyRecordService;
        $profile = $this->profile([
            'allergies' => ['penicillin'], 'allergies_canonical_at' => '2026-10-08 01:00:00',
            'allergies_review_status' => 'no_known', 'allergies_reviewed_at' => '2026-10-08 01:00:00',
            'allergies_review_digest' => $records->digest([]), 'allergies_review_method' => 'lead review',
        ]);
        $record = $records->summaryFromEvidence(42, collect(), $profile, 'Clinical lead');
        $full = ClientSafetyPayload::forClient($this->client($profile), includeRisks: false, allergyRecord: $record);
        $this->assertSame([], $full['allergies']);
        $this->assertSame('no_known', $full['allergy_record']['status']);
        $this->assertSame('Clinical lead', $full['allergy_record']['reviewed']['by']);
        $this->assertSame('lead review', $full['allergy_record']['reviewed']['how']);

        $profile->allergies_review_digest = 'stale';
        $stale = ClientSafetyPayload::forClient($this->client($profile), includeRisks: false);
        $this->assertSame('none', $stale['allergy_record']['status']);
        $this->assertNull($stale['allergy_record']['reviewed']);
        $this->assertSame([], $stale['allergies']);
        $empty = ClientSafetyPayload::forClient($this->client(null), includeRisks: false);
        $this->assertSame('none', $empty['allergy_record']['status']);
        $this->assertNull($empty['allergy_record']['reviewed']);
    }

    public function test_medical_denial_omits_even_an_explicitly_supplied_canonical_projection(): void
    {
        $record = ['status' => 'recorded', 'entries' => [
            ['key' => 'secret', 'allergen' => 'Peanuts', 'severity' => 'severe', 'reaction' => 'Anaphylaxis'],
        ], 'reviewed' => null, 'digest' => 'secret'];
        $client = $this->client($this->profile(['allergies' => ['penicillin'], 'disabilities' => ['epilepsy']]));
        $full = ClientSafetyPayload::forClient($client, includeMedical: false, includeRisks: false, allergyRecord: $record);
        $summary = ClientSafetyPayload::summaryForClient($client, includeMedical: false, includeRisks: false, allergyRecord: $record);
        $this->assertSame([], $full['allergies']);
        $this->assertNull($full['allergy_record']);
        $this->assertSame([], $full['care_flags']);
        $this->assertNull($summary['allergy_record']);
        $this->assertSame(0, $summary['allergies_count']);
        $this->assertNull($summary['top_allergy']);
    }

    public function test_uncopied_intake_labels_use_the_same_canonical_projection_without_claiming_a_review(): void
    {
        $profile = $this->profile(['allergies' => ['aspirin', 'Free-text allergen']]);
        $full = ClientSafetyPayload::forClient($this->client($profile), includeRisks: false);
        $this->assertSame(['Aspirin / NSAIDs', 'Free-text allergen'], array_column($full['allergies'], 'label'));
        $this->assertSame('recorded', $full['allergy_record']['status']);
        $this->assertNull($full['allergy_record']['reviewed']);
        $this->assertSame('health_profile', $full['allergy_record']['entries'][0]['source']);
        $this->assertSame(['aspirin', 'Free-text allergen'], $profile->allergies);
    }

    public function test_shift_and_risk_reader_projection_requires_the_current_exact_medication_person_gate(): void
    {
        $client = $this->client($this->profile([
            'allergies_canonical_at' => '2026-10-08 01:00:00',
            'allergy_records' => [['key' => 'current', 'allergen' => 'Peanuts', 'severity' => 'severe']],
        ]));
        $client->setRelation('risks', collect([new ClientRisk(['label' => 'Authorized risk', 'severity' => 'critical', 'active' => true])]));
        $viewer = new CanonicalSafetyReaderDouble(false);
        $sections = $this->createMock(ClientProfileSectionAccess::class);
        $sections->expects($this->once())->method('for')->with($viewer, $client)
            ->willReturn(['medical' => true, 'risks' => true]);
        $container = Container::getInstance();
        $isolated = new Container;
        $isolated->instance(ClientProfileSectionAccess::class, $sections);
        Container::setInstance($isolated);
        try {
            $payload = ClientSafetyPayload::forViewer($client, $viewer);
        } finally {
            Container::setInstance($container);
        }
        $this->assertSame([['viewMedications', $client]], $viewer->checked);
        $this->assertSame([], $payload['allergies']);
        $this->assertNull($payload['allergy_record']);
        $this->assertSame('Authorized risk', $payload['critical_risks'][0]['label']);
    }

    public function test_reader_projection_preserves_a_medical_grant_without_granting_risk_reads(): void
    {
        $client = $this->client($this->profile([
            'allergies_canonical_at' => '2026-10-08 01:00:00',
            'allergy_records' => [['key' => 'current', 'allergen' => 'Peanuts', 'severity' => 'severe']],
        ]));
        $client->setRelation('risks', collect([new ClientRisk(['label' => 'Restricted risk', 'severity' => 'critical', 'active' => true])]));
        $viewer = new CanonicalSafetyReaderDouble(true);
        $sections = $this->createMock(ClientProfileSectionAccess::class);
        $sections->expects($this->once())->method('for')->with($viewer, $client)
            ->willReturn(['medical' => true, 'risks' => false]);
        $container = Container::getInstance();
        $isolated = new Container;
        $isolated->instance(ClientProfileSectionAccess::class, $sections);
        Container::setInstance($isolated);
        try {
            $payload = ClientSafetyPayload::forViewer($client, $viewer);
        } finally {
            Container::setInstance($container);
        }
        $this->assertSame([['viewMedications', $client]], $viewer->checked);
        $this->assertSame('Peanuts', $payload['allergies'][0]['label']);
        $this->assertSame('severe', $payload['allergy_record']['entries'][0]['severity']);
        $this->assertSame([], $payload['critical_risks']);
        $this->assertNull($payload['risk_level']);
    }

    private function profile(array $attributes): ClientMedicalProfile
    {
        $profile = new ClientMedicalProfile;
        $profile->setDateFormat('Y-m-d H:i:s');
        $profile->fill($attributes);

        return $profile;
    }

    private function client(?ClientMedicalProfile $profile): Client
    {
        $client = new Client;
        $client->id = 42;
        $client->setRelation('medicalProfile', $profile);
        $client->setRelation('risks', collect());

        return $client;
    }
}
class CanonicalSafetyReaderDouble extends User
{
    public array $checked = [];

    public function __construct(private readonly bool $canViewMedications = false)
    {
        parent::__construct();
    }

    public function can($abilities, $arguments = []): bool
    {
        $this->checked[] = [$abilities, $arguments];

        return $abilities === 'viewMedications' && $arguments instanceof Client
            && (int) $arguments->id === 42 && $this->canViewMedications;
    }
}
