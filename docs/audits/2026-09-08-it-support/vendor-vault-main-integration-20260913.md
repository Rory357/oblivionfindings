# Vendor and shared credential integration — 13 September 2026

The user explicitly requested merging the verified Vendor work to main and pushing it, then completing the existing site/house-manager role mapping. That request supersedes this session's earlier no-push hold for this bounded change only.

This change extends the canonical SiteVendor and SiteCredential records: restricted commercial agreements and private versioned files, owner renewal follow-ups, independently authorized vault metadata/reveal/copy/manage/audit, step-up authentication, encrypted history, retirement and recovery. It includes the corrected four-step Add/Edit Vendor wizard and one Vendors & Credentials IT navigation destination.

The checkout already uses main. Shared navigation, routing and task-provider files are staged with only the Vendor integration changes. Unfinished Knowledge, Provisioning, Ticket, Governance and My Day changes, runtime files, database backups, synthetic fixtures and generated build assets are excluded.

Validation already completed in the saved checkout: 64 owned backend cases across guarded isolated runs; 13 latest vendor wizard/register tests; focused shared navigation checks; a successful production build; ordinary-login Herd browser checks of the combined navigation, wizard validation/review/discard and permission-specific discovery. Whole-checkout TypeScript checking still reports independent failures. These results establish the implemented scope; they do not establish completion of all W23/W24 or E19/E20/E21 acceptance criteria.

Before commit, all 36 existing UI cases across six Vendor/navigation files passed with a temporary test loader reading application and test sources from the Git index. This confirms the staged subset independently of unfinished working-copy changes. PHP syntax passed for all 45 prepared PHP files, and TypeScript syntax passed for all 16 prepared TS/TSX files. The temporary harness and generated cache remain outside the repository.

The local working database has only the separately reviewed additive migrations and approved contract view/manage grants for Finance, CEO, COO, CFO and Provider Manager. No credential-copy role grant was added. Site/house-manager mapping remains the next authorized step. A Git push does not run migrations, grant production access or deploy the application.

Cross-record links reuse the shared permission-scoped relationship adapter. The richer Knowledge workspace and its vendor-filtered documentation destination remain dependent on the separate Knowledge change landing.

The Knowledge owner released the relationship adapter, its unchanged exception and related-record UI after their relevant backend cases and focused UI checks passed. Its broader run reported 37 passes and one unrelated legacy Word fixture-size failure, with all isolation postflight checks passing. No other Knowledge implementation is included. The staged article link uses the existing `/it/knowledge?article=ID` catalogue destination until the dedicated workspace lands.
