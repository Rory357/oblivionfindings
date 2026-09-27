# PKG-06B v2 consistency audit and verification inventory

Stephan requested improvements and a consistency audit against Vehicle Profile. Work remains a local design revision. v1 remains frozen. No sibling contact or production changes.

## Reference and findings

The approved Vehicle Profile v13 is identified by Main's current PKG-02B-BUILD handoff. Its published source supplies the reference: vehicle-header.tsx, overview-details.tsx, overview-documents.tsx and record-collection.tsx. The v7 document screenshot is historical visual context only; v13 and current source govern the comparison. The old v13 preview port was unavailable; no sibling server was changed.

1. **High usability impact — misleading find control.** v1's header search changed the selected view to Documents and searched only files. v2 uses the shared profile search trigger and a keyboard-accessible command palette for sections, files and original work/check references. Document filters remain local to the library.
2. **High evidence clarity — generic document history.** v1 showed the same v3/v2 narrative for every file. v2 derives the viewer from the selected source. Replacement preserves that source identity, archives the prior version and retains its reason. No real file storage or scan is implied.
3. **High custody clarity — mixed history and stale kit labels.** v1's Movement history included unrelated document events, and kit labels represented dispatch rather than current arrival. v2 filters custody history, updates assigned placement after a transfer, and shows dispatch and receipt independently for each item.
4. **Medium consistency — navigation and header.** Vehicle Profile places details, Documents and Finance within Overview. v2 follows that hierarchy, uses the same back/photo identity treatment, four legible header meters and glass/primary action buttons. Old design links resolve to their new sections. Asset-specific Custody, Location and kit journeys remain distinct; no vehicle operating features are copied.
5. **Medium consistency — collections.** v1's custom tables lacked Vehicle Profile's List/Cards choice and real kebab/context actions. v2 bundles the actual read-only VehicleRecordCollection and VehicleCollectionToggle, backed by the same EntityTable, EntityCard and Entity menus, for documents, Maintenance and kit. Synthetic data and handlers stay in PKG-06B.
6. **Medium scanning — overview and details.** Repeated identity fields delayed the next actions. v2 puts Maintenance, receipt and unavailable evidence first; details use an identity/photo column, a fact panel and an attachment shelf. A compact persistent hold continues to explain that receipt does not release it.
7. **Medium document retrieval — filtering and provenance.** v2 adds adjacent filename/type/reference/source search, Current/All history/Photos, source and availability filters, result count, clear/reset recovery, direct source navigation and differentiated unavailable/quarantined actions. No mandatory document policy or vehicle insurance requirement is invented.

## Browser checks to complete

- Exact served bundle/header and source hashes; v1 manifest preservation; no tracked app or guide changes.
- New navigation plus old Documents/Finance/check/location URL compatibility, reload and browser back.
- Profile search by section, filename and work/check reference; `/`, Escape, keyboard selection and return focus.
- Documents List/Cards, combined filters, empty recovery, kebab/right-click actions, original source, record-specific versions, replace/archive and read-only actions.
- Profile photo accepts an image, rejects a non-image, survives modal cancellation correctly, and remains browser-local.
- Summary next actions; complete/incomplete receipt, kit statuses and independent hold; movement history and assigned location after a transfer.
- Maintenance List/Cards and source links; guarded completion and retained draft on failed save.
- All subviews; denied/empty/read-only/archived states; 1600×1000, 1280×900 and 1024×768 desktop widths, dark mode token check, clipping, focus, scroll and menu placement.
- Genuine browser zoom remains separately unverified unless actually demonstrated; viewport resizing is not zoom.

The audit informs an improved existing mockup, rather than a separate analysis application. Screenshots and a compact results ledger will accompany the frozen v2 candidate.
