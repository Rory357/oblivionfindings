<?php

use App\Models\CareNoteTemplate;
use App\Models\Client;
use App\Models\ClientFund;
use App\Models\EvvRecord;
use App\Models\Shift;
use App\Models\StaffQualificationRequirement;
use App\Models\User;
use Laravel\Dusk\Browser;

test('operations dynamic pages that previously 500 now load', function () {
    $user = User::where('email', 'admin@test.com')->firstOrFail();
    $client = Client::factory()->create(['organization_id' => 1]);
    $shift = Shift::factory()->create([
        'organization_id' => 1,
        'client_id' => $client->id,
        'user_id' => $user->id,
        'created_by' => $user->id,
    ]);

    $template = CareNoteTemplate::create([
        'organization_id' => 1,
        'name' => 'Dusk care note template',
        'description' => 'Template used by the Operations dynamic page smoke test.',
        'template_type' => 'general',
        'fields' => [
            ['label' => 'Summary', 'type' => 'text', 'required' => true],
        ],
        'is_active' => true,
        'created_by' => $user->id,
    ]);

    $fund = ClientFund::create([
        'organization_id' => 1,
        'client_id' => $client->id,
        'fund_name' => 'Dusk flexible support fund',
        'fund_type' => 'personal',
        'balance' => 125.50,
        'low_balance_threshold' => 25,
        'is_active' => true,
        'notes' => 'Browser smoke test fund.',
    ]);

    $fund->transactions()->create([
        'organization_id' => 1,
        'transaction_type' => 'credit',
        'amount' => 125.50,
        'running_balance' => 125.50,
        'description' => 'Opening balance',
        'reference' => 'DUSK-OPEN',
        'transaction_date' => now()->toDateString(),
        'recorded_by' => $user->id,
    ]);

    $evv = EvvRecord::create([
        'organization_id' => 1,
        'shift_id' => $shift->id,
        'user_id' => $user->id,
        'client_id' => $client->id,
        'check_in_time' => now()->subHours(2),
        'check_out_time' => now()->subHour(),
        'check_in_latitude' => -36.8485,
        'check_in_longitude' => 174.7633,
        'check_out_latitude' => -36.8485,
        'check_out_longitude' => 174.7633,
        'verification_status' => 'pending',
        'notes' => 'Browser smoke test EVV record.',
    ]);

    StaffQualificationRequirement::create([
        'organization_id' => 1,
        'client_id' => $client->id,
        'qualification_name' => 'Medication support',
        'qualification_type' => 'certification',
        'is_mandatory' => true,
        'description' => 'Required for the dynamic qualification page smoke test.',
    ]);

    $this->browse(function (Browser $browser) use ($user, $template, $fund, $evv, $shift) {
        $browser->loginAs($user)
            ->visit("/operations/note-templates/{$template->id}/edit")
            ->waitForText('Edit Note Template', 10)
            ->assertDontSee('Server Error')
            ->assertInputValue('#name', 'Dusk care note template')
            ->visit("/operations/client-funds/{$fund->id}")
            ->waitForText('CURRENT BALANCE', 10)
            ->assertDontSee('Server Error')
            ->assertSee('Opening balance')
            ->visit("/operations/evv/{$evv->id}")
            ->waitForText('Verification', 10)
            ->assertDontSee('Server Error')
            ->assertSee('Browser smoke test EVV record')
            ->visit("/operations/qualifications/check/{$shift->id}")
            ->waitForText('Qualification Check', 10)
            ->assertDontSee('Server Error')
            ->assertSee('Medication support');
    });
});
