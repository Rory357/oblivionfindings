# NZS assurance certification scope decision

Status: product decision required before provider-wide inheritance is introduced.

## Bounded implementation decision

`SiteCertification` is the existing canonical certification record and is owned by one Site. NZS-ASSURANCE-01 therefore treats a current `healthcert_certification` record as evidence only for its owning Site and only for the Ngā Paerewa certification signal. It does not imply first-aider cover, another certification type, or certification at another Site.

The application must not infer provider-wide certification from one Site record. Doing so would require an explicit product decision covering the certified legal entity, which Sites and service modules are listed on the certificate, partial suspensions, and how provider-wide revocation propagates. That decision materially changes storage and resolution semantics, so this remediation deliberately does not add provider-wide inheritance or a second certification store.

## Current evidence contract

A green certification result requires the newest Site-owned Ngā Paerewa record to be current, in force, independently reviewed by a currently approved reviewer for that Site (or the explicit global `sites.viewAll` permission), unrevoked, not soft-deleted, and backed by readable evidence on the private disk in that Site's `site-certifications/{site_id}/` namespace whose stored SHA-256 digest still matches. Missing, cross-Site, or mismatched provenance requires action; an unavailable private evidence store produces an unknown result.

Replacing or editing Ngā Paerewa evidence creates a successor record and revokes the prior head under a Site row lock. Only the current head may be edited, and changing a record into or out of the Ngā Paerewa type requires a new record rather than rewriting its scope. Historical records remain recoverable through soft deletion and audit history, while the resolver considers only the newest head so an older certificate cannot be replayed after revocation.

Per-shift first-aider coverage is a separate signal. It is resolved from Site roster intervals and current first-aid training evidence, including the canonical training record's own `hr/training/certificates/{record_id}/` evidence namespace; it never derives from `SiteCertification`. The shared hero projection evaluates scheduled or in-progress shifts in the next seven days. No rostered shifts in that bounded window is unknown, not an affirmative claim.
