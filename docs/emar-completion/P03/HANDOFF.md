# P03 support workflow handoff (in progress)

Approved design: 9822d78b4, docs/emar-design/P03/v1, APPROVAL.md. Governing choices: Self-managed / Prompt / Assist / Administer; score is a cap; Self-managed/Prompt need a versioned agreement; controlled medicines are Assist/Administer; new medicines remain Administer; consent withdrawal immediately Administer; independence waits for reassessment; reviews preserve support.

Worktree: C:/Users/steph/.codex/worktrees/emar-p03-support/oblivionfindings
Branch: codex/emar-p03-support. Base: 9747cf7cb654c2ef441e8f60c7ea1b5918081925. No push/merge/deploy.

## Dependency setup and cleanup

The node_modules junction was created only after this configuration proof. Exact paths:
- Link: C:/Users/steph/.codex/worktrees/emar-p03-support/oblivionfindings/node_modules
- Target: C:/Users/steph/Herd/oblivionfindings/node_modules (existing physical directory).
- The shared target is treated as read-only; no install, update, removal, or recursive cleanup against it.
- Cleanup must remove only the junction itself with a verified literal link path, never recurse through its target. Record actual creation below.

PHP vendor is a PHYSICAL copy, not a junction. Source C:/Users/steph/Herd/oblivionfindings/vendor; destination C:/Users/steph/.codex/worktrees/emar-p03-support/oblivionfindings/vendor. The vendor classloader and Application::inferBasePath must resolve this exact worktree before PHP tests.

Configuration proof, read on 3 October 2026:
- git rev-parse --show-toplevel resolves the exact worktree above.
- vite.config.ts resolves @ to path.resolve(__dirname, resources/js), with local resources/css/app.css and resources/js/app.tsx inputs. Laravel's default build output is the checkout's public/build. No explicit external output path exists.
- vitest.config.ts resolves @ with __dirname, and setupFiles/include are relative to the checkout.
- tsconfig.json baseUrl is '.', @/* resolves ./resources/js/*; include contains only the checkout's resources/js tree.
- No .env exists in this checkout. No live environment file or medication data will be copied.
- Verification will pass this worktree as cwd and its config explicitly, with uniquely named logs. Heavy checks wait for Main's slot and the machine-wide FIFO wrapper.

## Shared interfaces

P02 / Care & Support Plan: MedicationSupport::summary(Client, User) provides a permission-filtered payload. resources/js/pages/emar/support/support-panel.tsx exports MedicationSupportPanel. Omit callbacks for read-only summary. The panel never edits care-plan clinical prose. No P02-owned page/controller is edited here.

P01: DoseSlotProjection rows and ScheduledDoseStates dose entries expose support_mode (stored compatible values self_managed/prompted/assisted/staff_given). listStatus preserves self_managed. Present taken with prompting/assistance for recorded given outcomes. P01 owns the board, round and recorder presentation.

P04: MedicationSupport::mode(order, at) is the narrow support policy. A newly created order has no support change and defaults Administer. SupportReviewSources observes order creation and verification-sensitive changes. P04 must not copy medicine support to a replacement order without an explicit supported choice.

P08a: SupportFollowupAdapter uses the canonical MedicationFollowupService lifecycle. Required mappings are listed below. No duplicate review table/model ships.

## Current verification

The pure support policy/time suite passes (3 tests). The focused backend workflow suite is queued through the machine-wide heavy wrapper (one command only). PHP Reflection and Application::inferBasePath resolve this owned worktree. Targeted formatting and syntax checks run locally; full frontend types/build/browser and cross-lane integration remain with Main.

## Approved state checklist (implementation pending verification)

- Register, filters, no-assessment residents, permissions, controlled conceal/count, pagination.
- Person: By medicine / Assessment / Agreement / Changes.
- Assessment: wishes, participants, five existing scores, six existing checks, support cap, per-medicine support, storage, 3/6/12 month cadence, review, destructive loosening, discard guard, saved pane.
- Agreement: person/welfare guardian/activated EPOA, signed private attachment or verbal independent witness, ordering/person/staff/storage terms, version history, saved pane.
- Medicine support: new order within cap; established medicine more staff support; controlled cap; server canonical ownership; stale save rejection.
- Consent: one/all medicines, request direction, person’s words, NZ date/time, immediate restrictive change, unchanged support for independence request, reassessment follow-up.
- Projection: historical support, immediate withdrawal on unrecorded doses today, self-managed informational state, recording guard.

## Deliberate difference requiring Main review

Consent offline retains an unsaved draft with a truthful retry/house-lead message. The mockup's simulated queued consent is not reported as saved without a supported canonical sync envelope. No clinical support is changed before server acknowledgement.

Node dependency junction CREATED at the exact documented link/target above. Shared dependencies remain read-only. Build/source paths proven local before creation.

## Follow-up contract revision

Provisional P03 review table/model removed before commit. SupportFollowupAdapter calls canonical P08a ensure(type: reassess_support), visibleQuery and completeFromSource(sourceKey, actorId, context). P08a must add reassess_support to TYPES and LEAD_TYPES, and completeFromSource for source-authorised completion. The current service does not yet implement those two mappings. This is a declared prerequisite, not a second lifecycle. Context support_assessment_id is immutable source ownership; support_trigger and generic reason reveal no controlled medicine.

P09 prerequisite cherry-picked as 390632233 (source 466ce69df). Main should integrate the source dependency once.

Additional shared seam: one saving hook in ClientMedicationAdministration after its existing order lock. It prevents every recording path from fabricating self-managed outcomes. The provider's earlier global saving event was removed because it could run before the model's lock.
