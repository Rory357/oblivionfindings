# PKG-02A v1 browser verification inventory

Designer: Astra Extra High. Design-only acceptance checks; no backend enforcement claim.

Required claims and checks:

- Current Client Snapshot → Location and reciprocal Relationships & governance → Consents context; shared PageHeader/Rail/TierTwoTabs, profile identity, Home breadcrumbs and source links.
- Three independent decisions: collection, staff viewing and named recipient sharing. No grant from kinship, device registration or collection permission. Self-access remains separate.
- Explicit latest observation date/time/timezone, source, accuracy and age; view refresh cannot fabricate a new observation. Stale, absent, unavailable and unknown accuracy/battery states.
- No-device, missing/unknown/wrong-purpose authority, denied viewer, expired/withdrawn decision, assignment change, loading and failed access revalidation. Denied states have no map/history/recipient details; accessible next actions.
- View-only actor hides management, withdrawal and export triggers. Export and locate commands are explanatory synthetic interactions only.
- History expand/collapse, date controls, incomplete/reversed period, no results versus loading/failure, safe retry and whole-view clearing after access loss.
- Recipient review: empty validation, searchable canonical account/evidence picker, no matches/loading/error/denial, retained selection, explicit purpose/scope, required time bounds/review, exact minutes, Calendar Cancel/Apply and picker-first Escape, Review/Back preservation, dirty-close confirmation, failed review and retry. Existing illustrative grant pre-fills the same wizard.
- Withdrawal: required reason, explicit named audience, access-ending state, collection/staff access unchanged, retained evidence distinction.
- Main navigation Find, sidebar collapse, map controls and contextual destinations remain clickable without opening live application routes.
- Viewport screenshots and overflow checks at 1280×800 and 1440×900, per Main's corrected current handoff; initial 1366×768 captures are supplementary. 200% desktop browser zoom, modal footer and picker reachability; keyboard/focus return; console errors. No mobile deliverable.

Exploratory cases: change scenario after opening history; cancel time changes after an exact-minute edit; browse calendar months without applying; jump straight to Review with missing inputs; retry a failed sharing review without duplicating a grant; withdraw recipient access without withdrawing collection.

All evidence is from a synthetic local browser UI. Production routing, backend authorization, assignment races, cache/jobs/realtime/export invalidation, timezone/DST resolution, legal authority and privacy policy need later scoped implementation and independent verification.
