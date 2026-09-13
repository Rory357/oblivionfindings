# Specialist workspace publication — 13 September 2026

The user authorized committing and pushing this parent task's completed work to `main`, excluding the three active Knowledge, Provisioning and Governance tasks. The publication starts from `96ae8f765b2028f4b6d7cfd7f776950cc99c3159`, which already includes the separately published Overview, ticket workflow and vendor/credential work.

## Included changes

- Modern Problems, Changes and Major Incidents registers and record pages, with consistent headers, table/card views, searchable/filterable queues, direct record links and reviewed create/edit/transition forms.
- Canonical version checks for specialist edits and transitions; current actor checks; retained validation/conflict feedback; creation results that identify the saved record.
- Explicit New Zealand maintenance-window input, including rejection of skipped or ambiguous daylight-saving times.
- Major Incident internal command notes preserve the promised audience-update deadline. Profile and relationship changes advance the canonical ticket version.
- The report component accepts an explicit date range and carries the returned range into CSV links. This does not publish the dedicated Reports page integration.

The shared specialist list retains its optional selection API used by Provisioning. The publication contains no schema migration, access grant, database reset, provider configuration or deployment.

## Excluded active work

Knowledge, diagram editing, document files, Provisioning/catalogue/drafts/attachments/HR integration and Governance changes remain with their active tasks. Mixed routes, IT index, shared wizards, sidebar, PageHeader, ticket services and controller integration are excluded. Dedicated Knowledge, Provisioning and Reports page separation therefore remains in those owners' pending shared integration. Independent My Day, Finance, Sites and other dirty work is also excluded.

## Validation

A private publication copy contains the current `main` resources plus only the 39 selected source/test files. It is not a Git worktree and does not alter served source, the shared build or a database. Generated URL helpers are compilation inputs only and are not published.

- All 22 selected PHP files pass syntax checks.
- All 12 focused frontend tests pass across four specialist suites against the publication copy.
- 38 of 39 selected source/test files match their recorded hashes from this task's completed browser verification. The later optional-selection compatibility change in `specialist-record-list.tsx` was reviewed and included in the current frontend checks.
- Earlier guarded backend checks cover specialist lifecycle, actor/version conflicts, maintenance windows and communication cadence. Their failures were corrected and affected cases passed; the final correction run included seven Major Incident cases. Those unchanged backend files are not rerun against the working database for publication.
- Earlier browser verification exercised all three registers, reviewed edits, a New Zealand maintenance window, header stability in table/card views and an internal note that left its audience-update deadline unchanged. Its disposable runtime was removed and independently checked.

The publication-copy TypeScript check passed with exit 0. Its Vite production compile passed with exit 0 in 3m46s, producing `app-YHAZvUsO.js` in the private verification output. This compile used the normal plugins with only Artisan route generation disabled, to preserve the active shared checkout; existing generated URL helpers supplied compilation inputs. No generated output is included in the commit.

The Git candidate uses an explicit 41-file allowlist: 39 source/test files and this note plus its source-hash evidence. It is prepared in a separate index, with a whitespace check, exact path comparison and source recheck before moving `main`. This narrow publication does not certify W00–W27 or full specialist lifecycle/release acceptance.
