# W01 fine-capability desktop browser evidence — 9 September 2026

Actual Codex in-app browser at the correct local checkout, second build `app-eTyglvnK.js`, primary desktop 1440 × 900. Each role used normal login/logout. These are focused permission/lifecycle checks, not W21/W24 or whole-module acceptance.

## Knowledge author and reviewer

Synthetic author 234 has only `it.knowledge.author` and approved Site A. Its navigation offered Knowledge, without the ticket queue, Setup, Reports, ticket creation or ticket meters. Knowledge showed permitted Site A and staff guidance; Site C draft 9 remained absent even though this actor is its author/owner. Direct browser navigation to `/it/tickets/9` returned 403.

For synthetic draft 8, Actions offered Edit, Send for review and Delete draft; no publication control. Keyboard Enter opened Edit. The screenshot confirmed the existing title was populated; the browser tool's DOM snapshot and evaluated initial input value misleadingly appeared blank, so that observation was **not** treated as an application defect. Existing body/preview were present. The author changed the title to `W01 Fine Site A draft — browser reviewed`; the audience step offered only approved Site A. Save completed, retained Draft state, displayed the acknowledged Article saved pane and updated the underlying list. Send for review persisted In review; the author then had only Return to draft, with no publish/edit action.

Synthetic reviewer 235 has only `it.knowledge.review`, Site A, no author or ticket grants. It had no New KB article. It read the submitted body in the canonical reader, closed with Escape and used the only in-review action, Approve & publish, with Enter. The completed list showed Published. Retirement with an empty reason was blocked and kept the dialog open. A specific synthetic-test retirement reason completed retirement. Read-only SQL confirms review_started_at 02:48:05 UTC, published_at 02:50:55 and retired_at 02:51:47; Site C draft 9 is unchanged (`w01-fine-browser-records-retired.json`). Only synthetic article 8 was edited/published/retired.

## Credential auditor

Synthetic auditor 236 has exactly `credentials.view`, `credentials.audit`, `sites.viewAny` and approved Site A; no reveal or manage grant. Its first Vendors visit reached email verification. This was a **fixture defect**: the creation helper passed non-fillable email_verified_at to User::create, which silently omitted it. A separate reviewed helper set only that timestamp and updated_at on exact synthetic users 234–236. Preview/result files record previously false and subsequently true verification plus all other user fields unchanged. No verification email was sent. Do not change User fillable or bypass the production verification middleware.

After correction, the canonical `/vendors` list showed only credential 1 at Site A. Its menu offered View details and Reveal history, without reveal, copy, rotation or edit actions. Keyboard Enter opened history, displaying exactly the permitted synthetic creation event. Clearing the local search still showed only that one event. A copied `/sites/9405/credentials/2/audit` URL in a second in-app tab returned **404 Not Found**. Details showed a disabled Show password control with the explicit missing-reveal-permission explanation, while Reveal history remained available. No credential value was revealed or copied, and evidence contains only metadata.

## Remaining work

W21 still supplies immutable publication revisions, revision comparison, broader documentation navigation and full failure/draft lifecycle. In particular, the existing retirement UI closes its draft before a server acknowledgement; that failure/retry improvement remains open. W24 still supplies truthful external rotation versus storage-key maintenance, step-up/copy recovery and the complete shared vault lifecycle. Existing backend and UI revocation tests remain separate from these actual browser observations; no claim of full provider, operational or release acceptance follows.
