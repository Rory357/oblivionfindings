# PKG-06B v8 — Asset QR labels and modal consistency

Status: Design candidate, synthetic data only. Production implementation is not included.

[Open Asset details and QR labels](http://127.0.0.1:8903/#view=overview&section=identity&scenario=normal).

## User decisions

Stephan requested a QR code for every asset, individual export/printing and a fully usable bulk printing tab owned by PKG-06A Assets Register. Printer choice is **both / not decided yet**, so the design supports generic A4 sheets and custom label-printer pages. The two designer chats coordinated the same AS-104 demo identity and print defaults.

## Delivered in this profile candidate

- A QR section under Asset details, with a genuine scannable QR and Print & export QR action.
- A shared WizardShell section viewer: label preview, print layout and session export history. It uses the structured 1100 px width token, shared Dialog behavior and an explicit section header.
- Actual SVG and 980 px PNG QR downloads; real PDF labels for A4 sheets or individual label-printer pages. This is a working local export, with no backend writes.
- A4 uses 70 × 40 mm labels, 2 columns × 6 rows, 3 mm gaps, 33.5 mm side margins and 21 mm top/bottom. It is a generic layout, not a claim of compatibility with a branded sheet. The starting slot lets partly used sheets be reused; skipped slots remain blank.
- Label-printer pages default to 70 × 40 mm and accept widths 60–150 mm and heights 40–100 mm. Copies accept whole numbers 1–100 per asset in this profile preview. Bulk selection belongs in Assets Register.
- Stable identity across reprints; separate active, missing, revoked, restricted and export-failure examples. Missing identities require an explicit demo action; a read-only actor cannot generate one. Failed exports retain settings and offer retry.
- Copy/page counts, required markers, focused errors and a discard guard for changed print settings. Client-owned sample labels omit the asset name; client and custodian details are never encoded or printed.
- Export history survives navigation between profile sections for this browser session. It records preparation, format, quantity and actor. Neither download nor opening a print dialog is treated as proof of physical printing.

All sample codes use https://example.invalid/assets/qr/demo-as-104 and labels visibly say DEMO · NOT A LIVE LABEL (or DEMO · NOT LIVE on narrower stock). The target intentionally cannot open a live asset. Do not attach these demonstration labels to operational equipment.

## Company branding

The later user request adds the company logo from Settings → Branding. LabelOptions receives a branding object through the profile with the existing name/logoUrl shape. The label header preserves the image aspect ratio and places it outside the QR code and quiet zone. No-logo and unavailable-logo examples print the company name instead. QR-only SVG/PNG exports remain clean codes; label PDFs and printing include the company logo.

The design embeds a rasterised copy of the repository's public/logo.svg as a labelled sample. It does not read or claim to reflect the current live Settings upload. Production must use HandleInertiaRequests branding.name/branding.logoUrl for display and embed validated public-storage bytes from AppSetting branding.logo_path for PDF jobs, following VehicleTripReportExporter. The OrgBranding::logoUrl() helper currently returns null and must not be mistaken for the configured logo provider. Snapshot the branding used for each export so a completed file and its preview agree; changing the logo must not regenerate the asset QR identity.

Four additional branded PDFs are in branded-exports/. They use the same labelSvg and labelsPdfFromPng code as the browser export, with Sharp rasterisation for source-level artifact verification. Every label decoded correctly, with exact page dimensions; the rendered 60 × 40 mm page was also visually inspected. The earlier exports/ directory contains actual pre-branding browser downloads. After adding branding, IAB exposed prepared Blob URLs but its file-download inspection timed out and no new Downloads files were observed. Therefore the final branded artifacts are **not** claimed as captured browser download bytes. An explicit prepared-file link remains visible for manual download if an automatic download is blocked. Physical printing and native printer preview remain unverified.

## Connected bulk workspace

[Open Assets Register → QR labels v8](http://127.0.0.1:4373/PKG-06A/v8/?view=qr-labels). The Asset Profile link was followed in the browser and opened the selected QR labels tab successfully. PKG-06A owns and verifies the bulk implementation; this candidate does not modify that checkout. Both previews share the AS-104 demo QR target and Settings branding contract.

## Modal rule fixes

The Finance request now imports WizardShell, WizardStepPane, WizardSuccessPane, ReviewCard and ReviewRow. Review type uses icon tiles; Finance source uses shared Popover/Command search over the permitted sample list. Review shows the readable source name. Mandatory fields are marked, invalid submission focuses the reason, and unsaved type/source/reason changes trigger the guard. Cancel/Back are on the left and the primary action on the right. Non-token modal widths were replaced by 480/720/900/1100 px variants as appropriate.

These checks cover the changed QR and Finance flows. They do not certify every modal across the application or replace the production popup acceptance review.

## Verification

Owned v8 TypeScript sources have no diagnostics. One pre-existing imported PageHeader diagnostic remains: the dusk attribute at line 160 is absent from the React heading type. The isolated bundle builds successfully.

Browser interaction evidence is in browser-results.json. The exercised flows include page counts, skipped sheet positions, focused validation, dirty guards, missing/revoked/denied states, read-only missing identity, client-owned name suppression, failure/retry, navigation-persistent history, Finance search, review and local success. Dark mode at 1024 px and the 1600 px layout were visually checked. Twenty-five browser checks passed with no runtime errors. One unsupported SVG getBBox measurement attempt is recorded as a harness limitation; its retry using rendered DOM rectangles passed. Runtime error collection is recorded separately in the browser results.

Downloaded SVG, PNG and PDFs were copied to exports/. verify-exports.py uses pypdfium2 and zxing-cpp to render and decode the actual downloaded files. PNG and every label on sample PDF pages decode to the exact intended demo URL. A4 pages measure 210 × 297 mm. Three copies beginning at position 12 produce two pages with one and two labels. Two custom labels produce two 80 × 50 mm pages. The minimum 60 × 40 mm label also decoded correctly; a clipped demo footer was shortened and its text bounds verified. Rendered pages were visually inspected for alignment and clipping. PDF label artwork is embedded at 600 dpi; the QR SVG remains vector.

The browser Print action was exercised and reports a print request. The in-app browser did not expose a native print preview for inspection. Native print pagination and physical alignment remain unverified; the verified PDF is the reliable review artifact. Test one page at 100% / actual size on the selected stock before a batch.

All 32 frozen v7 files are unchanged; see preservation.json. Production and shared source files were read only. The loopback server identifies 8821-v8 and blocks network connections via CSP.

## Production handoff requirements

The existing Asset creating hook already gives new assets a random 32-character qr_token when one is absent. Existing authenticated PNG/SVG/download routes encode the token redirect; the redirect authorizes access to the asset before opening the canonical profile. Nullable legacy tokens mean existing assets need an audited, resumable backfill. Reprints must retain valid tokens. Do not create a competing identity scheme.

Audit AssetQrTag dependencies before deciding how revoked/replacement tags relate to Asset.qr_token. The preview's revoked fixture is a proposed UX state, not proof that the current redirect enforces tag revocation. Keep intentional replacement and token revocation separate from normal reprinting.

PKG-06A owns the bulk QR tab. For production mass export, take a deduplicated selection snapshot, make selected rows versus all matching filters explicit across pages, report excluded/missing/revoked assets, and use bounded asynchronous jobs for large batches. Retain selection/layout after errors, retry only failed assets, and offer an export manifest and downloadable print files. Recheck role, approved-site, record and privacy access when selecting, generating and downloading. Selection is one organisation's permitted assets; no tenant selector or tenant-based transport is introduced.

Production labels must use the approved application origin and existing authenticated token route. A QR is an identifier, not an access grant. Changing rooms, custodians or asset names must not invalidate the label. Scanning must not silently acknowledge custody, clear a hold, record stocktake evidence or make financial decisions. Print/export audit events must remain distinct from an explicit physical-print confirmation.

Unimplemented here: live routes/data, backfill execution, durable job queue/storage/audit, real revocation behavior, permissions enforcement, server file expiry and production transport. Those require the approved implementation phase after mockup review.
