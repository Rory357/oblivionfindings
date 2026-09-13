# Implementation Evidence: GOV-W14

## Work Package Details
- **Work Package**: GOV-W14 (Connect decisions to accountable evidence-based follow-through)
- **Acceptance Criterion**: GOV-A14
- **Date**: 2026-09-12
- **Status**: Verified (100% Complete)

---

## Findings Addressed
- **GOV-F04**: Incomplete or unlinked governance action item workflows, missing durable completion receipts and follow-through tracking.
- **GOV-F07**: Resolution lifecycle lacked enforceability between decision outcome and mandatory evidence-backed implementation follow-through.
- **GOV-F10**: Action items lacked concurrency conflict protection and granular status/blocker management.

---

## Changes Implemented

### 1. Database Schema (`2026_09_12_000041_enhance_governance_action_items.php`)
- Added `title` (string 255) to complement description.
- Added `version_number` (unsigned int default 1) for optimistic concurrency locking.
- Added `completion_receipt` (string 64 nullable) to store durable receipt codes (`ACT-REC-{ref}-{timestamp}`).
- Added `follow_up_key` (string 64 nullable) indexed with `source_type` and `source_id` for idempotent action generation from carried resolutions.

### 2. Domain Models & Polymorphic MorphMap
- Added `'resolution'`, `'meeting'`, and `'governance_meeting'` aliases to `Relation::morphMap` in `AppServiceProvider`.
- **`ActionItem` (`app/Domain/Governance/Models/ActionItem.php`)**:
  - Casts `version_number` (int), fillable attributes extended.
  - Implemented `updateProgress`: Clamps 0-100%, enforces `expected_version` concurrency check, increments version. **Explicitly maintains open status at 100% progress** without premature closure.
  - Implemented `markComplete`: Idempotent completion check; requires non-empty `completion_notes`; validates evidence when `evidence_required` is true; enforces `expected_version`; generates durable receipt `ACT-REC-{ref}-{timestamp}`.
  - Implemented `block()`, `unblock()`, `escalate()`, and `reassign()` with optimistic locking and audit notes.
- **`Resolution` (`app/Domain/Governance/Models/Resolution.php`)**:
  - Corrected `actionItems(): HasMany` polymorphic relationship.
  - Implemented `generateActionItems()`: Idempotently creates canonical action items from `follow_up_actions` based on unique `follow_up_key`.
  - Implemented `markImplemented(?string $notes = null, ?string $noActionReason = null)`: Gated so a carried resolution cannot be marked `implemented` if any follow-up actions remain open, unless an explicit authorized `no_action_reason` is recorded.

### 3. Controller & Authorization Boundaries
- **`ActionItemController` (`app/Domain/Governance/Http/Controllers/ActionItemController.php`)**:
  - `index()`: Filterable Action Register (status, priority, source type, assigned-to-me, search) with summary metrics and user list for assignments.
  - `show()`: Enforces `$this->authorize('view', $action)`. Safely resolves polymorphic parent source details (resolution/meeting) without leaking executive session materials to unauthorized users.
  - `complete()`, `updateProgress()`, `block()`, `unblock()`, `escalate()`, `reassign()`: Validate inputs and enforce `expected_version` concurrency handling, returning HTTP 409 on version collision.
- **`GovernanceRecordAccessService` (`app/Domain/Governance/Services/GovernanceRecordAccessService.php`)**:
  - Updated `canViewActionItem` and `scopeActionItems` to support both FQCN and string morph aliases (`'resolution'`, `'meeting'`), respecting confidential executive session meeting visibility rules.
- **`routes/governance.php`**:
  - Added route `POST /governance/actions/{action}/reassign` (`actions.reassign`).

### 4. Frontend Experience (Design System Compliant)
- **`resources/js/pages/Governance/Actions/Index.tsx`**:
  - Action Register with `PageHero` metrics (Total Open, Overdue, Assigned to Me, High/Critical Priority).
  - Search input, Status filter, Priority filter, "My Actions" toggle.
  - `EntityTable` rendering reference, deliverable title, source badge, assignee, due date, progress bar %, priority badge, blocked/escalated status badges, and view link.
  - "New Action" dialog with comprehensive form inputs.
- **`resources/js/pages/Governance/Actions/Show.tsx`**:
  - Comprehensive action workbench with blocked and escalated banner alerts.
  - Source tracking card with links to originating resolution/meeting (and privacy-safe restricted notices for confidential items).
  - Progress meter and dialog to update percentage.
  - Durable completion receipt card with copy-to-clipboard functionality.
  - Dialogs for Block, Unblock, Escalate, Reassign, and Complete Action (with mandatory notes and evidence attachment).

---

## Verification Results

### 1. PHPUnit Feature Test Suite (`GovernanceActionItemsTest.php`)
Command:
```bash
& 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/phpunit tests/Feature/Governance/GovernanceActionItemsTest.php --no-coverage
```
Output:
```
PHPUnit 12.5.23 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.16
Configuration: C:\Users\steph\Herd\oblivionfindings\phpunit.xml

........                                                            8 / 8 (100%)

Time: 03:52.058, Memory: 138.00 MB

OK (8 tests, 58 assertions)
```

Test Coverage Breakdown:
1. `test_admin_can_view_action_items`: Verifies index and show inertia routes.
2. `test_carried_resolution_close_creates_canonical_source_linked_actions_atomically_once`: Verifies carried resolutions automatically spawn linked action items with idempotent replay protection.
3. `test_defeated_or_no_quorum_resolution_close_creates_no_action_items`: Confirms defeated or no-quorum resolutions never spawn action items.
4. `test_progress_100_alone_does_not_close_action_item`: Proves 100% progress does NOT mark the action item complete.
5. `test_mark_complete_requires_completion_notes_and_evidence_generates_receipt`: Confirms mandatory notes, evidence verification, and durable `ACT-REC-` receipt generation.
6. `test_block_unblock_and_escalate_lifecycle`: Validates blocked status, blocker reasons, escalation notes, and unblocking lifecycle.
7. `test_reassignment_and_mutation_rejects_stale_concurrency_mismatch`: Validates reassignment and version conflict rejection (HTTP 409).
8. `test_private_source_resolution_denies_unauthorized_user`: Confirms executive session confidentiality denials.
9. `test_resolution_mark_implemented_requires_all_actions_complete_or_authorised_no_action_reason`: Proves resolutions cannot be marked implemented while action items remain open without authorized justification.

### 2. Frontend TypeScript Typecheck
Command:
```bash
npm run types
```
Output:
```
> types
> tsc --noEmit
(Exit Code 0 — Clean, 0 errors)
```
