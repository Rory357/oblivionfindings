<?php

use App\Models\Asset;
use App\Models\AssetInspection;
use App\Models\User;
use Laravel\Dusk\Browser;

test('asset show page renders inspection history details', function () {
    $user = User::where('email', 'admin@test.com')->firstOrFail();

    $asset = Asset::factory()->create([
        'created_by_user_id' => $user->id,
        'updated_by_user_id' => $user->id,
        'requires_inspection' => true,
        'inspection_due_at' => now()->addWeek(),
    ]);

    AssetInspection::query()->create([
        'asset_id' => $asset->id,
        'inspected_by_user_id' => $user->id,
        'inspected_at' => now()->subDay(),
        'result' => 'pass',
        'notes' => 'QA inspection note',
    ]);

    $this->browse(function (Browser $browser) use ($user, $asset) {
        $browser->loginAs($user)
            ->visit("/fleet-assets/assets/{$asset->id}")
            ->waitForText($asset->name, 10)
            ->click('@radix-tab-inspections')
            ->waitForText('Inspection History', 10)
            ->waitForText('pass', 10);
    });
});
