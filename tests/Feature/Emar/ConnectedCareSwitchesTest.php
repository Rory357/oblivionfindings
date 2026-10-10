<?php

/*
 * B10 — connected care completed (D4, 9 Oct: "PLEASE COMPLETE IT").
 *
 * EA-103: every optional connected feature is off until an organisation
 * switches it on in Settings › Connected services; off, its pages and
 * actions are not there (404) and its navigation is hidden.
 * EA-139: the "Were you there?" request also goes by push.
 * EA-188: owners hear 14 days before access, identities and reviews end.
 */

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\MedicationExternalClinician;
use App\Models\MedicationExternalGrant;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Models\UserPushSubscription;
use App\Notifications\Channels\PushChannel;
use App\Notifications\MedicationSecondPersonConfirmationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\ConnectedCareSwitches;

uses(RefreshDatabase::class);

function connectedCareManager(Site $site): User
{
    $user = User::factory()->create(['role' => 'team_lead', 'approved_at' => now(), 'email_verified_at' => now()]);
    $keys = ['medications.view', 'clients.viewAny', 'medications.orders.manage', 'medications.external.manage', 'medications.transfers.manage',
        'medications.catalogue.manage', 'medications.backups.manage', 'medications.pharmacy.connect.manage', 'medications.settings.manage',
        'medications.audit.view', 'medications.reports.view', 'medications.reports.export'];
    foreach ($keys as $key) {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'medications']);
        $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    }
    HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
        'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true, 'work_email' => 'lead.'.$user->id.'@work.example.test']);

    return $user->fresh();
}

test('b10 every connected feature is off by default and its pages are not there', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $manager = connectedCareManager($site);

    foreach (['/emar/catalogue', '/emar/backups', '/emar/pharmacy-connections', '/emar/connected-care', '/clinical-portal'] as $uri) {
        $this->actingAs($manager)->get($uri)->assertNotFound();
    }

    ConnectedCareSwitches::on('picture_catalogue');
    $this->actingAs($manager)->get('/emar/catalogue')->assertOk();
    $this->actingAs($manager)->get('/emar/backups')->assertNotFound();
});

test('b10 eMAR pages tell navigation which connected features run', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $manager = connectedCareManager($site);
    ConnectedCareSwitches::on('provider_transfers');

    $this->actingAs($manager)->get('/emar/connected-care')->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.can.medications.connected.provider_transfers', true)
            ->where('auth.can.medications.connected.prescriber_portal', false)
            ->where('auth.can.medications.connected.protected_backups', false));
});

test('b10 the second-person request goes by push to a person with an allowed device', function () {
    $colleague = User::factory()->create(['approved_at' => now()]);
    $notification = new MedicationSecondPersonConfirmationNotification(42);
    expect($notification->via($colleague))->toBe(['database']);

    UserPushSubscription::query()->create(['user_id' => $colleague->id, 'provider' => 'webpush', 'token' => 'https://push.example.test/'.$colleague->id,
        'keys' => ['p256dh' => 'key', 'auth' => 'auth'], 'enabled' => true]);
    expect($notification->via($colleague->fresh()))->toBe(['database', PushChannel::class])
        ->and($notification->toPush($colleague))->toBe(['title' => 'Were you there?',
            'body' => 'A dose names you as the second person. Answer in your own login within 30 minutes. Reset your witness PIN if you have forgotten it.',
            'data' => ['url' => '/medication-followups?open=42']]);
});

test('b10 owners are reminded once, 14 days before prescriber access ends', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $manager = connectedCareManager($site);
    $client = Client::factory()->create(['site_id' => $site->id, 'first_name' => 'Aroha', 'last_name' => 'Ngata']);
    $prescriber = User::factory()->create(['name' => 'Dr Synthetic', 'role' => 'external_clinician', 'external_clinical_account' => true, 'approved_at' => now()]);
    $identity = MedicationExternalClinician::create(['user_id' => $prescriber->id, 'provider_name' => 'Synthetic practice', 'registration_authority' => 'Register',
        'registration_number' => 'R-1', 'identity_evidence' => 'Checked', 'verified_by' => $manager->id, 'identity_verified_at' => now(), 'expires_at' => now()->addDays(200)]);
    MedicationExternalGrant::create(['clinician_id' => $identity->id, 'client_id' => $client->id, 'site_id' => $site->id, 'purpose' => 'Review',
        'can_propose' => false, 'include_controlled' => false, 'granted_by' => $manager->id, 'expires_at' => now()->addDays(10)]);
    ConnectedCareSwitches::on('prescriber_portal');

    Artisan::call('medications:connected-expiry-reminders');
    Artisan::call('medications:connected-expiry-reminders');

    $reminders = DatabaseNotification::query()->where('notifiable_id', $manager->id)->get();
    expect($reminders)->toHaveCount(1)
        ->and($reminders->first()->data['title'])->toBe('Prescriber access ends soon')
        ->and($reminders->first()->data['message'])->toContain('Dr Synthetic')->toContain('Aroha N.');
});
