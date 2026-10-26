<?php

namespace Tests\Feature\HealthSafety;

use App\Models\ChemicalExposureSourceDelivery;
use App\Models\HsEvent;
use App\Services\HealthSafety\ChemicalExposureCommandAccess;
use App\Services\HealthSafety\ChemicalExposureDeliveryService;
use Illuminate\Support\Facades\DB;

/** The outbox composes a concrete destination callback; root's separate destination suite proves its actual H&S marker. */
final class ChemicalExposureDeliveryContractTest extends ChemicalExposureCommandTestCase
{
    private function delivery($root): ChemicalExposureSourceDelivery
    {
        return ChemicalExposureSourceDelivery::where('exposure_id', $root->id)->where('kind', 'health_safety')->orderByDesc('id')->firstOrFail();
    }
    private function serviceDelivery(): ChemicalExposureDeliveryService { return app(ChemicalExposureDeliveryService::class); }

    public function test_capture_acceptance_leaves_durable_pending_outbox_without_inventing_hs_delivery(): void
    {
        $root = $this->exposure(); $row = $this->delivery($root); $dto = $this->serviceDelivery()->attemptsForViewer($this->manager, $root->id);
        $this->assertSame('pending', $row->state); $this->assertNull($row->hs_event_id); $this->assertSame(0, $row->attempts);
        $this->assertSame('recorded', $dto['state']); $this->assertSame($row->id, $dto['attempts'][0]['id']);
        foreach (['membership', 'worker_id', 'site_id', 'original_context', 'idempotency_key', 'actor_id'] as $private) {
            $this->assertArrayNotHasKey($private, $dto['attempts'][0]);
        }
    }

    public function test_required_callback_receives_same_current_authority_and_exact_membership_before_confirmation(): void
    {
        $root = $this->exposure(); $row = $this->delivery($root); $called = 0;
        $result = $this->serviceDelivery()->deliverForViewer($this->manager, $row->id,
            function ($source, $proof, $current, $reads, $sites, $membership, $kind, $recipient) use ($root, &$called) {
                $reads->assertActive(); $called++;
                $this->assertSame($root->id, $source->id); $this->assertSame($this->manager->id, $current->id);
                $this->assertContains($root->site_id, $sites); $this->assertSame('recorded', $proof['attribution_state']);
                $this->assertSame($proof['source_version_id'], $membership['source_version_id']); $this->assertSame('health_safety', $kind); $this->assertNull($recipient);
                $event = HsEvent::factory()->create(['site_id' => $source->site_id, 'staff_id' => $source->user_id,
                    'source_type' => $source::class, 'source_id' => $source->id, 'event_category' => HsEvent::CATEGORY_EXPOSURE]);
                return ['hs_event_id' => (int) $event->id, 'source_revision' => (int) $source->source_revision,
                    'source_version_id' => (int) $proof['source_version_id']];
            });
        $this->assertSame(1, $called); $this->assertSame('delivered', $result['state']); $this->assertNotNull($row->fresh()->hs_event_id);
        $this->assertSame(1, $root->fresh()->source_revision);
    }

    public function test_null_or_wrong_revision_consumer_refuses_destination_confirmation_atomically(): void
    {
        $root = $this->exposure(); $row = $this->delivery($root);
        foreach ([null, ['hs_event_id' => 1, 'source_revision' => 999, 'source_version_id' => $row->source_version_id],
            ['hs_event_id' => '1', 'source_revision' => 1, 'source_version_id' => $row->source_version_id]] as $answer) {
            $this->integrity(fn () => $this->serviceDelivery()->deliverForViewer($this->manager, $row->id, fn () => $answer));
            $this->assertSame('pending', $row->fresh()->state); $this->assertSame(0, $row->fresh()->attempts);
        }
    }

    public function test_destination_side_write_rolls_back_when_callback_throws_or_outbox_save_is_vetoed(): void
    {
        $root = $this->exposure(); $row = $this->delivery($root); $before = HsEvent::count();
        $this->integrity(fn () => $this->serviceDelivery()->deliverForViewer($this->manager, $row->id, function () use ($root) {
            HsEvent::factory()->create(['site_id' => $root->site_id, 'source_type' => $root::class, 'source_id' => $root->id]);
            throw new \LogicException('The concrete destination did not persist required proof.');
        }));
        $this->assertSame($before, HsEvent::count()); $this->assertSame('pending', $row->fresh()->state);
        ChemicalExposureSourceDelivery::updating(fn ($item) => $item->state === 'delivered' ? false : null);
        try {
            $this->integrity(fn () => $this->serviceDelivery()->deliverForViewer($this->manager, $row->id,
                function ($source, $proof) use ($root) {
                    $event = HsEvent::factory()->create(['site_id' => $root->site_id, 'source_type' => $root::class, 'source_id' => $root->id]);
                    return ['hs_event_id' => (int) $event->id, 'source_revision' => 1, 'source_version_id' => $proof['source_version_id']];
                }));
        } finally { ChemicalExposureSourceDelivery::flushEventListeners(); }
        $this->assertSame($before, HsEvent::count()); $this->assertSame('pending', $row->fresh()->state);
    }

    public function test_a_delivered_flag_alone_never_suppresses_current_concrete_destination_verification(): void
    {
        $root = $this->exposure(); $row = $this->delivery($root);
        DB::table('chemical_exposure_source_deliveries')->where('id', $row->id)->update(['state' => 'delivered', 'hs_event_id' => 999999]);
        $called = false;
        // Test shape: an arrow function captures $called by value, so the outer wrapper takes it by reference.
        $this->integrity(function () use ($row, &$called) { $this->serviceDelivery()->deliverForViewer($this->manager, $row->id,
            function () use (&$called) { $called = true; throw new \LogicException('The retained destination is missing.'); }); });
        $this->assertTrue($called); $this->assertSame(0, $row->fresh()->attempts);
    }

    public function test_old_delivery_becomes_superseded_after_real_aggregate_advance_without_invoking_consumer(): void
    {
        $root = $this->exposure(); $old = $this->delivery($root);
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Actual new occurrence fact', 'circumstances' => 'Current source narrative']));
        $called = false; $dto = $this->serviceDelivery()->deliverForViewer($this->manager, $old->id,
            function () use (&$called) { $called = true; return null; });
        $this->assertFalse($called); $this->assertSame('superseded', $dto['state']);
        $this->assertSame(2, $root->fresh()->source_revision); $this->assertSame('pending', $this->delivery($root)->state);
    }

    public function test_corrupted_membership_actor_or_purpose_is_repair_not_a_legitimate_superseded_delivery(): void
    {
        $root = $this->exposure(); $row = $this->delivery($root); $original = $row->getRawOriginal();
        foreach ([['actor_id' => $this->worker->id], ['kind' => 'automatic_fitness'], ['recipient_id' => $this->worker->id],
            ['membership' => json_encode([...$row->membership, 'worker_id' => $this->manager->id])]] as $bad) {
            DB::table('chemical_exposure_source_deliveries')->where('id', $row->id)->update($bad); $called = false;
            $this->integrity(fn () => $this->serviceDelivery()->deliverForViewer($this->manager, $row->id,
                function () use (&$called) { $called = true; return null; }));
            $this->assertFalse($called); DB::table('chemical_exposure_source_deliveries')->where('id', $row->id)->update($original);
        }
    }

    public function test_warmed_current_manage_private_read_or_site_withdrawal_denies_before_consumer_and_delivery_body(): void
    {
        $root = $this->exposure(); $row = $this->delivery($root); $this->manager->load('permissionOverrides');
        foreach (['hazards.manage', 'hazards.view', 'hr.wellbeing.view'] as $key) {
            $this->allow($this->manager, $key, false); $called = false;
            $this->denial(fn () => $this->serviceDelivery()->deliverForViewer($this->manager, $row->id,
                function () use (&$called) { $called = true; return null; }));
            $this->assertFalse($called); $this->allow($this->manager, $key);
        }
        \App\Domain\Hr\Models\HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->foreignSite->id]);
        $loaded = false; ChemicalExposureSourceDelivery::retrieved(function ($item) use (&$loaded) { if ($item->getRawOriginal('membership') !== null) { $loaded = true; } });
        try { $this->denial(fn () => $this->serviceDelivery()->deliverForViewer($this->manager, $row->id, fn () => null), 404); }
        finally { ChemicalExposureSourceDelivery::flushEventListeners(); }
        $this->assertFalse($loaded);
    }

    public function test_followup_delivery_requires_exact_named_private_receiver_and_actual_callback_purpose(): void
    {
        $root = $this->exposure(); $receiver = $this->staff(['hazards.view', 'hr.wellbeing.view', 'hazards.manage']);
        $r = $this->exposureService()->assignFollowup($root, $this->manager, $this->privateCommand($root,
            ['assigned_to' => $receiver->id, 'instruction' => 'Actual private named action', 'due_at' => '2026-10-13T01:00:00Z']));
        $row = ChemicalExposureSourceDelivery::where('source_version_id', $r['source_version_id'])->where('kind', 'followup')->sole();
        $this->allow($receiver, 'hr.wellbeing.view', false); $called = false;
        $this->denial(fn () => $this->serviceDelivery()->deliverForViewer($this->manager, $row->id,
            function () use (&$called) { $called = true; return null; }));
        $this->assertFalse($called); $this->allow($receiver, 'hr.wellbeing.view');
        $this->integrity(fn () => $this->serviceDelivery()->deliverForViewer($this->manager, $row->id,
            fn ($source, $proof) => ['delivered' => true, 'recipient_id' => $this->worker->id,
                'source_revision' => 2, 'source_version_id' => $proof['source_version_id']]));
        $dto = $this->serviceDelivery()->deliverForViewer($this->manager, $row->id,
            fn ($source, $proof, $current, $reads, $sites, $membership, $kind, $recipient) => ['delivered' => true,
                'recipient_id' => $recipient, 'source_revision' => 2, 'source_version_id' => $proof['source_version_id']]);
        $this->assertSame('delivered', $dto['state']); $this->assertNull($row->fresh()->hs_event_id);
    }

    public function test_source_proof_and_delivery_refuse_external_root_mutation_instead_of_certifying_existing_flag(): void
    {
        $root = $this->exposure(); $row = $this->delivery($root);
        DB::table('substance_exposure_records')->where('id', $root->id)->update(['symptoms' => 'Unaudited body replacement']);
        $this->assertSame('repair_required', $this->proof($root)['attribution_state']);
        $this->integrity(fn () => $this->serviceDelivery()->deliverForViewer($this->manager, $row->id, fn () => null));
        $this->assertSame(['state' => 'repair_required', 'attempts' => null], $this->serviceDelivery()->attemptsForViewer($this->manager, $root->id));
    }
}
