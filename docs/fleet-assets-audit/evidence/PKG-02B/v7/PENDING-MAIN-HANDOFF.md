# Prepared locally — do not send to Main

The user requires finishing the mockup before notifying Main. No message has been sent.

Carry forward the v6 brief, including universal geofences, a universal vehicle checklist library, GV500CG capability limits, Control Room triage ownership and separate Maintenance/Finance/release decisions.

v7 adds these implementation contracts for later review:

- Searchable configurable catalogues with explicit Add new and future reuse. Production needs stable identities, case-insensitive uniqueness, permissions, central persistence and creation history. Unique VIN/policy/document references remain identifiers; approval and lifecycle states remain controlled.
- Vehicle identity and ownership editing includes categorized uploads. Canonical documents have source ownership, version lineage, archive reasons, expiry/renewal reminders, readable review summaries and explicit conflicting-date handling.
- Finance remains the owner of fixed assets, cost centres, purchase orders, supplier invoices, approvals and accounting. Vehicle and Maintenance contexts link existing records or submit evidence-based review requests; do not duplicate spend, infer approval from a typed reference or treat a UI request as delivered without a receipt.
- Shared files may be reused as evidence by reference; immutable check/work sources must not be silently reassigned. Production private download and Finance/document permissions must be enforced server-side.
- Trip timeline location labels and coordinates follow the selected recorded point on the map. Real geocoding needs provenance and an unknown-address fallback; synthetic map labels do not establish road matching.

Detailed scope and remaining boundaries: GAP-AUDIT.md. No production release, commit or push is included.
