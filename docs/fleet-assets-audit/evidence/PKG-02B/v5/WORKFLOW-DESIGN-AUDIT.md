# Vehicle profile mockup — follow-up audit

Reviewed 22 September 2026 (Pacific/Auckland). Isolated v5 preview; not production acceptance. Main has not been notified. Browser evidence covers all 16 profile sections, five calendar views and targeted workflows, not every combination of state and permission.

## Corrected in this revision

- Driving insights previously displayed a hard-coded zero for speed events. There was no overspeed source, trip event, score deduction or Control Room route. The preview now includes all four, with speed, duration, threshold/tolerance, rule snapshot, source trip, location and duplicate correlation.
- Analytics now show daily vehicle scores, eligible distance, recorded distance, idle percentage, event rate per 100 km, per-trip insights and event filtering. Missing coverage is a gap rather than a zero or perfect score.
- The calculation is inspectable: trip deductions, distance weighting, coverage exclusions and vehicle versus confirmed-driver scores. Overspeed contributes once per recorded episode.
- Response-plan speed, tolerance, duration and re-arm controls use searchable presets/custom values. Saving a future draft does not rewrite an existing speed event's evidence.
- Larger square trip map, compact map/vehicle menus, hover/focus telemetry, icon-based reported state and triage-gated Maintenance handoff remain in place.

## Workflow gaps, in priority order

1. **Confirm the actual driver and handle handovers.** Bookings and custody exist, but journey attribution still uses an unconfirmed assignment. Add checkout identity confirmation, driver changes during a trip, unassigned-trip reconciliation and correction history. A booking must not silently award events to a person. Personal scores remain withheld.

2. **Review and correct events before final scoring.** No event-level confirm/dismiss/dispute workflow recalculates the score. Add a reviewer, reason, evidence, retained original, outcome and recalculation history. Resolving a Control Room alert as a false alarm currently does not alter the analytics fixture. That needs an explicit linked review action.

3. **Complete delivery and acknowledgement escalation.** New, delivery failure/retry, acknowledgement, triage, escalation, resolution and duplicate correlation are demonstrated. Real receipts, acknowledgement deadlines, unattended-shift queues and timed backup escalation are missing. Show received/acknowledged/overdue times and escalation owner. Operations must choose urgency and deadlines.

4. **Choose and version the score policy.** Weights are illustrative: 5 per braking event, 3 per acceleration event, 8 per overspeed episode and 0.5 per idle minute (rounded), starting at 100 and clamped at zero. Period vehicle scores are distance weighted; coverage below 90% is excluded. Proposed personal-score minimums of five eligible trips and 100 km are examples. Validate severity, exposure normalization, comparable trip types and sample minimums; add a versioned policy editor with effective dates and review before activation.

5. **Separate fleet thresholds from road-limit overspeeding.** A configured threshold is demonstrated. Posted-limit analysis needs a verified road/direction match, limit source/version, effective time and temporary-limit handling. Unknown limits remain unknown. Episode duration is synthetic source evidence, not inferred from five summary points. Production must validate GNSS quality, continuous duration, reporting gaps, late reports and re-arm rules.

6. **Handle tracker changes and delayed data.** Reconciliation, manual evidence and stale-source fallback are demonstrated. Device replacement/counter reset and buffered-report replay workflows are missing. Add installation time, old/new counters, baseline approval and duplicate/late-data reconciliation so service/RUC planning does not jump or double count distance.

7. **Complete the response-to-follow-up loop.** Fault-to-Maintenance creation preserves the source and requires triage. Add a visible completion signal back to Control Room, reassessment of unresolved concerns and linking to existing work to avoid duplicates. Keep work completion, alert resolution and authorised release independent.

8. **Implement shared-record governance.** Universal geofence and checklist interactions are demonstrated; shared publication, retirement, permissions and concurrent-change conflicts are not backed by a registry. Show impacted profiles before changing a shared boundary/template and keep submitted checks on their original version.

## Design improvements still worth doing

- **Trip exploration at scale:** custom date ranges, driver/event filters, a searchable paginated trip list and retained filter/selection state. Three sample cards do not scale to hundreds of trips.
- **Replay:** timed playback/pause, readable sample timestamps, gap segments and direct analytics-event navigation to the exact point. Current next/previous/slider navigation explores recorded points; it is not real-time playback.
- **Alert triage:** a compact persistent detail panel with priority, age, owner, deadline and source; rare actions in a menu. The detail dialog becomes dense.
- **Forms:** shorter field hints, optional detailed help and a concise affected-record summary. Retain canonical steps, review and dirty-state protection.
- **Confidence:** compact coverage/identity summary beside the score. Add previous-period comparison only with comparable data, plus filtered reports that retain source and policy version.
- **Coaching:** replace a generic follow-up reminder with an event-review outcome, assigned coach, acknowledgement and review date linked to the original trip.

## Boundaries

All records, coordinates, scores and events are synthetic. Form/file state resets on reload. No live tracker integration, Control Room message, emergency call, device command or production Maintenance record is created. The registry, decoder, scoring engine, episode detector and delivery service remain implementation work.

Manufacturer documentation confirms driving-behaviour monitoring and GNSS tracking, but does not validate this example's weights, speed rule or decoded subtypes. GV500CG's OBD socket supplies power only; ECU faults need a separate source. Sources: [Queclink datasheet](https://queclink.com.br/wp-content/uploads/2025/02/GV500CG.pdf), [Queclink user manual, sections 2.2 and 3.6](https://easynt.com/wp-content/uploads/2024/10/queclink-gv500cg-user-setup-manual.pdf).

Recommended next pass: driver confirmation/handover → event review/correction → acknowledgement deadline and escalation → coaching closure. These close the largest workflow gaps before adding more charts.
