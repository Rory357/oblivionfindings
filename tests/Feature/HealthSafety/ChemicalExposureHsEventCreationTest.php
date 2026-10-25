<?php

namespace Tests\Feature\HealthSafety;

use App\Models\HazardousSubstance;
use App\Models\HsEvent;
use App\Models\Site;
use App\Models\SubstanceExposureRecord;
use App\Models\User;
use App\Observers\SubstanceExposureRecordObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Main's exposure-to-H&S cases, restored (25 Oct): a recorded chemical exposure reaches the H&S register and its
 * WorkSafe review straight away. Codex's delivery consumer is not mounted in this build, so without the observer no
 * new exposure would ever become an H&S event.
 */
class ChemicalExposureHsEventCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_substance_exposure_record_creates_exposure_hs_event(): void
    {
        $site = Site::factory()->create();
        $worker = User::factory()->create();
        $creator = User::factory()->create();
        $substance = HazardousSubstance::factory()->create(['created_by' => $creator->id]);

        $record = SubstanceExposureRecord::create([
            'hazardous_substance_id' => $substance->id,
            'user_id' => $worker->id,
            'site_id' => $site->id,
            'exposed_at' => now()->subHour(),
            'exposure_type' => 'inhalation',
            'circumstances' => 'Cleaner vapour exposure during storage-room spill response.',
            'medical_attention_sought' => true,
            'incident_reported' => false,
            'created_by' => $creator->id,
        ]);

        $this->assertDatabaseHas('hs_events', [
            'source_type' => SubstanceExposureRecord::class,
            'source_id' => $record->id,
            'event_category' => HsEvent::CATEGORY_EXPOSURE,
            'severity' => HsEvent::SEVERITY_HIGH,
            'site_id' => $site->id,
            'staff_id' => $worker->id,
            'created_by' => $creator->id,
            'investigation_required' => true,
        ]);
    }

    public function test_exposure_observer_escalates_hospitalisation_for_qualified_worksafe_review(): void
    {
        $substance = HazardousSubstance::factory()->create();
        $record = SubstanceExposureRecord::factory()->notifiable()->create(['hazardous_substance_id' => $substance->id]);

        app(SubstanceExposureRecordObserver::class)->created($record);

        $event = HsEvent::where('source_type', SubstanceExposureRecord::class)->where('source_id', $record->id)->first();
        $this->assertNotNull($event);
        $this->assertSame(HsEvent::CATEGORY_EXPOSURE, $event->event_category);
        $this->assertNull($event->worksafe_notifiable);
        $this->assertNull($event->worksafe_decided_at);
        $this->assertNull($event->worksafe_decided_by_user_id);
        $this->assertSame(HsEvent::SEVERITY_HIGH, $event->severity);
        $this->assertSame(1, HsEvent::where('source_type', SubstanceExposureRecord::class)->where('source_id', $record->id)->count());
    }

    public function test_exposure_observer_leaves_reduced_medical_treatment_input_undecided(): void
    {
        $substance = HazardousSubstance::factory()->create();
        $record = SubstanceExposureRecord::factory()->requiringMedicalAttention()->create(['hazardous_substance_id' => $substance->id]);

        app(SubstanceExposureRecordObserver::class)->created($record);

        $event = HsEvent::where('source_type', SubstanceExposureRecord::class)->where('source_id', $record->id)->first();
        $this->assertNotNull($event);
        $this->assertNull($event->worksafe_notifiable);
    }
}
