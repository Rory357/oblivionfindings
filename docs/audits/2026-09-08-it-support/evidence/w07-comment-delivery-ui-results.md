# W07 comment delivery summary

New read-only component implemented; source checks passed on 9 September 2026. Thread/presenter integration and current-build browser verification remain separate. This is not whole-package W07 verification.

## Contract and files

- [Component](../../../../resources/js/components/it/ticket-comment-delivery.tsx): `TicketCommentDelivery({ delivery, onRefresh?, refreshing = false })`.
- Exported `TicketCommentDeliveryData`: `{ tracking: 'recorded' | 'unrecorded', requested: boolean, attempt_statuses: Partial<Record<'queued' | 'sending' | 'accepted' | 'delivered' | 'failed' | 'bounced' | 'retried', number>>, checked_at: string, review_url: string | null }`.
- [Focused tests](../../../../resources/js/components/it/__tests__/ticket-comment-delivery.test.tsx).

The canonical presenter supplies current leaf-attempt counts and controls audience/current authorization. The component does not calculate recipients, combine historical retry attempts, infer delivery from acceptance, or perform transport. A recorded accepted count explicitly says provider acceptance does not confirm delivery. Sending remains unconfirmed. Historical unrecorded tracking is distinct from an explicit no-notification request and from a requested notification with no attempt recorded yet. Invalid counts are withheld rather than shown as a partial success. Unknown recipient/provider fields are never rendered.

The supplied log link is preserved only for root-relative `/it/setup` URLs, including the server's query/hash scope. External, script and other paths are omitted. There is no client-generated route/filter or retry action. A supplied refresh callback owns the current authorized read, pending state and any failure handling. Refresh is a native non-submit button and is disabled while pending. The check timestamp uses the existing Auckland `formatDateTime` helper; malformed time produces a truthful unavailable label. Existing Button/StatusBadge primitives and semantic tokens are reused.

## Actual checks

- [Final focused Vitest run](w07-comment-delivery-ui-tests-final.txt): **16 passed / 1 file / 1.52s**, exit0, 20:20 NZ.
- [Scoped ESLint](w07-comment-delivery-ui-lint.txt): exit0, zero warnings, both new files.
- [Initial run](w07-comment-delivery-ui-tests.txt): 15 passed / 1 test assertion failed because this installed `en-NZ` Intl implementation abbreviates September as `Sept`, while the test expected `Sep`. The assertion now accepts both normal ICU abbreviations and still requires the correct Auckland time. Product code did not change for this correction.

No existing source, PHP, database, provider, notification, design guide or build assets changed in this slice. Full TypeScript and real desktop browser acceptance follow root's coordinated integration checkpoint.
