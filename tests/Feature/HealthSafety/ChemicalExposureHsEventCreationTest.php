<?php

namespace Tests\Feature\HealthSafety;

use App\Models\HazardousSubstance;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\Site;
use App\Models\SubstanceExposureRecord;
use App\Models\User;
use App\Observers\SubstanceExposureRecordObserver;
use App\Services\HealthSafety\HsEventClosureService;
use App\Services\HealthSafety\HsEventService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /**
     * Main's WorkSafe path: the classifier's positive is only preliminary, so a hospitalisation exposure lands
     * undecided, high severity and needing investigation. It can't close until a qualified H&S lead signs the
     * decision; a signed "notifiable" decision puts it in the WorkSafe pending queue.
     */
    public function test_hospitalisation_exposure_awaits_a_signed_worksafe_decision_then_joins_the_worksafe_queue(): void
    {
        $this->seed(RbacSeeder::class);
        $site = Site::factory()->create();
        $substance = HazardousSubstance::factory()->create();
        $record = SubstanceExposureRecord::factory()->notifiable()->create([
            'hazardous_substance_id' => $substance->id, 'site_id' => $site->id,
        ]);

        $event = HsEvent::where('source_type', SubstanceExposureRecord::class)->where('source_id', $record->id)->sole();
        $this->assertSame($site->id, (int) $event->site_id);
        $this->assertSame(HsEvent::SEVERITY_HIGH, $event->severity);
        $this->assertTrue((bool) $event->investigation_required);
        $this->assertNull($event->worksafe_notifiable);
        $this->assertNull($event->worksafe_status);
        $this->assertFalse($event->hasSignedWorksafeDecision());
        $this->assertContains('worksafe_decision', array_column(
            app(HsEventClosureService::class)->readiness($event)->hardBlockers(), 'key'));

        $lead = User::factory()->create(['approved_at' => now()]);
        $lead->permissionOverrides()->syncWithoutDetaching([
            Permission::where('key', 'hazards.manage')->sole()->id => ['allowed' => true],
        ]);
        app(HsEventService::class)->recordWorksafeDecision($event, true,
            'Hospitalisation after a chemical exposure is a notifiable illness.', $lead);

        $event->refresh();
        $this->assertTrue($event->worksafe_notifiable);
        $this->assertSame(HsEvent::WORKSAFE_PENDING, $event->worksafe_status);
        $this->assertTrue($event->hasSignedWorksafeDecision());
        $this->assertSame(1, HsEvent::whereKey($event->id)->where('worksafe_notifiable', true)
            ->where('worksafe_status', HsEvent::WORKSAFE_PENDING)->count());
        $this->assertNotContains('worksafe_decision', array_column(
            app(HsEventClosureService::class)->readiness($event)->hardBlockers(), 'key'));
    }

    public function test_exposure_hs_event_copies_no_narrative_or_clinical_text(): void
    {
        $site = Site::factory()->create();
        $substance = HazardousSubstance::factory()->create();
        $record = SubstanceExposureRecord::factory()->notifiable()->create([
            'hazardous_substance_id' => $substance->id, 'site_id' => $site->id,
            'circumstances' => 'PRIVATE-CIRCUMSTANCES spill in the laundry',
            'symptoms' => 'PRIVATE-SYMPTOMS wheezing and sore eyes',
        ]);

        $event = HsEvent::where('source_type', SubstanceExposureRecord::class)->where('source_id', $record->id)->sole();
        $stored = json_encode(DB::table('hs_events')->where('id', $event->id)->first(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('PRIVATE-', $stored);
        $this->assertStringNotContainsString('hospitalisation', $stored);
    }

    private function exposureWithTreatment(string $treatment): array
    {
        $substance = HazardousSubstance::factory()->create();
        $record = SubstanceExposureRecord::factory()->create(['hazardous_substance_id' => $substance->id,
            'medical_treatment' => $treatment, 'medical_attention_sought' => $treatment !== 'none']);
        $event = HsEvent::where('source_type', SubstanceExposureRecord::class)->where('source_id', $record->id)->sole();

        return [$record, $event];
    }

    /** Interim until job 3: a corrected exposure raises its open H&S event, never lowers it, and leaves a closed one. */
    public function test_corrected_treatment_raises_the_open_event_and_marks_investigation_required(): void
    {
        [$record, $event] = $this->exposureWithTreatment('none');
        $this->assertSame(HsEvent::SEVERITY_LOW, $event->severity);
        $this->assertFalse((bool) $event->investigation_required);

        $record->update(['medical_treatment' => 'hospitalisation', 'medical_attention_sought' => true]);

        $event->refresh();
        $this->assertSame(HsEvent::SEVERITY_HIGH, $event->severity);
        $this->assertTrue((bool) $event->investigation_required);
        $this->assertNull($event->worksafe_notifiable);
        $this->assertSame(1, HsEvent::where('source_type', SubstanceExposureRecord::class)->where('source_id', $record->id)->count());
    }

    public function test_corrected_treatment_never_lowers_the_event(): void
    {
        [$record, $event] = $this->exposureWithTreatment('hospitalisation');
        $record->update(['medical_treatment' => 'first_aid']);

        $this->assertSame(HsEvent::SEVERITY_HIGH, $event->fresh()->severity);
        $this->assertTrue((bool) $event->fresh()->investigation_required);
    }

    public function test_corrected_treatment_leaves_a_closed_event_as_recorded(): void
    {
        [$record, $event] = $this->exposureWithTreatment('none');
        // Fixture only: the closure guard trigger admits this one close, as HsEventClosureService does.
        DB::statement('SET @hs_canonical_close_event_id = '.(int) $event->id);
        try { $event->forceFill(['status' => HsEvent::STATUS_CLOSED])->saveQuietly(); }
        finally { DB::statement('SET @hs_canonical_close_event_id = NULL'); }

        $record->update(['medical_treatment' => 'hospitalisation', 'medical_attention_sought' => true]);

        $this->assertSame(HsEvent::SEVERITY_LOW, $event->fresh()->severity);
        $this->assertSame(HsEvent::STATUS_CLOSED, $event->fresh()->status);
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
