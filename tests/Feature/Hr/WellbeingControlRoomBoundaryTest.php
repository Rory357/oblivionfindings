<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\WellbeingCareService;
use App\Models\ControlRoomAlert;
use App\Models\Site;
use App\Models\User;

/**
 * Seam S15 — HR Wellbeing (duty-of-care) ↔ Control Room (alert triage).
 *
 * Source-audited outcome: the "wellbeing lone-worker → Control Room" seam is a
 * clean-by-design ABSENCE, not a broken flow:
 *   - The whole `app/Domain/Hr` tree has ZERO references to Control Room / the
 *     `ControlRoomAlert` model / any Signal service (grep = 0 files). HR Wellbeing
 *     (WellbeingCareService: flag triage, welfare check-ins, EAP referrals, action
 *     plans) never touches Control Room.
 *   - HR Wellbeing has no "lone worker" concept. "Lone worker → Control Room" IS a
 *     live seam, but it is H&S-owned: LoneWorkerSignalService (app/Services/
 *     HealthSafety) → SignalProcessingService → ControlRoomAlert. Control Room owns
 *     alert triage; H&S raises lone-worker signals.
 *   - HR Wellbeing's only escalation (CalculateWellbeingIndicatorsJob, a red flag)
 *     notifies the staff member's MANAGER via StaffFatigueAlertNotification — it
 *     never raises a Control Room alert. This is correct: wellbeing duty-of-care is
 *     confidential (is_private check-ins, EAP consent gates); routing it into Control
 *     Room's operational triage would breach that confidentiality.
 *
 * "One owner per fact" holds: Control Room owns operational triage (fed by H&S/ops
 * signals incl. lone-worker); HR owns confidential wellbeing duty-of-care. This test
 * is the runtime boundary guard — real duty-of-care writes happen AND none leak into
 * Control Room. It would fail if a wellbeing→Control Room bridge were ever wired
 * (which is Decision D-10 for Chane, not a unilateral build).
 */
test('S15 seam: HR wellbeing duty-of-care actions stay in HR and raise no Control Room alert', function () {
    $site = Site::factory()->create(['name' => 'Wellbeing boundary site']);
    $manager = User::factory()->create();
    $staff = User::factory()->create();
    foreach ([$manager, $staff] as $user) {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);
    }

    $service = app(WellbeingCareService::class);

    // Representative duty-of-care writes: flag triage, a welfare check-in, and a
    // confidential EAP referral (the most safety-sensitive of the three).
    $flag = $service->recordFlagAction($manager, $staff->id, 'acknowledge', 'checking in');
    $checkin = $service->createCheckin($manager, [
        'staff_user_id' => $staff->id,
        'type' => 'welfare',
        'notes' => 'Quiet week, keeping an eye on them.',
    ]);
    $eap = $service->createEapReferral($manager, [
        'staff_user_id' => $staff->id,
        'consent_given' => true,
    ]);

    // The wellbeing owner really did its job — the duty-of-care records exist…
    expect($flag->exists)->toBeTrue();
    expect($checkin->exists)->toBeTrue();
    expect($eap->exists)->toBeTrue();

    // …and NONE of it leaked into Control Room's operational alert triage. HR
    // wellbeing is confidential and never raises a ControlRoomAlert; lone-worker
    // safety reaches Control Room through the H&S signal path, not HR.
    expect(ControlRoomAlert::count())->toBe(0);
});
