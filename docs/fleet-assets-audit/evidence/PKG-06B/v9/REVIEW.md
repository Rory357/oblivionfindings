# PKG-06B v9 — Viewable and downloadable documents

Design candidate for review; synthetic files only. No production source, routes or storage were changed.

[Open Documents](http://127.0.0.1:8904/#view=overview&section=library&scenario=normal).

## Cause and correction

The v8 library marked fixture records Available but offered a metadata cover rather than file content, and had no View/Download implementation. This candidate supplies real labelled sample files: the current two-page operating manual, the warranty PDF, the archived manual PDF and an illustrative JPEG attachment. The JPEG does not represent an actual inspection photograph. None of the PDFs supplies equipment instructions or real contractual terms.

Available and archived files have View document and Download actions from the list/cards and record menu. The 900 px shared ModalFrame viewer renders the actual PDF page images, supports next/previous page, zoom and fit width, and exposes extracted page text. Files are downloadable from the viewer toolbar and footer. Version history opens the retained original version and its download, without changing the current version. Unavailable, quarantined and pending-verification records have explicit blocking copy and no file links. Staged uploads still await verification; they are not represented as available files.

Native Open PDF in a new tab was removed after the embedded browser did not show a new tab. Viewing stays inside the modal, with reliable HTTP downloads for the original files.

## Verification

- Isolated bundle builds. Owned TypeScript sources have zero diagnostics; the existing imported PageHeader dusk-attribute TS2322 remains unchanged.
- Three PDFs generated with ReportLab, text extracted and all four pages rendered with pypdfium2. Every PDF page and the JPEG were visually inspected without clipping.
- Browser verified the warranty and manual viewer, next/previous page, zoom, fit width, accessible page text, archived version, image viewer, list/card actions and version history, read-only actions and the denied scenario. Missing and quarantined records expose no download. See browser-checks.json and document-final-dom.txt.
- Actual browser downloads saved warranty-104.pdf and transfer-hoist-manual.pdf in Downloads. SHA-256 values match the exact source files; see browser-downloaded-file-checks.json. No download toast is treated as proof of a saved file.
- All four originals return matching bytes, MIME types and attachment/inline headers. Range, HEAD, unavailable files and encoded traversal checks passed. The loopback server serves only a fixed allowlist of synthetic files.
- Browser runtime warnings/errors: zero. One initial compound-text locator did not match; the locator was corrected without changing application code.
- All 70 frozen v8 files remain unchanged. v9 runs at port 8904, PID 21340, source header 8821-v9. Previous design candidates are retained.

## Production boundary

These static files demonstrate the interaction only. Production viewing and downloading must use the original document source, verify role/site/record/privacy access for each request, respect quarantined/unavailable states, and preserve version identity. Static fixture URLs are not a production authorization implementation. Live storage, scan/retrieval, uploads and authenticated delivery remain for the implementation phase.
