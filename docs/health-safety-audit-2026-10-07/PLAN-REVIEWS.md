# Three-pass review of the option 3 plan

**7 October 2026 · Planning review, not code verification**

The user requested two to three reviews and emphasized assignable incident ownership. The passes below are sequential reviews by this chat against the actual source and the proposed documents. They are not independent-agent reviews, test runs or proof that the proposed workflows already work.

**Subsequent clarification alignment, 7 October:** after these reviews, the user required full end-to-end implementation and, in the Devices chat, one complete final release. The earlier pass-3 references to an independent incident-page release and release slices are historical review findings superseded by this instruction. Current DELIVERY-PLAN.md and INCIDENT-RECONSTRUCTION.md use internal build/test checkpoints and one combined final gate. The user's actual Devices replies were read; configurable media/location/export access and the recommended assisted/manual/designated-reader attendance modes were aligned in the shared contract. Rory conformance remains mandatory throughout. This update reconciles the plans; it is not an additional runtime test or implementation claim.

## Pass 1 — Requirements, ownership and feature coverage

**Method:** compared the initial detailed plan with the original 21-entry navigation map, 16 audit findings, 30 core scenarios, register parity checklist, integration families and the user's ownership clarification. Inspected `ClientIncident`, `ClientIncidentProvider`, incident routes/policy, `Site`/`SiteContact`, closure service and Rory's work-record guide. Checked that 28 numbered packages and 20 scope rows were present.

| Gap found | Improvement made | Location |
| --- | --- | --- |
| The first specification named response/review/action owners but lacked an explicit accountable Incident owner | Added that role, assign/reassign/history, separate responsibilities and user clarification that ownership can change | SPECIFICATION.md, OWNERSHIP.md |
| Legacy investigation assignee is still displayed in All Tasks although incident assignment has no current write contract | Added dedicated ownership domain/API work before the page; prohibited reuse of legacy investigator as owner; required canonical task projection | HS03–04, O10 |
| Site manager contact is not necessarily an authenticated assignable staff account | Required explicit eligible-user configuration; prohibited name/email matching and arbitrary fallback | OWNERSHIP.md §2–3 |
| Older ten-package outline could be mistaken for the new sequence and omit ownership prerequisites | Declared detailed HS00–HS27 plan authoritative for sequencing; retained original acceptance baseline | README.md, IMPLEMENTATION-AND-ACCEPTANCE.md |
| Ownership could be misunderstood as permanent or as changing with every task | Recorded the user's clarification; recommended one accountable person at a time with intentional reassignment | D01, OWNERSHIP.md §1/4/10 |

**Result at pass 1:** scope covers all original feature families plus real incident ownership. Default recipient was then pending; the user subsequently selected the site-manager/coordinator default, recorded in pass 2. Layout does not invent H&S or shift-handover acceptance, and no ownership change implies broader source access.

## Pass 2 — Workflow and failure-state review

**Method:** walked ordinary reporting, after-hours response, manager-conflict safeguarding, owner absence, multi-person occurrences, delegated/rejected actions, changing severity, failed delivery and legacy closure through the plan. Rechecked `IncidentJourneyPresenter::nextAction()` and the existing incident closure gate. Researched six product families through primary vendor/help documentation plus current NZ sector guidance; distinguished documented features from our design inferences. Incorporated the user's second answer and verified their original Security & Devices cross-chat instruction before coordinating.

| Gap found | Improvement made | Location |
| --- | --- | --- |
| Next-action ordering can offer H&S acceptance before live response; No action available falls back to a complete stage | Required approved urgency presentation and actual domain-derived completion independent of viewer capability | HS07, X02, specification action panel |
| Initial site-manager routing did not fully explain overlapping rules, manual overrides and reclassification | Added deterministic precedence, dry-run explanation, ambiguity queue and versioned impact preview without silent reassignment | OWNERSHIP.md §3, X09 |
| Generic priority/deadline wording could flatten actual/potential harm and several independent clocks | Added versioned reassessment, preserved elapsed history and no blanket pause for external waits | HS10, X01/X05/X08 |
| One-incident wording could collapse legitimate person reports from the same occurrence or repeated events | Added source-linked multi-person branches, distinct metric units and reviewed duplicate/theme handling | HS22/24/25, X04/X06/X07 |
| Market patterns needed translation into this existing architecture | Added six-source-family research, 24-variable matrix, template/owner-return/learning refinements and explicit features not copied | MARKET-RESEARCH.md |
| Live cameras and cross-device evidence were not an explicit deliverable | Added S21 and shared reconstruction contract, early SD-01/02 prerequisites, HS22c/d and R01–R10 | INCIDENT-RECONSTRUCTION.md, delivery/spec/integrations |
| Default recipient remained a planning assumption | Adopted user's site-manager/coordinator selection with monitored fallback and specialist eligibility | D02, OWNERSHIP.md |

**Result:** 12 additional variable-sensitive X scenarios and 10 shared reconstruction R scenarios complement the 30 original C and 16 ownership O scenarios. Changes preserve separate domain lifecycles and current privacy. The source defects are acknowledged without claiming a deployed missed fall, lost original source record or downgraded critical severity.

## Pass 3 — Delivery, migration and cross-document review

**Method:** reviewed the package dependency order, source compatibility, owner migration/backfill, closure activation, phased rollout, provider/hardware dependencies, document links, scope IDs, scenario IDs and both chats' reconstruction agreement. Rechecked the mocked routing in `MilesightWebhookContractTest`. Verified the initial audit's 22 source hashes still match the inspected main baseline. No application test suite was run for these documentation changes.

| Risk found | Improvement made | Location |
| --- | --- | --- |
| The cross-domain next-action UI needs the urgency contract, while Control Room work was initially sequenced after that UI | Removed the implicit dependency loop: HS10/11 use existing source contracts after HS00/02, run alongside ownership/page work and join at HS07 | HS07/10/11 and milestone sequencing |
| Hardware/provider readiness could accidentally block the ordinary incident-page release, or conversely be implied by it | Added separate readiness gates for ordinary pages, triage, sensor response, reconstruction, attendance and notifications | Activation ledger; M0; HS22d |
| The final package exit still named only the original C/O sets | Added X/R sets explicitly and distinguished a release slice from whole-programme completion | HS27 and §8 |
| New owner-required closure could strand legacy records or tempt invented backfill | Made that gate a separate activation decision; nullable compatibility, evidence-backed current assignments and historical immutability remain explicit | Ownership migration and activation ledger |
| Coordination text described a reply as sent although the other chat instead read and acknowledged the shared document | Recorded actual shared-document acknowledgement and agreement; linked the Devices question list for reuse | INCIDENT-RECONSTRUCTION.md §7 |
| Documentation could overstate verification or market completeness | Preserved source/browser limitations, vendor-document-only evidence and hardware witness gates; verified local links/format/IDs only | Research, audit and release evidence |
| User clarified during the final pass that the app is development-only and response/evidence settings need flexibility | Adopted shared D1/D2/D3 pilot levels, internal/external/hybrid response and configurable bounded evidence windows; kept operational gates future-facing | Plan context/HS11/HS22c, reconstruction, V25/V26 |

**Final result:** 28 unique main work packages (with scoped specialist subpackages and early shared safety fixes), 21 scope rows, 26 variable rows and 68 unique named scenarios: C30 + O16 + X12 + R10. All local Markdown targets resolve; no conflict markers/trailing whitespace were found; the original source hashes remain consistent. The selected architecture and default routing are settled. Actual policy/configuration/hardware details are bounded future activation decisions; labelled synthetic fixtures allow development to proceed. Existing evidence and the other chat's answers are reused before asking further questions.

This completes three review passes of the plan. It does not claim implementation, fresh application-test passes, a commissioned camera integration or production readiness.
