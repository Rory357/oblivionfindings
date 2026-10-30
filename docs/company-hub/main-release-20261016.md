# Company Hub main integration — 16 October 2026

The user authorised merging the completed Company Hub work into local main and GitHub main, then pushing. This supersedes earlier notes stating that publication to Git was not yet authorised.

Local main was fast-forwarded from d48ea17e5 to GitHub main1c77a0115 before adding the Hub changes. The unrelated routes/web.php working change was temporarily safeguarded and restored successfully. Other chat, workforce, handover, medication and calendar work remains outside this Hub commit.

The commit includes the Hub implementation, canonical HR event/recognition integration, required shared navigation/draft-protection components, migrations, tests, mockups and verification evidence. The shared Composer files were staged with only smalot/pdfparser2.13.1 and its lock entry; chat dependencies remain intact in the working copies and were not swept into this release. JavaScript package changes belong to the separate chat work and are excluded.

Verification before commit: no missing local TypeScript/PHP imports in the staged Hub files; isolated PDF-only Composer manifests validate, with the expected advisory about an intentionally pinned version. The actual previous full build and feature-test evidence remain recorded in audit-remediation-20261016.md. This integration changes no Hub product logic and does not claim a new full-suite run after bringing in unrelated upstream eMAR commits.

Git publication is distinct from deployment. Deployment migrations, queue workers and actual outbound delivery retain the checks described in the acceptance register.
