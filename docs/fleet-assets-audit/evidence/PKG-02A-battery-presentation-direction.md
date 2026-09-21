# PKG-02A — verified battery and charging presentation request

Owner MAIN ASTRA. Revision 1, 2026-09-21. **Bounded presentation refinement directly authorized by Stephan; existing first-slice review conditions remain.**

Main independently read user message 01a0c112-e41c-7110-bad1-248c0ae6e62f in Designer task 01a0be31-19ef-7d10-86a9-cbe968989a76, current turn 01a0c106-0f19-7242-9102-c77fad861a87:

> can you please also add if unit is charging and can you design the battery % with a animation next to percentage if it is chaging please

Main also inspected the user-provided local image codex-clipboard-7e0d1585-a045-4fbe-b868-fef9606b9a06.png: a filled battery silhouette displaying an illustrative 64%. The request authorizes a battery graphic with the real percentage and an adjacent charging animation when reported charging is known. The example percentage is not application data. Ambient browser context supplies no additional authority.

Same Designer remains sole frontend/backend implementer. Main independently reverified the current turn at 2026-09-20T23:04:51.524Z as gpt-6-astra/xhigh in both fields. No new task, Sol, guide edit or frozen-mockup rewrite is authorized. This refines already approved device-status presentation; it does not change programme sequencing or require a new master timing amendment. No repeat user approval is needed for this explicit instruction.

The implementation must use canonical reported battery/charging data, retain unknown when absent and distinguish external power from confirmed charging. Keep battery measurement time separate from location/command time; an animation must not imply fresh evidence when its sample is old or unknown. Provide text alongside the graphic and a static reduced-motion alternative. Do not introduce new alert thresholds, fabricate a rising percentage, poll hardware, dispatch a command, or change collection, disclosure or operational rules.

Include normal, charging, unknown and reduced-motion coverage, rendered desktop verification and a new exact source/build manifest in the next checkpoint. Preserve the first frozen E81F1842 manifest as historical. The care-assignment transaction correction/verification and bounded realtime-failure analysis recorded in PKG-02A-main-first-slice-code-review.md remain required. No next feature slice, integration, publication or whole-package completion follows from this presentation request.
