# Vehicle record and modal audit — v7

Scope: the isolated vehicle profile, its form definitions, shared modal renderer, document ownership, Finance handoff and the latest trip feedback. This is a list of gaps found in that scope, not a claim that every possible operational state has been accepted. Production implementation is unchanged; Main has not been notified.

## Gaps corrected in the mockup

1. **Vehicle category was free text.** Category, manufacturer, model, fuel/energy type, seating presets and ownership arrangement now use searchable catalogues with explicit Add new.
2. **Custom options vanished on refresh.** New values persist in this browser and reappear in later forms. Whitespace is normalized, case-insensitive duplicates are rejected, names are limited to 80 characters and storage failure is visible. Cancelling a vehicle draft does not undo an explicitly created catalogue option.
3. **Other forms used text for repeatable choices.** Booking purpose, pickup/key location, reminder titles, service reminder lead presets and problem types now use searchable choices. Existing service types and intervals inherit persistence. Unique references, observed measurements, explanations and evidence narratives remain appropriate text/number inputs.
4. **Record pickers and catalogue creation were conflated.** Staff, providers, Finance records and document relationships resolve to scoped existing records. Lifecycle/approval states remain controlled choices. Report-only staff cannot add shared catalogue options; permitted coordinators can.
5. **Vehicle editing had document references without uploads.** Ownership, insurance and warranty uploads are now part of the reviewed save. Insurance/warranty dates are separate from their references. Manufacturer/model/fuel updates flow to the vehicle header.
6. **Documents were hard to discover and unclassified.** A dedicated Documents tab includes search, document type, reference, document date, expiry, optional Finance link, previews and missing-evidence summaries.
7. **Document replacement and history were missing.** Replace creates a new version with lineage; the original becomes superseded. Archive requires a reason and retains the original under All history. Source-owned check/Maintenance files are managed from their source rather than silently moved to the vehicle record.
8. **Document renewal had no follow-up workflow.** An expiry can create a named-owner reminder linked to the source document and vehicle calendar. Dates are validated; a reminder without an expiry is rejected. Archiving evidence does not silently close the obligation.
9. **Profile and document expiry could disagree silently.** The document summary shows its own expiry and flags a disagreement with the profile date for review.
10. **Finance was represented by generic text references.** A dedicated Finance tab links the fixed asset, cost centre, purchase orders and supplier invoices. Detail views retain amount, source, status and owning Finance workspace. Order and invoice amounts are not added together as duplicate spend.
11. **There was no evidence-based Finance handoff.** Request Finance review accepts an existing vehicle document or a new attachment, preserves the source and creates a visible pending request in the demonstration queue. Duplicate open requests for the same source/type are rejected. Existing Maintenance costs expose their corresponding Finance record and request workflow.
12. **Finance approval could be typed as a reference.** Assessment now selects an existing approved Finance record. Vehicle editing never approves spend, pays an invoice, posts a journal or disposes of the financial asset. Report-only roles do not expose Finance details.
13. **Compliance edit asked for a reference but omitted the file.** Certificate/licence uploads are available inside the evidence modal and remain attached to their compliance source.
14. **Review screens displayed opaque record IDs.** Record selections now display their readable names in the final review.
15. **Trip driver selection was not searchable.** Driver filtering uses the shared searchable record picker; it does not create staff records.
16. **Trip headings duplicated the navigation.** The extra Journey Explorer / Trip history heading block is removed. Search and filters form a compact toolbar.
17. **Journey events lacked location context.** Timeline events now show a location label and coordinates. Selecting an event highlights it, pauses replay, updates the map marker and location strip, pans the map and brings it into view. The map's recorded-point markers can also be selected. Location labels are explicit synthetic examples, not a live geocoding claim.

## Other modal families audited

- Vehicle identity, ownership, profile photo, document upload/replace/archive and Finance linking/request/detail.
- Service scheduling and reminder lead times; appointment planning, assessment, work outcome, release and compliance evidence.
- Booking request/approval, checkout/return and cancellation; reminder create/reschedule/lifecycle; mileage evidence/reconciliation.
- Check submission, check detail, reusable checklist editor, problem reporting and generic evidence upload.
- Driver confirmation/handover, event review, coaching, scoring policy, manual speed limits and approval; telemetry setup, alert response/triage and existing-work linking.
- Geofence selection/drawing/assignment, map context interactions and trip export.

The source inventory records field definitions and modal entry points. Existing shared review, required-field validation, dirty-draft handling, upload validation and success/retry patterns are retained. Browser verification is targeted at changed paths; inherited v5/v6 checks remain in their frozen evidence packages.

## Deliberate boundaries and remaining implementation work

Catalogue persistence is local to this browser origin; a production catalogue needs stable identities, permissions, central storage, retirement/merge and audit history. Uploaded files and operational records remain session-only mockup state. Production needs private durable storage, file scanning, version governance and real Finance record lookups/permissions/delivery. The mockup Finance queue is not a real notification, approval or accounting transaction.

Live reverse geocoding, road matching and speed-limit ingestion remain unconnected. Trip labels and Finance values are synthetic. Large-data search/pagination, tracker installation changes, durable event delivery, effective-date scoring governance and shared geofence/checklist publication still require implementation and acceptance. The prior PDF/Excel native-download/rendering limitations remain documented in v6; this pass did not alter the report generator.
