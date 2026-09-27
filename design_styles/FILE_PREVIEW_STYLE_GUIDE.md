# File preview and download

Approved by Stephan on 27 September 2026 after review of the PKG-06B v9 Asset
Profile document viewer. This is a focused addition to Rory's design rules;
it does not change the existing upload, wizard, ownership or permission rules.

## Shared interaction

Use `resources/js/components/files/file-preview-dialog.tsx`. A single file is
a simple shared Dialog, capped at `min(92vw, 900px)`, with a labelled header,
scrollable content and a reachable Close/Download footer. Multiple business
sections still use WizardShell with `headerLabel`; do not turn file pages
into wizard steps.

- Show the readable title, original filename, verified MIME type, byte size,
  version and source where supplied. Never infer trusted MIME from a filename.
- View displays actual permitted bytes. PDF pages render inside the app with
  previous/next, page count, bounded zoom and Fit width. Images preserve their
  aspect ratio. Do not depend on a new tab or the browser's native PDF plugin.
- Offer extracted PDF page text for keyboard and assistive-technology users.
  An image uses meaningful alternative text; do not invent OCR or descriptions.
- Download is an explicit link to the original file/version. Use a safe server
  filename and attachment headers. Preparation, clicking and physical printing
  are separate outcomes; never announce a saved file without evidence.
- Loading, unavailable, blocked/quarantined, access-ended, unsupported format,
  malformed/password-protected PDF and retrieval failure have clear copy.
  Retry retains the record context. Unsupported formats may download when
  permitted; blocked files expose neither content nor a download.
- Abort outstanding loads/render jobs on close, version change or retry.
  Clear previous content before requesting another file. Ignore stale results.
  Revoke temporary object URLs. Use local bundled PDF code/worker, not a public
  document conversion service or third-party viewer.
- Preserve focus trapping, Escape/Close and focus return from shared Dialog.
  Label icon buttons, disable page/zoom limits, announce page/loading changes,
  and keep long names, controls and footer usable at desktop resize/zoom.

## Source and security contract

The caller supplies source identity and authorized same-origin URLs. Recheck
role, approved Site, canonical record/privacy ownership and file state for
both preview and download, including archived versions and linked evidence.
Do not trust a hidden button or a previous page authorization. Missing bytes
are unavailable; a database row alone is not proof of availability.

Private responses use `Cache-Control: private, no-store`, `nosniff`, a safe
Content-Disposition and the detected MIME type. Inline rendering is limited to
supported passive formats; HTML/SVG and unknown content never execute in the
application's origin. PDF scripting and external links are not executed by the
viewer. Do not weaken source access in order to add a preview.

Version replacement preserves original files and submitted evidence. A pending
or failed replacement must not supersede the last usable revision. Archive
requires its owning module's authority and reason; it is not deletion. Source
links return to the original check/work/Finance record. Showing an invoice
does not approve expenditure, release equipment or update a ledger.

## Verification

Exercise a real multi-page PDF, an image, an unsupported file, unavailable and
blocked files, expired access, failed retrieval/retry, a version switch during
loading, and closing during rendering. Verify a downloaded file's actual bytes,
not only an anchor or toast. Include archived/current identity, keyboard/focus,
long names, dark mode and desktop resize. Clearly label synthetic artifacts;
do not call a fixture-backed mockup production integration.
