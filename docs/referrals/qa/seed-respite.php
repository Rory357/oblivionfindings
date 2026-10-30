<?php

// Blocked-admission browser fixture only. This is not a completed referral journey.
// Reuses the exact disposable-schema guard and fakes every outgoing notification.
require __DIR__.'/seed-staff.php';

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralConversion;
use App\Models\Client;
use App\Models\Permission;
use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
use App\Models\RespiteStay;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;

DB::transaction(function () use ($version): void {
    $site = Site::where('name', 'Referrals browser preview')->sole();
    $reviewer = User::where('email', 'reviewer@referrals-preview.test')->sole();
    foreach (['clients.viewAny', 'clients.update', 'care_plans.viewAny', 'service_agreements.viewAny', 'respite.viewAny', 'respite.stays.manage'] as $key) {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'clients', 'module' => 'Operations']);
        $reviewer->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    }
    $referral = CareReferral::where('team', 'Fictional blocked respite fixture')->first();
    if (! $referral) {
        $context = ServiceContext::create(['site_id' => $site->id, 'name' => 'Fictional planned respite', 'type' => 'planned_respite', 'is_active' => true]);
        $person = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => $context->id, 'first_name' => 'Fictional', 'last_name' => 'Respite check-in', 'status' => 'onboarding']);
        $referral = CareReferral::create(['settings_version_id' => $version->id, 'site_id' => $site->id, 'client_id' => $person->id, 'owner_id' => $reviewer->id,
            'team' => 'Fictional blocked respite fixture', 'source' => 'phone', 'entry_kind' => 'self', 'stage' => 'converted', 'requested_service' => 'supported_living',
            'identity' => ['first_name' => 'Fictional', 'last_name' => 'Respite check-in'], 'intake' => ['goals' => 'Fictional blocked-admission UI verification only'],
            'authority' => [], 'assessment' => [], 'next_action' => 'Review missing service-start prerequisites', 'next_due_at' => now()->addDay(), 'stage_changed_at' => now(), 'converted_at' => now()]);
        ReferralConversion::create(['referral_id' => $referral->id, 'client_id' => $person->id, 'created_person' => true, 'converted_by' => $reviewer->id, 'converted_at' => now(),
            'receipt' => ['site_id' => $site->id, 'service_context_id' => $context->id, 'workflow_scope' => 'person', 'fixture' => 'Incomplete fictional handover for blocked-admission verification']]);
        $request = RespiteBookingRequest::create(['client_id' => $person->id, 'service_context_id' => $context->id, 'requested_start' => now(), 'requested_end' => now()->addDay(), 'status' => 'approved', 'created_by' => $reviewer->id]);
        $booking = RespiteBooking::create(['booking_request_id' => $request->id, 'client_id' => $person->id, 'location_id' => $site->id, 'start_at' => now(), 'end_at' => now()->addDay(), 'status' => 'confirmed']);
        RespiteStay::create(['booking_id' => $booking->id, 'client_id' => $person->id, 'status' => 'admitted', 'actual_start' => null]);
    }
    $stay = RespiteStay::where('client_id', $referral->client_id)->sole();
    echo json_encode(['stay_url' => '/respite/stays/'.$stay->id, 'review_url' => '/operations/referrals/'.$referral->reference.'?tab=service-start', 'status' => $stay->status]).PHP_EOL;
});
