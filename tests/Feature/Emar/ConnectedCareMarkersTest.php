<?php

namespace Tests\Feature\Emar;

use App\Services\Medication\ExternalClinical\ExternalClinicalProposals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ConnectedCareSwitches;
use Tests\Support\ExternalClinicalFixtures;
use Tests\TestCase;

/**
 * EA-025 (UI part, after Lane C9): a waiting prescriber request is marked on
 * the person's medicine record and on the order it is about in Orders, so
 * nobody keeps working from the unchanged chart without knowing. The marker
 * shows only while the outside prescriber portal runs, and a controlled
 * request only to controlled readers.
 */
final class ConnectedCareMarkersTest extends TestCase
{
    use ExternalClinicalFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ConnectedCareSwitches::on('prescriber_portal');
        ConnectedCareSwitches::value('prescriber_registration_check', 'note');
        $this->connectedFixtures();
        config(['medications.person_record' => 'p02']);
    }

    public function test_ea025_a_waiting_change_request_is_marked_on_the_order_row_and_the_person_record(): void
    {
        $order = $this->chart();
        $other = $this->chart(['name' => 'Another medicine']);
        $proposal = app(ExternalClinicalProposals::class)->submit($this->clinician, $this->person->id, [
            'kind' => 'change', 'medication_id' => $order->id, 'expected_version' => 1,
            'prescription' => $this->prescription(['dosage' => '20 mg']), 'reason' => 'Increase', 'request_key' => 'marker-1',
        ], null);

        $this->actingAs($this->manager)->get('/emar/prescriptions?client_id='.$this->person->id)->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('orders.data', fn ($rows) => collect($rows)->firstWhere('id', $order->id)['prescriber_request']['id'] === $proposal->id
                && collect($rows)->firstWhere('id', $order->id)['prescriber_request']['kind'] === 'change'
                && collect($rows)->firstWhere('id', $other->id)['prescriber_request'] === null));
        $this->actingAs($this->manager)->get('/emar/mar?client_id='.$this->person->id)->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('emar/record/show')
                ->where('prescriber_requests.count', 1)
                ->where('prescriber_requests.url', '/emar/connected-care?client_id='.$this->person->id.'#requests'));
    }

    public function test_ea025_no_marker_while_the_portal_is_switched_off(): void
    {
        $order = $this->chart();
        app(ExternalClinicalProposals::class)->submit($this->clinician, $this->person->id, [
            'kind' => 'stop', 'medication_id' => $order->id, 'expected_version' => 1,
            'prescription' => $this->prescription(), 'reason' => 'Stop', 'request_key' => 'marker-2',
        ], null);
        ConnectedCareSwitches::off('prescriber_portal');

        $this->actingAs($this->manager)->get('/emar/prescriptions?client_id='.$this->person->id)->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('orders.data.0.prescriber_request', null));
        $this->actingAs($this->manager)->get('/emar/mar?client_id='.$this->person->id)->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('prescriber_requests', null));
    }
}
