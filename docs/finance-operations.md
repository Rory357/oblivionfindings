# Finance Operations Notes

## Bank Transaction Imports

CSV import through Finance > Bank Transactions is the supported bank transaction import path.

Automated NZ bank-feed provider setup is controlled by `finance.bank_feeds.provider_setup_enabled`, backed by `FINANCE_BANK_FEED_PROVIDER_SETUP_ENABLED`. Leave it disabled until production API access, consent handling, and provider transaction mapping have been verified. The ASB, ANZ, BNZ, and Westpac provider classes intentionally fail with an explicit unsupported message instead of returning an empty successful sync.

## Recurring Journals, Rent, and Utilities

`GenerateRecurringJournalsJob` is for finance-owned templates in `fin_recurring_journals`: accruals, standing adjustments, and other journals that do not already have an operational source.

Scheduled site rent and utilities have dedicated operational jobs:

- `PostSiteRentJob` posts rent from site lease configuration.
- `PostSiteUtilitiesJob` posts utilities and true-ups from site utility configuration.

Do not create recurring journal templates for the same rent or utility obligations, because that will double-post the cost.

## External Integration Boundaries

Xero accounting sync has provider code for token refresh plus account, journal, bill, and contact push/pull flows. Keep it disabled for a customer organisation until OAuth credentials, tenant access, sandbox verification, and account/contact mapping have been checked. MYOB remains explicitly unsupported; use Xero sync or CSV/manual export for organisations that need external accounting handoff today.

Direct bank-feed APIs are not production-supported until provider credentials, consent handling, and transaction mapping tests are in place. Finance GL posting, CSV bank imports, payment matching, GST return generation, donor fund reporting, and IRD filing records are the supported internal flows.

IRD e-filing records can be created, validated, and submitted through the local service flow when `IRD_API_KEY` is configured. Real IRD Gateway Services certification/sandbox smoke testing remains an external go-live activity.
