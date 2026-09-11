# W07 raw intake attachment accessibility

Source checkpoint: 2026-09-10. Implemented and verified by focused component tests; no public build or browser acceptance is claimed by this slice.

The shared `Field` cloned an `id` onto React.Fragment in the two raw intake attachment branches. This caused a runtime warning and left the Photos or files label without a real picker target. The existing dropzone itself remained keyboard operable; file content was not lost by this defect.

`Field` now excludes Fragment, preserves direct control IDs, and accepts optional `htmlFor`, `labelId`, and `errorId`. `FieldErr` accepts the explicit error ID. The existing generated-ID association for direct inputs remains tested.

`FileDropzone` now accepts optional ID and ARIA label/description/invalid props. Its visible keyboard control reads the visible Field label together with its original title; the native picker has a derived ID for label activation. Both controls receive the validation description. The native input also respects disabled state, so the newly associated label cannot bypass the existing disabled picker boundary. Existing title-based names, click/Enter/Space handling, file selection and drag/drop behavior remain intact.

Only requester and technician raw intake attachment branches opt into these associations, using stable `useId` values. Invalid selection retains existing files and text; a corrected selection removes the error association. Persisted draft attachment transport and retention policy are unchanged.

Changed application files:

- `resources/js/components/wizard/primitives.tsx` — Field/FieldErr only.
- `resources/js/components/ui/file-dropzone.tsx` — optional accessibility forwarding and disabled native picker only.
- `resources/js/components/it/it-wizards.tsx` — useId import and the two raw attachment field branches only.

New tests: `components/wizard/field.test.tsx`, `components/ui/file-dropzone.test.tsx`, and `components/it/__tests__/ticket-intake-attachment-accessibility.test.tsx` under `resources/js/`.

## Actual verification

```powershell
.\node_modules\.bin\vitest.cmd run resources/js/components/wizard/field.test.tsx resources/js/components/ui/file-dropzone.test.tsx resources/js/components/it/__tests__/ticket-intake-attachment-accessibility.test.tsx resources/js/components/it/__tests__/ticket-intake-file-selection.test.tsx resources/js/components/it/__tests__/ticket-intake-draft.test.tsx resources/js/components/wizard/primitives.test.tsx --reporter=dot
```

**35 tests / 6 files passed, 10.19s, exit 0**. The new tests assert no Fragment warning; default and explicit input ID compatibility; actual label-to-native-picker association; Enter and Space activate the same picker; disabled controls cannot activate it; visible errors are linked; and existing selected files survive invalid/corrected selections. Existing raw-selection and persisted/raw draft-retention tests also passed. Log: `w07-attachment-accessibility-tests.txt`.

Scoped ESLint `--max-warnings=0` passed for all six changed/new source files. Prettier checks passed for the four non-UI files; the UI directory is excluded by the repository's `.prettierignore`, so its existing formatting was preserved and the new UI test was formatted explicitly. Logs: `w07-attachment-accessibility-{eslint,format}.txt`.

No protected design file, task wizard/action, ConfirmDialog, PHP service, schema, browser helper, provider or public asset was changed. Root owns the coordinated whole-source TypeScript check, Build11 and desktop browser verification.
