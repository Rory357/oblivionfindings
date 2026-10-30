<?php

// Fictional admission without any referral conversion; refuses persistent databases through the shared guard.
require __DIR__.'/seed-staff.php';

use App\Models\Client;
use App\Models\Permission;
use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
use App\Models\RespiteStay;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;

DB::transaction(function (): void {
    $site = Site::where('name', 'Referrals browser preview')->sole();
    $actor = User::where('email', 'reviewer@referrals-preview.test')->sole();
    foreach (['clients.viewAny', 'clients.update', 'onboarding.view', 'respite.viewAny', 'respite.stays.manage'] as $key) {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'clients', 'module' => 'Operations']);
        $actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    }
    $context = ServiceContext::firstOrCreate(['site_id' => $site->id, 'name' => 'Fictional unprepared respite'], ['type' => 'planned_respite', 'is_active' => true]);
    $person = Client::where('first_name', 'Fictional')->where('last_name', 'Unprepared respite')->first();
    if (! $person) {
        $person = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => $context->id, 'first_name' => 'Fictional', 'last_name' => 'Unprepared respite', 'status' => 'onboarding']);
        $request = RespiteBookingRequest::create(['client_id' => $person->id, 'service_context_id' => $context->id,
            'requested_start' => now(), 'requested_end' => now()->addDays(2), 'status' => 'approved', 'created_by' => $actor->id]);
        $booking = RespiteBooking::create(['client_id' => $person->id, 'booking_request_id' => $request->id, 'location_id' => $site->id, 'start_at' => now(), 'end_at' => now()->addDays(2), 'status' => 'confirmed']);
        RespiteStay::create(['client_id' => $person->id, 'booking_id' => $booking->id, 'status' => 'admitted', 'actual_start' => null, 'created_by' => $actor->id]);
    }
    $stay = RespiteStay::where('client_id', $person->id)->sole();
    echo json_encode(['stay_url' => '/respite/stays/'.$stay->id, 'person_id' => $person->id, 'context' => $context->name]).PHP_EOL;
});
