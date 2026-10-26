<?php

require __DIR__.'/inspect-delivery.php';

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralConversion;
use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Domain\Referrals\Services\ReferralPeopleContacts;
use App\Models\User;

$referral = CareReferral::where('source', 'qa_people_contacts')->sole();
$actor = User::where('email', 'reviewer@referrals-preview.test')->sole();
$records = app(ReferralPeopleContacts::class)->present($actor, $referral);
$checks = [];
$check = function ($ok, $label) use (&$checks) {
    if (! $ok) {
        throw new RuntimeException($label);
    } $checks[] = $label;
};
$check(count($records) === 1, 'Browser edits retained one contact record');
$contact = $records[0];
$check($contact['version'] === 3 && $referral->lock_version === 4, 'Create, withdrawal and renewal have separate committed versions');
$check($contact['details']['name'] === 'Fictional Morgan Supporter' && $contact['details']['role'] === 'supporter', 'Reviewed role and name persisted');
$check($contact['details']['phone'] === '021 555 0188' && $contact['details']['leave_message'] === false, 'Phone and no-message preference survived withdrawal and renewal');
$check($contact['details']['safe_time'] === 'Fictional QA: weekday afternoons only. Do not leave messages.', 'Safe contact instructions remain intact');
$check($contact['details']['review_due_at'] === '2026-10-24T20:00:00.000000Z', 'Calendar and clock selection saved 25 October 9am in New Zealand');
$check($contact['review_current'] && $contact['details']['may_contact'], 'New review explicitly renewed current contact permission');
$events = ReferralEvent::where('referral_id', $referral->id)->where('kind', 'person_contact.reviewed')->orderBy('id')->get();
$check($events->count() === 3 && $events->pluck('payload.current.details.state')->all() === ['current', 'withdrawn', 'current'], 'Encrypted history preserves each permission decision');
$check(! str_contains($referral->getRawOriginal('intake'), 'Fictional Morgan') && $events->every(fn ($event) => ! str_contains($event->getRawOriginal('payload'), '021 555 0188')), 'Contact values remain encrypted in storage and history');
$check($referral->client_id === null && ReferralConversion::where('referral_id', $referral->id)->doesntExist(), 'Recording a contact does not convert the supported person');
$check(User::where('name', 'Fictional Morgan Supporter')->doesntExist(), 'Recording a contact does not create a login account');
$check(ReferralWorkItem::where('referral_id', $referral->id)->where('status', 'open')->whereNotNull('owner_id')->count() === 1, 'Assigned funding-assistance follow-up remains open');
echo json_encode(['passed' => $checks, 'count' => count($checks), 'reference' => $referral->reference, 'contact_id' => $contact['id']]).PHP_EOL;
