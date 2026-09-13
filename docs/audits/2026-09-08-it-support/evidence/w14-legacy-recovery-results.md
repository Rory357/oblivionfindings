# W14 legacy recovery — 12 September 2026

Mappings: W14 / F06 / B04 / E13. Implemented and locally Verified for this slice. This does not complete W14 or the release gate.

- Legacy recovery now requires a persisted canonical online event and a preceding offline event for the same Device, source and correlation. Invalid versions, mismatched recorded Sites, missing faults and an intervening online event do not establish recovery.
- Control Room records the exact fault/recovery reference with mandatory audit and leaves operational status unchanged. Matching follows the original offline Signal's alert or correlated alert, rather than all alerts for a Device.
- IT recovery requires the exact fault in canonical monitoring ticket history and checks that it is the ticket's latest fault. Delayed recovery cannot mark a newer fault recovered. Recovery that preceded IT creation can be recovered from canonical event history.
- The existing permission-filtered recovery presenter and approved workspace notice support legacy evidence. Raw recovery references remain removed from general workspace context. Actual fault history is retained without inventing native Monitor observations.
- Original report author is System only for system-created tickets without a requester reference; existing human names and unresolved user references are preserved.
- Focused formatter passed. Combined isolated run2690/tokenit_1d2b575c1817450f passed **76 tests /545 assertions /209.62s /terminal0** with all14 isolation checks and exact schema absence. Browser fixture adds a labelled synthetic legacy recovery alongside existing native cases; no working database changes.
- Main checkpoint 600851723 remains local: automatic approval review rejected public-origin push; explicit public-destination approval pending. Subsequent changes remain local.

Next: legacy recovery-before-offline-source-delivery acceptance, Fleet IT intake, urgent operator handoff and delivery retry UI remain open.

## Browser bootstrap finding and correction

Browser bootstrap49092/rune8130bfd54234791 stopped terminal1 before starting HTTP because the synthetic legacy alert lacked recovery evidence, although its ticket recorded recovery. Three guarded diagnostic reproductions used the exact owned schema, verified runtime isolation and an outer rollback; no Devices remained after each rollback. Redacted metadata in w14-legacy-recovery-browser-diagnostic.txt proved that Signal.occurred_at was the receipt time, while the canonical DeviceEvent occurred one minute earlier. The shared Signal.setReceivedAtAttribute compatibility setter overwrote the explicit source occurrence. Earlier backend coverage used current-time recovery and missed this local-runtime difference.

The setter now treats received_at as a fallback only, preserving explicit occurred_at in either input order. The regression now delivers a historical recovery and checks the persisted Signal time as well as the open alert/recovery evidence. The focused suite is expanded to shared Control Room processing and Fleet recovery; run13412/tokenit_65dffe695b254636 passed **104 tests /721 assertions /205.86s /terminal0**, all14 isolation cleanup checks/schema absent. Fresh browser bootstrap95457/run217874f910344249 is active against the unchanged fingerprinted fixture/assets. Exact current8-file bundle: w14-legacy-recovery-final-source-hashes.json. All10 protected design baselines match. The failed browser environment was removed through the original fingerprint gate (cleanup57520 terminal0); independent postflight confirms exact schema/root absence. No browser success claimed yet.

Final browser closure supersedes the preceding active/pending notes: bootstrap95457 completed terminal0; technician/restricted-source journeys, keyboard Evidence navigation, real linked open ticket, System and human original-report attribution,403 source denial and Back recovery passed. Owned tab5 closed. Cleanup65975 terminal0 and independent schema/root absence passed. Exact eight source hashes still match. See w14-legacy-recovery-browser-results.md. No active owned runtime/schema remains; no full W14, CI or release acceptance is claimed.
