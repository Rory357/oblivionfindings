<?php

// Reuses the exact isolated-schema and fictional-publication guards. No delivery.
require __DIR__.'/seed-staff.php';

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralFundingCase;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

DB::transaction(function () use ($version): void {
    $site = Site::where('name', 'Referrals browser preview')->sole();
    $owner = User::where('email', 'author@referrals-preview.test')->sole();
    $definitions = [
        ['Contacted', 12, 'triage', null, true],
        ['Capacity', 8, 'on_hold', 'capacity_waitlist', true],
        ['Funding', 4, 'funding_assistance', null, false],
        ['Older work', 40, 'assessment', null, false],
    ];
    foreach ($definitions as [$name, $age, $stage, $hold, $contacted]) {
        if (CareReferral::where('team', 'Fictional report fixture '.$name)->exists()) continue;
        $received = CarbonImmutable::now('Pacific/Auckland')->subDays($age)->setTime(10, 0)->utc();
        $record = CareReferral::create(['settings_version_id' => $version->id, 'site_id' => $site->id, 'owner_id' => $owner->id,
            'team' => 'Fictional report fixture '.$name, 'source' => 'phone', 'entry_kind' => 'self', 'stage' => $stage, 'hold_reason' => $hold,
            'identity' => ['first_name' => 'Fictional', 'last_name' => $name], 'intake' => ['submitted_contact' => ['name' => 'Fictional '.$name, 'phone' => '0200000000', 'channel' => 'phone', 'leave_message' => false]],
            'authority' => ['contact_permission' => true], 'assessment' => [], 'next_action' => 'Fictional review for report verification', 'next_due_at' => now()->subHour(), 'stage_changed_at' => $received,
            'first_contact_attempt_at' => $contacted ? $received->addHours(2) : null, 'first_human_contact_at' => $contacted ? $received->addHours(4) : null]);
        $record->forceFill(['created_at' => $received])->save();
        if ($name === 'Funding') foreach (['dss_nasc' => false, 'personal_income' => true] as $pathway => $income) {
            ReferralFundingCase::create(['referral_id' => $record->id, 'navigator_id' => $owner->id, 'settings_version_id' => $version->id,
                'pathway' => $pathway, 'status' => 'exploring', 'personal_income' => $income, 'details' => ['pathway_label' => $income ? 'Personal income assistance' : 'DSS / NASC', 'fixture' => 'Fictional reporting data; no decision or verified award']]);
        }
    }
    echo "Fictional reporting examples ready in the disposable preview.\n";
});
