# P03 v1 — today’s code vs the design

This audit compares `origin/main` at `31d597415` (30 September 2026) with the design.

- **Verified**: I read the lines myself in this worktree.
- **Reported**: a code-search agent read them and I haven’t re-read them. Audit agents over-report, so treat these as leads.
- **Live**: seen on oblivionfindings.test at 1440 px as Demo Admin, read only.

Paths are relative to the repository root. Short names:

| Name | Path |
|---|---|
| PAGE | `resources/js/pages/emar/SelfAdmin.tsx` |
| DLG | `resources/js/pages/emar/_self-admin-dialogs.tsx` |
| CTRL | `app/Http/Controllers/Emar/EmarController.php` |
| MODEL | `app/Models/MedicationSelfAdminAssessment.php` |

## 1. The register and its dialogs

| # | Today | Status | P03 |
|---|---|---|---|
| 1.1 | `/emar/self-admin` is one page (CTRL:3531 renders `emar/SelfAdmin`). It is gated by `medications.view` (`routes/emar.php:143-145`). Live, it is a PageHero with the eyebrow “Self-administration oversight · live” and the title “Self-administration across 0 clients”. The description reads “Independence first; staff step in only where the risk assessment says so. Consent-first, NZ MOH medicines-management categories.” (PAGE:413-424). The tabs are Assessments, Reassessments due, Agreements, Per-medication scope and Activity. The demo organisation has no assessments. | Verified (wording, gate); Live | MAR & medicines › Support & self-administration, with P02’s hub PageHeader and the register as an `EntityTable` |
| 1.2 | “New assessment”, “Reassess”, “Sign agreement” and “Set scope” are shown to every `medications.view` holder (PAGE:427-433 and others). Saving needs `medications.orders.manage` (`routes/emar.php:170-178`), so support workers, team leads, finance and auditors get a 403 behind a generic toast. | Verified (routes, button); reported (other buttons) | Actions are shown only to people who can save; others see why and who can |
| 1.3 | The four dialogs (`AssessmentWizardDialog`, `SignAgreementDialog`, `MedScopeDialog`, `ViewSelfAdminDialog`) are defined in DLG and mounted only by PAGE. None of them is dead code. | Reported | Redesigned: see §5 |
| 1.4 | The assessment wizard has five steps: Person & consent · Capacity scores (1–5 each) · Capability checks (six) · Support & storage · Review & sign. The confirmation “I confirm this assessment was completed with the person.” (DLG:663) exists only in the browser; it is never sent. | Verified (checkbox); reported (steps) | Kept, with plain words and the result shown as “most independence allowed” (Q2) |
| 1.5 | The agreement dialog’s “The person has read and signed the agreement.” (DLG:766) is also browser-only. The server records only the staff member who clicked: `agreement_signed_by = $actor->id` (CTRL:6164-6167). | Verified | Records who agreed and how (Q3) |
| 1.6 | “Print assessment” prints the whole page; the detail dialog shows a raw ISO date; in reassess mode the Client field stays editable; `setInterval` is shadowed (DLG:300). | Reported | Fixed in the design |

## 2. Data and rules

| # | Today | Status | P03 |
|---|---|---|---|
| 2.1 | The result is computed from five scores (out of 25) and consent: if the person doesn’t wish to, or isn’t willing → `administered`; ≥21 → `independent`; ≥16 → `prompted`; ≥11 → `supervised`; else `administered` (MODEL:93-105). The labels are “Category 1: Independent Self-Administration” … “Category 4: Full Staff Administration” (MODEL:116-125). | Verified | Shown as the most independence allowed, in the four approved words (Q2) |
| 2.2 | Per-medicine scope accepts only `self_managed`, `prompted` or `staff_given` (CTRL:6132). There is no “Assist”, and “supervised” has no per-medicine equivalent. | Verified | Four per-medicine values: Self-managed · Prompt · Assist · Administer |
| 2.3 | Store validation (CTRL:6011-6038) accepts no `med_scope`, ordering or agreement fields, so **a reassessment creates a new row with no scope and no agreement**. Every medicine falls back to “Staff-given” and the agreement shows as unsigned. | Verified (validation); reported (the fallback, DLG:783) | Reassessment carries support and the agreement over, changing only what the reassessment changes |
| 2.4 | The server doesn’t check coherence. It accepts any scope and a signed agreement for any result (CTRL:6141-6148, 6164-6167); for example, a Category 3 person can have a medicine marked `self_managed`. | Reported | No medicine can be more independent than the assessment allows (Q2) |
| 2.5 | `client_medications.self_administered` exists but is never read or written. | Reported | Retired at build |
| 2.6 | There is no separate agreement record: the agreement is columns on the assessment row. Consent is a `wishes_to_self_administer` boolean plus free-text “people involved”; there are no names or representatives (welfare guardian, EPOA). | Reported | Agreement with who agreed, how, and when |

## 3. Recording ignores support

| # | Today | Status | P03 |
|---|---|---|---|
| 3.1 | Nothing outside the self-admin methods reads the assessment or `med_scope`. Meds today builds a due, late or upcoming row for every active scheduled medicine. | Reported (grep by the agent) | Build: Meds today and the MAR read per-medicine support (P00/P01 approved behaviour) |
| 3.2 | The only self-admin idea on the recording side is a not-given reason, `NotGivenReason::SelfAdministered` “Self-administered” (`app/Enums/Medication/NotGivenReason.php:17`, `:35`). A self-managed dose is therefore recorded as Refused or Withheld. That raises refusal incidents for high-risk or controlled medicines, and counts towards the 3-in-7-days refusal alert. | Verified (enum); reported (incident and alert paths) | Self-managed doses are listed for information and never count as late or missed (P00); Prompt and Assist are recorded as “Taken with prompting” and “Taken with assistance” (P01) |

## 4. Reassessment, consent and permissions

| # | Today | Status | P03 |
|---|---|---|---|
| 4.1 | “Due” is only `reassessment_date <= today`, shown on the page. There is no command, notification or All Tasks provider. `reassessment_trigger` is a stored label that no event sets. When the date passes, nothing changes. | Reported | Triggers create a “Reassess support” follow-up (Q4). Support stays as it is, and the record says why a reassessment is due (P00) |
| 4.2 | Consent can only change through a reassessment choosing “Does not wish to”. There is no revoke. The Consents module (“Medication Administration (Standing Order)”, `database/seeders/StandardConsentTypesSeeder.php:113-129`) isn’t linked. | Reported | “Consent changed” is recorded straight away (Q5) |
| 4.3 | There is no self-admin permission key. Create, reassess, sign and scope all need `medications.orders.manage`: admin, provider manager, coordinator and clinical lead. | Verified (route group); reported (roles) | Kept |
| 4.4 | A clinical lead has `orders.manage` but no controlled-drug permission. Any update to an assessment whose scope includes a controlled medicine returns 404, including signing the agreement (CTRL:3706-3711; confirmed by `tests/Feature/Emar/SelfAdminTest.php:283-301`). | Reported | Controlled rows are concealed and counted (the P02 rule), keep their support, and don’t block the rest |

## 5. Live reference (oblivionfindings.test, 30 September 2026, read only)

`/emar/self-admin`:
- a PageHero with the eyebrow “SELF-ADMINISTRATION OVERSIGHT · LIVE”;
- “Self-administration across 0 clients” and “New assessment”;
- the filters “All sites · 29” and “All clients · 202”;
- five tabs, and “No assessments match the current filters.”

Nothing was created or saved.
