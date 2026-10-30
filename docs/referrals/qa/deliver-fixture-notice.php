<?php

// This verification deliberately processes only an in-app fictional notice, never email.
require __DIR__.'/seed-delivery.php';

use App\Domain\Referrals\Models\ReferralOutbox;
use App\Domain\Referrals\Services\ReferralDelivery;
use Illuminate\Support\Facades\DB;

$notice = ReferralOutbox::where('deduplication_key', 'qa-intake-notice:'.$referral->reference)->sole();
if ($notice->kind !== 'intake_received' || !($notice->message['fixture'] ?? false)) throw new RuntimeException('Fictional internal notice required.');
$before = DB::table('notifications')->where('data->kind', 'referral_intake')->count();
app(ReferralDelivery::class)->deliver($notice->id);
$after = DB::table('notifications')->where('data->kind', 'referral_intake')->count();
app(ReferralDelivery::class)->deliver($notice->id);
$repeated = DB::table('notifications')->where('data->kind', 'referral_intake')->count();
if ($notice->fresh()->state !== 'delivered' || $after !== $before + 1 || $repeated !== $after) throw new RuntimeException('Internal delivery or replay did not reconcile.');
echo json_encode(['state' => 'delivered', 'new_notices' => $after - $before, 'duplicate_notices' => $repeated - $after, 'email_sent' => false]).PHP_EOL;
