# PKG-08 — Main Notifications alignment review

Owner: MAIN ASTRA. Revision: 1. Updated: 2026-09-28.

**Bounded result: no new blocking source finding in the Notifications alignment. T08-02 remains blocked/unverified; no integration or Main-write slot.** Reviewed exact `38239d35506bf97c4b6f865a13aa10933b4cb439`, parent `b07888e9b413696649ceceb03bbae3ee38edf166`, base/current local Main `926b4981b0289da08a20baca0995117fb53e413e`, tree `145aa80a99cd4ef03e815585f9ce9115ee38d2ab`.

## Actual authority and review scope

Main independently read the existing **OF | PKG-08 Settings & Setup | DESIGNER…** chat. User message `01a0e429-7616-77b3-93f5-6d8517eb45cc` asked “does it look like mockup?”; the same owner identified missing notification-specific cards, prominent preview/check actions, the separate Applied from column, event icons and footer/filter differences. User message `01a0e42c-1b1e-7aa2-806d-85d63cf1503c` in turn `01a0e42c-194f-7431-98d5-044400ef3320` then said “please continue”. This is corrective implementation under the existing approved v6, not a new product requirement or approval to edit design authorities.

Only `resources/js/pages/fleet-assets/settings/index.tsx` and `_notifications.tsx` change application source from the reviewed parent; the other16 paths contain evidence. T08-01's renderer is byte-identical, so its independent closure stands. Main's previous map-closure review was not complete visual acceptance of every Settings panel; the owner's later comparison correctly identified additional Notifications fidelity work.

## Design comparison and source review

Main visually compared approved frozen `v6/evidence/n6-10-preferences-1440-light.png` with the owner's committed `browser/fidelity-notifications-light-1440.png`, inspected the v6 Notifications source, and opened the actual updated application at1280×720. The restored cards, preview/check actions, notification icons, Applied from column, visible filter values, save/reload/defaults footer and rules action now follow the approved Notifications composition. This is a bounded composition comparison, not pixel identity or final all-panel acceptance.

Values come from the actual current snapshot/draft: counts3 in-app,1 email,3 personal event overrides and1 of2 configured channels in the retained fixture. The implementation correctly does not copy the mockup's invented persona, third channel or sample version/counts. Existing published shell and source-owned event descriptions remain; Rory references and shared primitives are untouched.

Source inspection confirms Save still opens the existing review before its durable PUT. Reload saved uses the existing uncached GET, confirms a dirty draft, and updates saved/draft state only after success; the catch path retains the draft. Summary callbacks reflect inherited or personal values, and the preview retains its original return target when its selected event changes. Current backend ownership, queue/delivery behaviour and permission boundaries are unchanged.

## Independent verification

- [Source verifier](PKG-08-main-fidelity-verify.mjs) and [result](PKG-08-main-fidelity-verification.json) match all40 working/committed source entries, exact39 source deltas plus the declared unchanged reference,437 frozen preview entries,10 authority files and protected Blade. Source-manifest working/Git SHA256 is `2658daa681789fb2afcdee76595ae6825e8fc11a8b26a98fc0b7516ec0895b76`. Approved v6 manifest/bundle remain `50e3df5741943eb76cc2c2b78ccb750bb56ca9cffe58d9f022d134a78b89dfb7` / `0851383d39dccf21d2618a4a8e9c622bc0e7fc1590960679a432531a2634a578`. Only the declared two runtime caches are untracked.
- Main reran the existing draft suite: **4 tests pass**,13.30 seconds. [Log](PKG-08-main-fidelity-ui.log). These are draft-logic tests; rendered interactions are separately verified below. Prior backend12/78 and map/front-end25 results remain evidence for unchanged source, not fresh reruns.
- Main's real browser at1280×720 verified Import in-app draft count3→4 and “draft choices”; cancelling reload preserved the edit; Save opened the review showing Off→On; returning and confirming reload restored count3, the original Off value and disabled Save. Main made no durable preference PUT and sent no notification/check.
- The preview selector changed Booking decisions to Import results. Escape closed the dialog and restored the header trigger; repeating from the Booking decisions menu restored that original row trigger after changing the event. The preview fits the ordinary viewport. Summary filtering shows the three overridden event types; returning to all shows four. Inherited scope shows count4 with all channel switches disabled. Main restored Personal/all and verified all eight displayed channel values match baseline.
- [Browser measurements and observations](PKG-08-main-fidelity-browser.json), [actual page screenshot](PKG-08-main-fidelity-page.png), [preview screenshot](PKG-08-main-fidelity-preview.png). Served scripts point to candidate Vite5194 and the isolated app8794 with the existing synthetic account. No operating records, provider calls, notification delivery or preference writes were used by Main.

The owner's durable save/GET roundtrip and restoration, error-free browser log, light/dark1280/1366/1440 checks, scoped lint0/0, full TypeScript exit0 and production build exit0 remain owner-run results. Their evidence is preserved in `docs/fleet-assets-audit/evidence/PKG-08-implementation/fidelity-*`; Main does not claim those operations were independently rerun. Backend sources/tests, shared map renderer and all protected design files are unchanged.

## Outstanding gate

**T08-02 genuine125% browser zoom remains blocked/unverified.** Ordinary viewport evidence above is not page zoom. Keep the existing People Locations owner-assisted setup request; no duplicate question or browser-policy workaround. Once available, the same Settings owner must verify filled preview/review/map configuration/nested discard/conflict controls and keyboard/focus at actual125%.

No Main application write, merge, push, operating migration/permission/provider/device activation, new worker or routine acknowledgement loop is released. MAIN-TELEM-01 remains open; People Locations' unpublished branding and separate T04 retain that owner's custody. Local Main is still926b4981; no fresh remote publication claim is made.
