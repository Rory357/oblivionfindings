# PIN-2 recorder and follow-up integration contract

Security owns the source controller/routes, expiration command/schedule and reusable answer dialog. Main relays this contract to P01 and P08a; Security does not message other workers or modify their checkouts.

## P01 recording

The existing requirements JSON adds:

```ts
second_person: {
    // Existing fields remain.
    forgotten_pin_allowed: boolean;
    confirm_within_minutes: 30;
}
```

Offer “Forgot PIN” only when forgotten_pin_allowed is true and an eligible named colleague has been selected. Send witnessed_by with that colleague’s user ID and second_person_pin_forgotten: true. Omit the PIN credential for this explicit branch. Do not set second_person_unavailable in the same request. Return to actual PIN entry by clearing the forgotten flag.

The server rechecks current nominee eligibility and credential readiness under its existing locks. Controlled drugs, witness-required medicines, absent/ineligible colleagues, missing/reset/expired/locked PINs, exhausted recorder attempts and unavailable protected keys reject fallback. The allowed kinds are rule, amount and cosigner. Availability is not a bypass of failed PIN checks.

The original dose is saved once with second_person_status: 'not_verified', witness_method: 'pin_forgotten', witnessed_by: null and witnessed_at: null. Show “Second person not yet verified” and the pending confirmation rather than claiming a witness already verified it. Keep the same recording request UUID and its semantic payload on uncertain retries. Server replays do not create another nomination, bell, follow-up or dose.

## P08a canonical follow-up

The ledger source is confirm:{nominationId}, type confirm, owner the nominated colleague, due_at server-now plus 30 minutes and context.nomination_id an integer. The enduring bell URL remains /medication-followups?open={followupId}; it does not use the nomination ID as the follow-up identity.

When a readable confirm row is opened, read its context.nomination_id and mount:

```tsx
import {
    SecondPersonConfirmationDialog,
    type SecondPersonConfirmationAnswer,
} from '@/components/emar/second-person-confirmation-dialog';

<SecondPersonConfirmationDialog
    open={selectedFollowup?.type === 'confirm'}
    nominationId={selectedFollowup?.context.nomination_id ?? null}
    onOpenChange={(open) => {
        if (!open) setSelectedFollowup(null);
    }}
    onAnswered={(answer: SecondPersonConfirmationAnswer, nominationId: number) => {
        // The source has committed. Refresh the canonical follow-up list,
        // meters and relevant dose projection for every terminal status.
        // confirmed, disputed and expired are distinct outcomes.
    }}
/>
```

Props are exactly open: boolean, nominationId: number | null, onOpenChange: (open: boolean) => void and optional onAnswered: (answer, nominationId) => void. The callback answer is { status: 'confirmed' | 'disputed' | 'expired', replayed: boolean }. The dialog fetches its own authorized details; never feed cached clinical details from a bell payload into it.

Use this source dialog for confirmation answers. The generic /medication-followups/{id}/transition endpoint intentionally rejects source-owned confirm completion. Keep that guard. P08a’s can_complete affordance for confirm must use the actual named-owner/current medication-view/witness eligibility rather than granting administer.record or manager override. The source API remains authoritative if capabilities change.

After yes, the original dose becomes verified with own_session_confirmation and the actual named colleague/time. No or timeout leaves one original dose needing lead review and creates one canonical disputed lead item. Existing unrelated review reasons remain. Refresh the row instead of inferring those states locally.

## Source API

Both endpoints require an authenticated session plus medications.view middleware. The service additionally rechecks current witness permission, employment, approved Site, named ownership and per-person medication access. Missing capabilities give 403; a foreign/hidden/moved source gives 404. JSON replies are private, no-store.

GET /meds/confirmations/{nominationId} returns:

```ts
interface SecondPersonConfirmationDetails {
    id: number;
    followup_id: number;
    status: 'pending' | 'confirmed' | 'disputed' | 'expired';
    due_at: string;     // ISO timestamp
    server_now: string;
    person_name: string; // Full person name for unambiguous identification
    medication_name: string;
    given_at: string;
    recorded_by: string;
}
```

POST /meds/confirmations/{nominationId} accepts { was_there: boolean } and returns { status: 'confirmed' | 'disputed' | 'expired', replayed: boolean }. The immutable nomination plus exact boolean answer is the retained replay binding. Same answer retries return the stored outcome; opposite terminal answers cannot overwrite it (422). A reply exactly at or after due_at commits expiry and returns expired, even if the request said yes.

The dialog validates JSON shape and identity, aborts stale requests, clears private details when closed or when the actor/read permission changes, and suppresses old acknowledgements. On an unknown save outcome it offers only “Retry same answer”; redirected HTML never counts as a successful answer. Its deadline display uses server timestamps and monotonic elapsed time. The final irreversible choice uses shared ConfirmDialog; its new optional buttonClassName prop sizes both controls without changing other callers.

## Expiry and activation

Artisan command: emar:expire-second-person-confirmations. routes/console.php schedules it every minute with withoutOverlapping and onOneServer. It invokes expireDue even if no bell is opened, records system evidence and safely closes hidden-parent work. Repeated runs are idempotent.

config('medications.witness_pin.forgotten_fallback_enabled') remains false until Main integrates the P01 branch, P08a parent dialog import, truthful pending/terminal projections and this scheduler in the combined release. Main can then enable the existing flag in that integrated commit. This work creates no production secret and sets no pepper value.

## Verification boundary

The focused PHP consumer cases cover wrong owner, current access loss, exact expiry command and retained same-answer HTTP replay. The focused React cases cover malformed replies, stale targets/login, read loss, server denial, safe uncertain retry and deadline truth. Main owns combined frontend type/lint/browser checks and parent wiring. Source-only checks are distinct from passing runtime evidence; see security-review.md for the current result and machine-wide hold.
