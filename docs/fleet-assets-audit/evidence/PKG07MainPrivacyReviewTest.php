<?php

// Run from the exact PKG-07 candidate checkout. Keep application source untouched.
require_once getcwd().'/tests/Feature/FleetAssets/Pkg07MapPrivacyTest.php';

final class PKG07MainPrivacyReviewTest extends \Tests\Feature\FleetAssets\Pkg07MapPrivacyTest
{
    protected function configureIsolatedTestingDatabase(): void
    {
        static::$testDatabaseBaseName = 'of_main07_privacy_20260927';
        parent::configureIsolatedTestingDatabase();
    }

    protected function pruneStaleIsolatedDatabases(PDO $pdo, string $baseName, string $currentDatabase): void {}

    protected function clearTestingMaintenanceMode(): void {}

    public function test_directory_and_history_withhold_private_vehicle_evidence_even_without_a_trip_link(): void
    {
        parent::test_directory_and_history_withhold_private_vehicle_evidence_even_without_a_trip_link();

        // The owner's fixture ends with consent_blocked=true on the source event,
        // and confirms null positions on both maps while retaining its FleetSignal.
        $boundary = \App\Models\AssetGeofence::query()->where('name', 'Privacy test area')->sole();
        $events = $this->getJson('/fleet-assets/geofences/events?boundary_id='.$boundary->id)->assertOk();
        $summary = $this->getJson('/fleet-assets/geofences/summary?site_id='.$boundary->site_id)->assertOk();
        $followUp = $this->getJson('/fleet-assets/geofences/events?boundary_id='.$boundary->id.'&follow_up=1')->assertOk();
        $this->assertSame(
            ['events' => 0, 'follow_up' => 0, 'follow_up_events' => 0],
            ['events' => $events->json('total'), 'follow_up' => $summary->json('follow_up'), 'follow_up_events' => $followUp->json('total')],
            'Consent-blocked location evidence must also be withheld from boundary history and follow-up counts.'
        );
    }
}
