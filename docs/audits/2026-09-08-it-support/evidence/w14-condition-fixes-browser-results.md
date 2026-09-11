# W14 technical-check corrections — desktop verification

Mappings: W14/F06/B04/E13. This is local synthetic acceptance of the condition adapter and two browser-discovered corrections, not completion of W14 or the release gate.

## Runtime and isolation

Build98832 passed in4m1s: app-CUgUJHwk.js, manifest e3e6553f6071fe1b0ed608079d23254d158ce6628949b95b43957fe152bd5373. Bootstrap9419 terminal0, owned token e559a40d4e69454a, fingerprint75c635fdf7e81408b6499e3f836f4edba25c4ab1fbfba09b18188a5472a7effc, server44828, loopback8766. Runtime identity confirmed this checkout, the exact disposable schema, array mail, sync queue and normal CSRF. The initially guessed identity URL returned404; the actual helper route /__it-draft-verification supplied the saved identity. This was a verification-path error, not application acceptance.

Owned in-app tab18 used the naturally present1063×856 desktop viewport and loaded the current script. No resizing, mobile emulation or user-tab replacement. User tab2 and Chrome tabs preserved. Screenshot of the corrected report was inspected inline, not saved as a file. No real provider or communications were exercised.

## Observed behaviour

- Normal technician login, ticket13: System original report now reads “Monitoring confirmed a failed technical check. Technical verification is required.” Title and original report agree. First-run evidence already covers direct/urgent/recovered condition cases, sealed source links, canonical routing and private Site denial; see w14-condition-adapter-results.md.
- Setup→Operations search “delivery failed”: Device31 total/4 need attention/13 awaiting, four failed rows. Keyboard Enter opened condition delivery15. Explicit consent followed by one retry produced an honest allowance acknowledgement and recorded technical work at2/2. The result remained open for review.
- Escape closed that result and triggered the fresh authoritative GET. Search stayed in the URL, Device totals changed to31/3/13 and the completed row disappeared from the three matching failures. Focus moved to it-device-delivery-history because the originating row was no longer in the filtered result. No manual reload was used to make this pass.
- A bounded25second lock held only synthetic delivery10. Request one retry followed by Stop waiting showed that waiting had stopped without claiming server cancellation. Close details refreshed the current history after the lock released:31/2/13, only failed rows9/8, with history focus restored. The committed operation was not submitted again. Lock66982 terminal0, released with no record mutations by the helper.
- Fleet delivery5 followed the same consent/result/Escape flow. Refreshed Fleet totals5/0/0, a correct filtered-empty explanation and focus on it-fleet-delivery-history. The completed result was preserved until the operator closed it.
- Normal logout and restricted-account login concealed Device and Fleet rows and totals. Both show the current IT/source-access explanation. Restricted ticket13 still shows the safe technical-check report. Console warning/error query returned none.
- Sign-out went to the public home page; the header Log in link was used. Two initial selectors assumed the login page or an unambiguous link and failed without taking any action; inspection and a scoped header locator resolved those tool errors.

## Persisted reconciliation and cleanup

Read-only w14-condition-fixes-browser-records.json proves three new canonical open tickets24/25/26, all Site1/queue1/team1/assigned3/owner3. Each corresponding Device15/Device10/Fleet5 delivery is sent/applied, ticket_created, attempts2/limit2, with exactly one actor3 retry audit and one system completion audit. The two untouched Device failures remain at one attempt without a ticket or retry audit. Fixture ticket count23→26. All13 pending intents remain unchanged.

Tab18 closed. Cleanup17231 terminal0 and independent postflight prove the exact owned schema and directory absent. Working Herd environment/database unchanged. All27 tested source hashes and all10 protected-design hashes still match. No owned test, build, browser server, proxy or row lock remains.

Automated correction evidence: UI34 passed/3files/4.38s, full TypeScript85174 terminal0, scoped lint6335 terminal0; isolated PHP45230/token it_52218ec4af5940cd passed45/345 assertions, terminal0/all14 cleanup checks/schema absent. Earlier condition adapter Feature86/712 and actual-worker1/590 results remain applicable; exact logs and initial failures are preserved.

## Remaining work

The native Monitoring dashboard's correlation presenter still assumes availability keys and an alert-backed ticket. Direct and condition-specific ticket lookup needs canonical source/issue/Site evidence and permission checks, followed by focused tests and browser verification. This is the next dependency-ready W14 change, documented in w14-source-capability-inventory.md. Other W14/E13 criteria, operational/provider dependencies and all remaining W15–W27/release criteria remain open. Public push still awaits the previously requested public-destination approval.
