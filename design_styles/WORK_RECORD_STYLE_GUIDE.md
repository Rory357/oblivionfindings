# Work-record detail layout

Approved by Stephan, 2026-09-20. This guide documents the reusable ticket-style
layout requested from the PKG-01 Maintenance design. Approval covers this design
reference, not production implementation, a new product lifecycle or acceptance
of the entire mockup.

## Where the pattern applies

Use this pattern for suitable records that track owned work, an issue or a
request through assessment, action and an outcome. Work orders and support
issues are examples. Keep the module's actual record name; “ticket-style”
describes the interaction, not a requirement to label every record Ticket.

Do not force the pattern onto dashboards, registers, calendars, read-only
documents or every HR/Finance/profile page. A module adopts the relevant layout
for its own approved record journey. This guide does not start a bulk migration.

## Build on the existing design system

The [page-header guide](PAGE_HEADER_STYLE_GUIDE.md) remains authoritative for the
page top, using the existing [PageHeader](../resources/js/components/page/page-header.tsx)
and rail. Follow the [navigation guide](NAVIGATION_STYLE_GUIDE.md) for main tabs
and meaningful sub-tabs. Keep the [app shell](APP_SHELL_STYLE_GUIDE.md),
[tokens](DESIGN_TOKENS.md) and [button rules](BUTTON_STYLE_GUIDE.md). This guide
owns the record body's hierarchy, not a replacement header, navigation skin or
second set of visual tokens.

Action dialogs follow the [popup guide](POPUP_STYLE_GUIDE.md), including its
existing simple-dialog/wizard, upload, searchable-selector and date/time rules.
Reuse appropriate primitives such as [Collapsible](../resources/js/components/ui/collapsible.tsx).
Any new production shared record component still needs implementation approval;
do not treat isolated preview components as an existing production framework.

## Shared body hierarchy

1. **Identity and context:** keep a stable reference, meaningful title and current
   status discoverable. Link to the affected resource/person/site and original
   source where relevant and permitted. Retain record context when opening
   evidence, profiles, tabs or action dialogs, with a clear return route.
2. **Summary:** show what happened or was requested, the essential facts and the
   current outcome/blocker concisely. Keep critical restrictions and required
   action visible; do not hide them inside collapsed notes or an unrelated tab.
3. **Action area:** make the next action, responsible owner and relevant target
   date easy to find. Place a module-appropriate progress control close to the
   next action, and make the permitted completion/resolution action obvious.
   Give blocked actions a useful reason and next step.
4. **Notes and activity:** use a compact collapsible section with an accurate
   count, visible Add note or Continue note, and an optional concise latest
   update. Expand to read attributable activity and use search when needed.
5. **Domain detail, evidence and history:** organise supporting information into
   clearly named sections or meaningful tabs. Preserve links to authoritative
   evidence and the original record history instead of creating a second copy.

At wide desktop sizes, use a larger summary/detail area and a narrower action
area where the content benefits from it, as in the Maintenance reference. Keep
the same reading order and visible next action as the desktop window narrows;
adapt column sizes or stack content without forcing a separate mobile design.
Avoid an oversized notes feed pushing ownership and the next action out of view.
The exact tabs and domain sections vary with the record's purpose; do not add
empty tabs or duplicate the same feed just to match another module's screenshot.

## State and interaction rules

- **Progress belongs to the module.** Reuse its actual lifecycle and permitted
  waiting reasons. Specify the mapping between display labels and authoritative
  values. Do not copy Maintenance's progress list into IT, HR or Finance, invent
  universal statuses, or present an unknown state as complete/healthy.
- **Completion is a guarded action.** Use the module's requirements, validation
  and confirmation. A terminal dropdown choice must invoke the same transition
  rules as its completion button; editing notes or display status must not bypass
  required evidence, approvals or permissions. Keep distinct domain decisions
  distinct even when one modal provides the steps.
- **Ownership is explicit.** Show the accountable person/team and the relevant
  next action. Transfer/acceptance behavior follows the module's real contract;
  this layout does not invent an acknowledgement requirement or assign work
  merely because a recipient was selected in a picker.
- **Notes retain work.** Preserve unsent text through collapse/expand and supported
  navigation/close recovery; expose Continue note when a draft exists. Use the
  approved unsaved-change and failed-save/retry behavior. Notes do not rewrite
  submitted source checks, certify an outcome or create an approval implicitly.
- **Shared references stay shared.** Where a record is surfaced in All Tasks,
  a resource profile or another module, project the same canonical identity,
  owner and relevant state with source links. Do not create an unexplained
  parallel ticket/task number or duplicate evidence/history for the layout.
- **Permissions cover every view.** Apply roles, approved sites, canonical-record
  access and privacy to links, notes, evidence, search, counts and history as
  well as buttons. Do not reveal restricted details merely because a user can
  see that work exists. A hidden or disabled control is not backend enforcement.
- **Recovery and accessibility remain consistent.** Keep entered values through
  Review/Back and recoverable failures, explain saved versus draft state, and
  provide safe retry. Use labelled controls, keyboard/focus behavior, accessible
  collapsed state and status feedback under the existing guides.

## What each module owns

The common arrangement answers: what is this record, what is happening, who is
responsible, what happens next, and where is the evidence? The module supplies
its own terminology, fields, section/tab names, allowed statuses, requirements,
permissions, notifications and lifecycle transitions. Omit inapplicable sections
and add the domain detail required by its approved journey without changing the
common interaction patterns or inventing new product policy.

For Maintenance, work completion, a vehicle/asset safety release, custody and
Finance approval/posting are independent decisions. A repair marked complete
must not imply all four occurred. IT support does not gain vehicle retests or
key custody from this guide, and Finance/HR records keep their own approval and
privacy requirements. These examples preserve domain ownership; they do not
define new workflows for those modules.

## Reference and review

PKG-01 v7 supplies the reviewed layout example: work overview, compact notes,
next action/progress, ownership, evidence and guarded completion/release.
[Main's exact artifact review](../docs/fleet-assets-audit/evidence/PKG-01-main-design-review.md#v7--independent-calendar-and-time-consistency-review)
records the identity and limitations. Its synthetic data, illustrative policies
and isolated component composition are not production defaults or completed
backend contracts. The exact Fleet mockup still has its own approval gate.

Before accepting an adopting page, verify the common hierarchy with realistic
populated, empty, waiting, blocked and completed states; long notes; permitted
and restricted actions; keyboard/focus; and draft/failed-save recovery at the
approved desktop sizes. Verify the module's actual lifecycle and source links.
Report implementation gaps honestly instead of claiming conformance from a
similar-looking screenshot.
