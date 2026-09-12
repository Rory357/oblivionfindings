# W15 durable catalogue creation — desktop verification

12 September2026. Bounded current-build acceptance passed; full W15 and the release gate remain open.

Runtime: token0f67ad218cb954e3, fingerprint2acd099dec2db597ac72c429e75df75e2f666dc0d5f7fa0d413e94ee1f3d46a8, bootstrap98720terminal0, server45884, in-app tab24. Identity JSON confirms correct checkout/disposable schema and app-BaXy6juF.js/manifestf43a2704bedf2e94473f80fe4f3e689674c3a852c369b0e3773a2c3ffcecd608. Normal synthetic technician3 login; desktop viewport unchanged. User tab2 retained. No real communications/provider operations.

Prepared a synthetic service-request draft and advanced to review. The exact-owned inspector held only the synthetic technician's row for25seconds: process9576 reported lock_held, then lock_released and terminal0; no record mutations. While save waited, the wizard displayed Creating and Cancel wait, with fields/navigation disabled. Cancel wait changed the UI to an honest unconfirmed state with Check saved result, Retry exact create and Cancel earlier create. It did not display success or imply server cancellation.

After lock release, Check saved result returned Draft saved. Read-only reconciliation proves command356114e4-4add-4a7a-a566-6d38cd7f2c31 belongs to actor3 and exactly item5, committed once, not cancelled. The item remains unpublished draft v1. Returning to catalogue refreshed the list and showed the recovered item.

A second create deliberately used a name longer than255characters. The server error remained visible in the open review without success. The author navigated back to details, corrected the name and saved successfully. Add another request from the success pane started a third independent creation and saved normally. Final inspection: four fixtures plus three intended drafts (items5/6/7), three distinct actor3 command receipts, no submissions/provisioning and no extra draft from the invalid attempt. Console error query returned none.

Tab24 closed. Exact cleanup14613terminal0 and independent postflight terminal0 confirm the exact schema and owned directory are absent. No owned runtime, row lock, build or test remains live.

Remaining browser checks: confirmed server cancellation of an uncommitted identity, reload marker recovery, same-command retry, stale edit and revoked/expired actor transitions. Existing backend/hook tests support parts of these contracts but are not browser substitutes. Server create errors currently appear in the shared recovery summary; route them to the corresponding field/step in the next UX refinement. Refresh generatedAt with catalogue data to keep the page's snapshot timestamp current. These refinements and the broader unsaved/edit draft recovery, requester tracking/intake and all remaining W15 lifecycle work remain open.
