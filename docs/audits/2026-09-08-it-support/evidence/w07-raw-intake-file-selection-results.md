# W07 raw intake selection correction

Source checkpoint: 2026-09-09, after Build9. This change is not included in the currently running browser assets.

The technician and requester raw-file intake branches now reject an entire selection if its filename extension is outside the existing IT allowlist, a file exceeds 10 MiB, or the total would exceed five files. Existing selected File objects and entered text remain intact. Inline attachment feedback gives the correction; a valid replacement selection or explicit removal clears the local error. The file picker uses the shared accept list. The existing server validation of detected content remains authoritative.

Changed files:

- `resources/js/lib/it-attachments.ts`: shared selection validation using the existing filename allowlist.
- `resources/js/components/it/it-wizards.tsx`: two raw intake handlers, local attachment error rendering, and error clearing on correction/removal/reset. No whole-file formatting or persisted-draft behaviour change.
- `resources/js/components/it/__tests__/ticket-intake-file-selection.test.tsx`: new focused helper and actual-form tests; existing intake draft diagnosis files untouched.

Verification:

- `node node_modules/vitest/vitest.mjs run resources/js/components/it/__tests__/ticket-intake-file-selection.test.tsx`: **13 passed, 1 file, 5.16 s**. Tests cover every existing approved extension at exact count/size boundaries; misleading/disallowed filenames; both forms' drag-and-drop rejection and picker correction; no silent truncation; oversized mixed batches; unchanged title/body; and original accepted File objects in requester submission FormData.
- Scoped ESLint on the three changed source/test files: **exit 0**.
- Prettier applied only to the helper and new test; wizard handler formatting was narrow.
- The first sandbox invocation did not execute tests because esbuild could not resolve the repository configuration through the restricted parent directory. The approved outside-sandbox rerun above executed successfully. Preserve both logs: `w07-raw-intake-file-selection-vitest.txt` and `w07-raw-intake-file-selection-vitest-executed.txt`; lint log `w07-raw-intake-file-selection-eslint.txt`.

No PHP tests, migrations, database writes, provider calls, whole-tree type check or asset build were run for this slice. Real browser verification of these source changes remains pending the next coordinated build; this evidence does not mark W07 implemented or verified in full.
