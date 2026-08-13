# Client-money governance

Oblivion Findings has one canonical client-money ledger: `ClientFund`,
`ClientFundTransaction`, and `ClientFundTransactionService`. Client funds are
segregated by Client and fund within the single operating organisation. Site
access, explicit client-fund permissions, record ownership, and direct-object
denial are the security boundaries; there is no tenant boundary.

## Transaction policy

- Credits below the configured threshold may be applied by an authorised fund
  custodian. Credits at or above the threshold require an independent checker.
- Debits, transfers, and reversals always require an independent checker. The
  maker cannot approve or reject their own request.
- Pending and rejected requests have no balance or GL effect. Approval rechecks
  the checker permission and current Site/client access, locks every affected
  fund in stable ID order, and applies the effect once.
- A transfer is limited to two active funds owned by the same Client at the same
  Site and using the same currency. Cross-client, cross-Site, missing, or stale
  identifiers fail as inaccessible before any effect.
- A reversal is a separately approved, linked, equal-and-opposite transaction.
  A database uniqueness constraint permits one reversal per original, and the
  original journal records the linked reversing journal.

## Available balance and overdraft policy

The normal product state is `overdraft_policy_state = prohibited`; a debit or
transfer cannot take `available_balance` below zero. This is not bypassed by an
administrator role.

The service recognises `authorized` only when all of the following durable
governance evidence is present on the fund: a positive limit, authorising user,
authorization timestamp, and non-empty authorization reason. The existing
Client Funds create/edit UI deliberately cannot set those fields. Enabling or
changing this exceptional policy requires a separately approved product
workflow with delegated authority, evidence retention, expiry/review rules, and
operational reporting. Direct database changes are not an authorised workflow.

## Posting and reconciliation

Approved effects are durable before asynchronous GL posting. A failed post
leaves the transaction in `approved`, records failure evidence, and is retried by
the existing unique job and scheduled recovery sweep. Row locks plus the source
transaction journal link prevent duplicate effects.

Every Client Trust Funds (2500) journal line carries Client, fund, and Site
dimensions. Reconciliation compares:

1. the stored fund balance to applied canonical subledger effects; and
2. posted subledger effects to the matching 2500 GL Client/fund/Site dimension.

This detects cross-client or cross-fund allocation errors even if organisation-
wide debits and credits still net to the correct aggregate. `clear`, `review`,
and `mismatch` are deterministic stored outcomes shown on the existing fund
surfaces.

## Legacy records

Migration never deletes or silently approves historical money records. Existing
effects are retained with `status = review`, their prior applied effect is
preserved, and the containing fund is marked `review_required`. Existing
negative balances have zero available balance and remain review items; they are
not converted into an authorised overdraft.
