# P02 completion

Baseline: `9747cf7cb654c2ef441e8f60c7ea1b5918081925`. Own branch: `codex/emar-p02-completion`.

Single organisation across approved sites. All reads and writes use roles, permissions, canonical person ownership and privacy; no tenant product boundaries.

1. Recover P02-1b (day MAR/report and re-offer display) and the captured P02-2 work without changing Claude's checkout.
2. Complete the record shell, current/stopped medicines, medicine details and support reading sections. Reuse PageHeader, TierTwoTabs, EntityTable and WizardShell.
3. Complete canonical allergy/review, chart alerts and clinical workflows. Preserve source allergy rows; exact normalized dedup only; copies start Not reviewed. Preserve existing policies, permissions and truthful Not configured states.
4. Enable the person chart and use the shared P01 recording dialog through one seam.
5. Complete MAR charts, medicines and as-needed history hub.
6. Finish client-profile canonical allergy/safety and medication privacy integration; stop new duplicate ClinicalObservation copies while preserving old records.

Verification: small pure UI tests and syntax checks first. DB suites, full types/build and browser servers wait for Main's verification slot. Synthetic isolated DB only. Main owns independent review, integration and pushing.

Dependency junction: `node_modules` points to the primary checkout. Never recursively delete this junction or its target. `vendor` is a physical local copy so Composer loads this checkout's application classes. The source recovery status, diff and SHA-256 hashes are saved alongside this plan.

Shared seams to report: routes/emar.php, EmarController::mar, emar-navigation/use-emar-breadcrumbs, use-dose-recorder, profile show/MAR tab, ClientController audit/safety projection, EnhancedMarService dose-vitals mirror. Global primitives and P01/P11 screens stay out of scope.
