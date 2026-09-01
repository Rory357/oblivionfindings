<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Jobs\EscalateUnresolvedEligibilityJob;
use App\Jobs\RecalculateFutureShiftEligibility;
use App\Models\Client;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftSignal;
use App\Models\Site;
use App\Models\User;
use App\Notifications\EligibilityEscalationNotification;
use App\Notifications\ShiftEligibilityWarningNotification;
use Illuminate\Support\Facades\Notification;

function eligibilityPrivacyUser(
    ?Site $site,
    array $profileOverrides = [],
    array $userOverrides = [],
): User {
    $missingProfile = (bool) ($profileOverrides['missing_profile'] ?? false);
    unset($profileOverrides['missing_profile']);

    $user = User::factory()->create(array_merge([
        'approved_at' => now(),
        'role' => 'team_lead',
    ], $userOverrides));

    if (! $missingProfile) {
        HrEmployeeProfile::factory()->create(array_merge([
            'user_id' => $user->id,
            'primary_site_id' => $site?->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ], $profileOverrides));
    }

    return $user;
}

function eligibilityPrivacyShift(Site $site, User $staff): Shift
{
    $client = Client::factory()->create(['site_id' => $site->id]);

    return Shift::factory()->create([
        'client_id' => $client->id,
        'site_id' => $site->id,
        'user_id' => $staff->id,
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(8),
        'status' => 'scheduled',
    ]);
}

function eligibilityPrivacyRecalculationHarness(): object
{
    return new class extends RecalculateFutureShiftEligibility
    {
        public function notifyForTest(Shift $shift, array $reasons = ['Licence expired']): void
        {
            $this->notifyManager($shift, $reasons);
        }
    };
}

function eligibilityPrivacyEscalationHarness(): object
{
    return new class extends EscalateUnresolvedEligibilityJob
    {
        public function recipientForTest(Shift $shift): ?User
        {
            return $this->resolveEscalationRecipient($shift);
        }

        public function notifyForTest(
            Shift $shift,
            ShiftSignal $signal,
            array $reasons = ['Licence expired'],
        ): void {
            $this->notifyEscalation($shift, $signal, $reasons);
        }
    };
}

it('skips an inaccessible direct manager and selects a current local fallback manager', function () {
    Notification::fake();

    $shiftSite = Site::factory()->create();
    $remoteSite = Site::factory()->create();
    $remoteManager = eligibilityPrivacyUser($remoteSite);
    $staff = eligibilityPrivacyUser($shiftSite, ['manager_user_id' => $remoteManager->id]);
    $localFallback = eligibilityPrivacyUser($shiftSite, [], ['role' => 'provider_manager']);
    $providerManagerRole = Role::query()->firstOrCreate(['name' => 'provider_manager']);
    $localFallback->roles()->attach($providerManagerRole);
    $shift = eligibilityPrivacyShift($shiftSite, $staff);

    eligibilityPrivacyRecalculationHarness()->notifyForTest($shift);

    Notification::assertNotSentTo($remoteManager, ShiftEligibilityWarningNotification::class);
    Notification::assertSentTo($localFallback, ShiftEligibilityWarningNotification::class);
});

it('excludes direct managers whose current employment or account cannot access the Shift Site', function () {
    Notification::fake();

    $shiftSite = Site::factory()->create();
    $remoteSite = Site::factory()->create();
    $staff = eligibilityPrivacyUser($shiftSite);
    $candidates = [
        eligibilityPrivacyUser($remoteSite),
        eligibilityPrivacyUser($shiftSite, ['end_date' => now()->subDay()]),
        eligibilityPrivacyUser($shiftSite, ['is_active' => false]),
        eligibilityPrivacyUser($shiftSite, ['missing_profile' => true]),
        eligibilityPrivacyUser($shiftSite, [], ['approved_at' => null]),
    ];

    foreach ($candidates as $candidate) {
        $staff->hrEmployeeProfile()->update(['manager_user_id' => $candidate->id]);
        eligibilityPrivacyRecalculationHarness()->notifyForTest(
            eligibilityPrivacyShift($shiftSite, $staff),
        );
    }

    Notification::assertNothingSent();
});

it('falls back from a remote senior manager to the current local direct manager', function () {
    $shiftSite = Site::factory()->create();
    $remoteSite = Site::factory()->create();
    $remoteSenior = eligibilityPrivacyUser($remoteSite);
    $directManager = eligibilityPrivacyUser($shiftSite, ['manager_user_id' => $remoteSenior->id]);
    $staff = eligibilityPrivacyUser($shiftSite, ['manager_user_id' => $directManager->id]);
    $shift = eligibilityPrivacyShift($shiftSite, $staff);

    $recipient = eligibilityPrivacyEscalationHarness()->recipientForTest($shift);

    expect($recipient?->id)->toBe($directManager->id);
});

it('deterministically skips a remote first fallback and selects a current local fallback', function () {
    $shiftSite = Site::factory()->create();
    $remoteSite = Site::factory()->create();
    $staff = eligibilityPrivacyUser($shiftSite, ['manager_user_id' => null]);
    $role = Role::query()->firstOrCreate(['name' => 'provider_manager']);
    $remoteFallback = eligibilityPrivacyUser($remoteSite, [], ['role' => 'provider_manager']);
    $remoteFallback->roles()->attach($role);
    $localFallback = eligibilityPrivacyUser($shiftSite, [], ['role' => 'provider_manager']);
    $localFallback->roles()->attach($role);
    $shift = eligibilityPrivacyShift($shiftSite, $staff);

    $recipient = eligibilityPrivacyEscalationHarness()->recipientForTest($shift);

    expect($recipient?->id)->toBe($localFallback->id)
        ->and($recipient?->id)->not->toBe($remoteFallback->id);
});

it('returns no escalation recipient when every hierarchy and fallback candidate is inaccessible', function () {
    Notification::fake();

    $shiftSite = Site::factory()->create();
    $remoteSite = Site::factory()->create();
    $remoteManager = eligibilityPrivacyUser($remoteSite);
    $staff = eligibilityPrivacyUser($shiftSite, ['manager_user_id' => $remoteManager->id]);
    $role = Role::query()->firstOrCreate(['name' => 'provider_manager']);
    $remoteFallback = eligibilityPrivacyUser($remoteSite, [], ['role' => 'provider_manager']);
    $remoteFallback->roles()->attach($role);
    $shift = eligibilityPrivacyShift($shiftSite, $staff);

    $recipient = eligibilityPrivacyEscalationHarness()->recipientForTest($shift);

    expect($recipient)->toBeNull();
    Notification::assertNotSentTo($remoteManager, EligibilityEscalationNotification::class);
    Notification::assertNotSentTo($remoteFallback, EligibilityEscalationNotification::class);
});

it('uses one canonical current Shift snapshot for escalation recipient and protected payload', function () {
    Notification::fake();

    $originalSite = Site::factory()->create(['name' => 'Original Site']);
    $currentSite = Site::factory()->create(['name' => 'Current Site']);
    $originalManager = eligibilityPrivacyUser($originalSite);
    $currentManager = eligibilityPrivacyUser($currentSite);
    $originalStaff = eligibilityPrivacyUser($originalSite, ['manager_user_id' => $originalManager->id]);
    $currentStaff = eligibilityPrivacyUser($currentSite, ['manager_user_id' => $currentManager->id]);
    $shift = eligibilityPrivacyShift($originalSite, $originalStaff);
    $staleShift = Shift::query()->with(['staff:id,name', 'site:id,name'])->findOrFail($shift->id);
    $signal = ShiftSignal::query()->create([
        'shift_id' => $shift->id,
        'site_id' => $originalSite->id,
        'client_id' => $shift->client_id,
        'user_id' => $originalStaff->id,
        'signal_type' => RecalculateFutureShiftEligibility::SIGNAL_TYPE,
        'severity_hint' => 'high',
        'occurred_at' => now()->subHours(30),
        'idempotency_key' => hash('sha256', 'stale-eligibility-shift-'.$shift->id),
        'payload' => ['blocking_reasons' => ['Licence expired']],
    ]);
    $currentClient = Client::factory()->create(['site_id' => $currentSite->id]);
    $currentStartsAt = now()->addDays(5)->setTime(14, 0);
    Shift::query()->whereKey($shift->id)->update([
        'client_id' => $currentClient->id,
        'site_id' => $currentSite->id,
        'user_id' => $currentStaff->id,
        'starts_at' => $currentStartsAt,
        'ends_at' => $currentStartsAt->copy()->addHours(8),
    ]);
    $currentShift = Shift::query()->findOrFail($shift->id);

    eligibilityPrivacyEscalationHarness()->notifyForTest($staleShift, $signal);

    Notification::assertNotSentTo($originalManager, EligibilityEscalationNotification::class);
    Notification::assertSentTo(
        $currentManager,
        EligibilityEscalationNotification::class,
        fn (EligibilityEscalationNotification $notification): bool => $notification->staffName === $currentStaff->name
            && $notification->siteName === $currentSite->name
            && $notification->shiftDate === $currentShift->starts_at->format('D j M, g:i A'),
    );
});
