# MAIN-TELEM-01 — bounded correction review

MAIN ASTRA,28 September2026. Published-source defect confirmed. **Read-only preparation; no application writer transfer or alert-policy approval.** This does not change PKG-08's frozen candidate or Rory's design references.

The existing Settings Designer traced both authenticated HTTP telemetry and paired native Queclink intake to the normalisation layer. Main independently reran both real adapters in memory and the existing zone classifier, without application bootstrap, database, device or network access. See `MAIN-telemetry-accuracy-probe.php` and JSON. An initial probe invocation used the generic normaliser's wrong method signature and was corrected to use its actual GenericAdapter; no product code changed.

Both Queclink and Generic currently turn HDOP1.8 into `accuracy_m:1.8`; explicit accuracy7 wins and absent quality returns null. The generic fallback is in `app/Services/TelemetryNormalizer.php:27`, extending the original Queclink finding. Existing permitted raw payload/vendor metadata already carries the dimensionless HDOP provenance. No assumed conversion factor is justified.

Main also confirms that `ClientZonePosition::classify()` treats missing metre accuracy as a one-metre boundary margin. A synthetic circle with no accuracy returns `inside` at its centre and `outside` beyond the boundary. Thus simply deleting the HDOP fallback does not necessarily remove false certainty and can make classification more confident. The monitoring service already has handling for unknown/uncertain results; that alone does not authorize changing which observations produce those results.

## Concrete proposed repair

1. Remove both HDOP-to-metres fallbacks. Define supported metre input semantics per adapter and validate finite, non-negative, representable values with an explicit conservative fractional rule. Unsupported units and absent/invalid values remain unknown.
2. Preserve HDOP only in existing privacy-permitted provenance. A new position lacking metre accuracy must not inherit accuracy from an older position. Retain initial and late withholding of coordinates and quality. Do not rewrite historical records or send tracker commands.
3. Proposed dependent alert behavior: classify observations without trustworthy metre accuracy as `unknown`, rather than assuming one-metre certainty. Such observations would neither start nor clear a zone breach under the current unknown/uncertain handling. This changes alert behavior and requires an explicit scope decision before execution; it is not silently included in a cosmetic Fleet setting change.
4. Verify routed HTTP/native intake, explicit/missing/invalid/boundary values, subsequent observations, duplicates and binding denial; event/snapshot/device/authorized Vehicle and Client projections; initial/late privacy withholding; and zone evaluation/queued projection without spurious or cleared breaches.

Reports09B retains the sole application writer slot. Settings has provided source inspection only and remains frozen. Main must resolve the alert-behavior scope and transfer custody explicitly before this correction starts. The finding remains open; published-source inclusion is not a cross-location accuracy or operating-alert sign-off.
