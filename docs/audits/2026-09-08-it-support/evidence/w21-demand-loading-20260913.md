# Knowledge content loading — 13 September 2026

The library previously selected and returned each page's full document bodies, structured content, relationship metadata and permitted working-copy snapshots. It now selects bounded metadata for 24 rows and fetches content for a selected reader, the existing authorized editor endpoint or revision history. Metadata reads do not select working-copy ciphertext or resolve every document's relationships. This is a bounded improvement; complete search/large-library performance acceptance remains open.

The reader prioritizes the separately loaded article over its metadata row. Opening a metadata row preserves filters and the page through the canonical article URL; an unavailable response cannot display a blank document as success. The editor initializes its form once from the fresh authorized content, using the installed Inertia `setDefaults` API. Later context refreshes retain the user's proposal rather than replacing it.

## Checks

- Grouped UI selection: 22 passed, one failed because the first implementation called `defaults` instead of the installed `setDefaults`. Source corrected; the affected editor test then exposed a test-only accessible-name mismatch, corrected without changing product copy. Final editor regression passed (1 case, 4.47s).
- Existing access/editor-context tests and the selected-reader regression passed in the grouped run. The strengthened row-open/page-retention test subsequently passed in the affected 15-case page suite.
- Prettier and scoped ESLint passed. Final typecheck57793 exited2 with no IT errors and the three independent My Day test errors. The first run also found the now-corrected defaults API error.
- New PHP regression asserts bounded library output, metadata-only article/working-copy SQL projections, retained proposal state/edit eligibility, full selected publication and full authorized editor content. Guarded backend session88177 passed all16 selected cases (251 assertions, 230.23s), including the five browser corrections' affected backend cases. All14 preflight/postflight checks passed and exact schema `oblivion_it_support_test_it_6a5987ad0cfd4c2d` was absent; runner exit0 confirmed.
- Build session69748 exited0 (5217 modules, 3m55s). The current desktop recheck passed full selected content, fresh authorized proposal hydration, retained editing, correct calendar dates and review/publication. See `w21-correction-desktop-browser-20260913.md` for exact observations, remaining native-link context/duplicate-toast defects and completed guarded cleanup.96 source/asset hashes stayed unchanged during the recheck.

## Additional user requirement

The user confirmed that Knowledge must upload and open Word/PDF files. W22 in the authoritative plan now explicitly names `.doc`, `.docx`, PDF, access-checked opening/preview or explicit original-file fallback, replacement versions, history and direct-file denial. This functionality is still unfinished. Existing ticket/catalogue attachments do not satisfy Knowledge document management.
