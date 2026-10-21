<?php

require __DIR__.'/seed-staff.php';

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralOutbox;

$referral = CareReferral::where('team', 'Browser verification fixture')->sole();
ReferralOutbox::firstOrCreate(['deduplication_key' => 'qa-intake-notice:'.$referral->reference], ['referral_id' => $referral->id, 'kind' => 'intake_received', 'state' => 'failed', 'message' => ['fixture' => true], 'available_at' => now(), 'last_error_code' => 'no_eligible_recipient']);
ReferralOutbox::firstOrCreate(['deduplication_key' => 'qa-uncertain-ack:'.$referral->reference], ['referral_id' => $referral->id, 'kind' => 'public_acknowledgement', 'state' => 'delivery_unknown', 'message' => ['recipient' => 'fictional@example.test', 'fixture' => true], 'available_at' => now(), 'last_error_code' => 'worker_interrupted']);
echo "Fictional communication recovery examples prepared; no email sent.\n";
