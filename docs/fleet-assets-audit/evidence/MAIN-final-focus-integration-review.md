# Main review — final Settings focus integration

**Publication verified:** Original Settings Designer fast-forwards local Main to29a83522d, verifies the reviewed files and all preservation boundaries, then releases custody. Main executes the A8 push and verifies actual/local/origin main at that exact commit. [Final publication record](MAIN-publication-verification-20260928.md) supersedes the earlier execution hold in this review.

Reviewed 28 September 2026 by MAIN ASTRA. Candidate `29a83522d596787320495b920f0af40322494ea3` is a normal merge of reviewed consolidation `a3cb72895ce6682bcf39fe6276ce38955987bd7d` and reviewed focus correction `2f788b0b880e9b838c0b89b3c8eba2ecd57bf097`.

**Technical review passed; prepared successor approved for integration on its exact reviewed consolidation base.** This does not release a new Main writer or bypass the pending publication execution decision. Original serial stages and original package ownership remain as recorded. The final focus successor belongs after the consolidation stage.

Main independently checks the committed trees and packet. Only Settings `_maps.tsx`, `_ui.tsx` and the new `maps-focus.test.tsx` differ in source; all three exactly match the already-reviewed and browser-verified focus correction. The 22,431 other existing consolidation paths, including all 12,535 documentation paths, retain their Git object and mode. Added documents are confined to the two focus evidence directories. Rory's design references, architecture, shared dialog/wizard code, backend and programme records are preserved.

The owner packet contains 15 passing tests in four files, zero scoped lint errors/warnings, full TypeScript exit 0 and production build exit 0. Main reads the committed test JSON, lint JSON and process-result records and verifies the reused backend summary hash (494 tests / 5,905 assertions). Initial harness failure logs remain available: the Vite runner lacked `__dirname`; a repository-root shim corrected the harness without application/config changes. Existing build-size warnings remain disclosed. No redundant backend rerun or new browser result is claimed.

The focus behavior and two real Settings409 dialogs were previously checked by Main at user-confirmed actual Chrome125% on identical source. [Rendered evidence](MAIN-chrome-125-verification-20260928.md) records exact coverage and limitations. Whole-repository CI, the unrelated telemetry finding and final user acceptance retain their earlier status.

[Independent committed-source verification](MAIN-final-focus-integration-verification.json) records both parents, three source hashes, 34 evidence hashes, preserved counts, actual test-result checks and zero unexpected paths. Local Main remains `abc70200d`; GitHub Main remains `a4f869fe5`. No Main application merge or push occurred in this review.
