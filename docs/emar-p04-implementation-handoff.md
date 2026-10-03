# P04 implementation handoff

Branch: `codex/emar-p04-orders`, base `9747cf7cb654c2ef441e8f60c7ea1b5918081925`.

Approved reference: `claude/emar-p04`, frozen `24d230ed9`, `docs/emar-design/P04/v1`.

Plan: preserve the checked canonical medication while proposed versions wait; attach source/read-back evidence and attributable checks to version snapshots; implement order check, send-back, written and allergy confirmation, stop/hold/restart, reconciliation with actual-dose provenance, and the approved Orders & reviews hub. Keep source guides read-only. No migration of live records. Main's 3 Oct direction preserves the current covering-shift/emergency authority, including for office orders.

Prerequisites: P09 `466ce69df` (local `eb6b644db`), P08a `8d799fd36` (local `f8ab349b3`) and batch correction `a5b12397f` (local `37618fbc4`). Main integrates those once; owned P04 changes follow them.

Verification preparation: Main granted focused PHP/Pest verification under `C:/Users/steph/.claude/heavy-lock.sh`. Vendor is a physical copy (Directory, no ReparsePoint); ReflectionClass(ClientMedication) and Application::inferBasePath both resolve this checkout. A node_modules junction points to the primary checkout's installed libraries; bounded Prettier and ESLint ran with this checkout as cwd and source paths. No build or browser server has run. Combined frontend and real-browser verification belong to Main.

Shared P08a seam: adds `covert-review` -> `covert_review` as a lead/source-owned type. Order checks, spoken written confirmations, second checks and reconciliation GP queries use ensureForSource. Source closures collect audit events and appendMany once after domain/authority writes. A failed chain append rolls the operation back. Stops preserve the existing lifecycle and now append a P04 action only for the initial cessation; retries do not add events. Written evidence can still be attached to a ceased phone order.

P03 seam: MedicationReconciliationApplied is emitted inside the aggregate transaction after decisions apply. Main must connect it to the canonical support reassessment workflow. The reconciliation's local flag is also retained. No duplicate support assessment model/provider is created.

Maintained allergy-class mappings are intentionally empty until an attributable clinical source is installed/reviewed. UI states Not configured; direct recorded-allergy matches block checking at every severity. Prescriber confirmation is tied to the exact current matches and checked version; changed evidence requires renewed confirmation.

Current scoped verification snapshot: meaningful regressions cover pending publication, replay, independent/lone/read-back checks, written files after cessation, allergy evidence changes, audit rollback/file cleanup, office shift denial, controlled privacy/canonical ownership, current-action filtering, summer/winter NZ reconciliation instants, actual last-dose provenance, chart drift and Respite gate release.

Shared seams anticipated: `routes/emar.php`, `EmarController` order entry methods, `ClientMedication` verification publication helper, `MedicationOrderVersion` additive fields. P02/P03/P01 page ownership remains untouched. Integration contracts and final push belong to Main.
