# W15 first catalogue desktop acceptance — 12 September 2026

Partial acceptance; two confirmed defects require correction and a fresh build/browser run. Do not mark catalogue or W15 Verified.

Owned token9482be3c7961a5d0, fingerprintabd2c0b0e73705f4053794faf3a4ee82ad5cfa2211a7eef6eff40b6b1a751c61, bootstrap67674terminal0, server38044, in-app tab22. Identity JSON proves the intended checkout, disposable schema, array mail/sync queue, CSRF enabled, and manifest8fe5fbaa7e4951ddb82a6eb66a451f13d60d766051c650b06a2b7aae5e821893 (app-C4-eA06x.js). User tab2 untouched; no resizing. Normal synthetic technician login.

At /it/setup?tab=catalogue the four fixture cards distinguish draft v2 from published v1, draft-only records, provisioning and ordinary service requests. New request opens the canonical three-step WizardShell. A screenshot was viewed inline at the existing1063x856 viewport: rail, type tiles, form and footer fit; no screenshot file is claimed. Empty-name Continue stays on details with a labelled error and focused form. Explicit equipment selection plus Enter advances to the field builder. A required question can be added and entered.

Confirmed defects:

- Switching the outcome to Provisioning displays Account as selected while the controlled value is empty. Continue correctly rejects the empty value, but the visible selection is misleading. Add an explicit empty placeholder.
- Clicking Continue from fields changes the same DOM button into the associated submit button before the native click default action finishes. The browser immediately submitted the draft instead of waiting on review. No explicit Save draft click was made. Saving state appeared, followed by confirmed Draft saved; a full page reload showed the new synthetic browser-authored request as draft v1, unpublished, one field, zero submissions. This is a real premature-save defect. Separate Continue and Save DOM identities and explicitly use type=button for navigation.

Console error query returned none. This does not establish requester/publication/approval/discard/stale recovery acceptance; those journeys remain pending. UI observation proves the new draft survived reload; no separate database inspector was run.

Tab22 closed. Exact cleanup43428terminal0 and independent postflight terminal0 prove this schema and owned directory absent. Working Herd database and provider configuration were not changed. UI corrections and regression assertions are now being verified; previous source hash bundle describes the pre-correction build.

Corrections: focused UI8passed/1file/3.68s, scoped lint0, TypeScript77752terminal0, build11313terminal0/4m20s. New app-COsBHkoO.js, manifest700811935e586063ecce1d01a51f11285d6179eaaa075fcf22fefc173aebdd79. Nineteen source hashes refreshed; ten design hashes remain unchanged. The read-only catalogue inspector is syntax checked but not yet executed.

Fresh bootstrap81045 is running for tokenb9d72a1e64c835f0/fingerprint3f603bf408b0a29224bb004a5557c5fe0c3b49c385fc0cd81b5793072234d39a. Recheck explicit review-before-save in the native browser before claiming the fix verified. Then run discard/publication/stale/requester approval journeys and inspect canonical records before exact cleanup.

Independent read-only review while the fresh schema imports: requester intake still uses the older long Dialog rather than the completed authoring wizard; this is remaining W15 UI work. Provisioning approve currently guards manage/access and terminal status but does not reject repeated already-approved decisions, distinguish a configured approver from any eligible manager, or enforce self-approval policy. These are confirmed implementation gaps for the next full lifecycle slice, not tested or repaired by this publication checkpoint.
