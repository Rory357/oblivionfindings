# W08 task recovery notice discovery — compiler regression

Source checkpoint: 2026-09-10. Implemented and verified in focused JavaScript tests; **not yet verified in a rebuilt browser**. Root's frozen Build10 browser retains the confirmed defect until the next coordinated build.

## Confirmed defect and cause

In Build10, Add task → enter title/description → Escape → Keep draft and close retained the private copy, but the existing Work register did not advertise it. Reopening Add task displayed the retained draft with its content concealed. The converse was also reproducible: an initially discovered notice remained after explicit discard.

`useItWorkTaskMemoryNotices` subscribed to the store revision but ignored the returned value and separately read the mutable memory/ownership register during render. Production React Compiler output cached that notice derivation solely by actor/ticket. The existing ownership release already published an update; adding another publish would not repair the compiled derivation.

The final change supplies the actual metadata array through `useSyncExternalStore`. A scoped snapshot reader caches it until the store revision changes, and is recreated when actor/ticket changes. No private payload, storage policy, command identity, release semantics or inventory was added. An intermediate revision-wrapper `useMemo` was rejected by the regression: the compiler still separately cached its inner notice array. Its transformed trace remains in `w08-task-compiler-discovery-memo-attempt.txt`.

## Reproducible checks

New `vitest.it-compiler.config.ts` selects the actual React plugins from `vite.config.ts`, including the production Babel compiler settings. Laravel, Wayfinder and CSS hooks are excluded. A post-transform assertion requires compiler output in the actual task register; `IT_TASK_COMPILER_TRACE=1` optionally prints the notice hook's transformed code. It does not build public assets or run PHP.

```powershell
.\node_modules\.bin\vitest.cmd run --config vitest.it-compiler.config.ts --reporter=dot
.\node_modules\.bin\vitest.cmd run --config vitest.it-recovery-control.config.ts --reporter=dot
.\node_modules\.bin\tsc.cmd --project tsconfig.it-task-compiler.json --noEmit
.\node_modules\.bin\eslint.cmd resources/js/hooks/use-it-ticket-draft-memory.ts vitest.it-compiler.config.ts vitest.it-recovery-control.config.ts tests/Frontend/It/task-recovery.compiler.test.tsx --max-warnings=0
```

The identical two UI tests in `tests/Frontend/It/task-recovery.compiler.test.tsx` use the real register, wizard and RAM adapter. They verify retained content exists while concealed, no HTTP calls occur, Keep advertises it without remounting the register, and confirmed Discard removes the notice.

- Before fix, production compiler: **2 failed / 2**, 5.56s, exit 1. Both fail only at the final notice assertion; `w08-task-compiler-discovery-red.txt`.
- Before fix, ordinary repository React transform control: **2 passed / 2**, 3.53s, exit 0; `w08-task-compiler-discovery-control.txt`.
- Final fix, production compiler: **2 passed / 2**, 5.26s, exit 0; `w08-task-compiler-discovery-green.txt` contains the transformed snapshot reader and compiler confirmation.
- Existing task register and task RAM tests: **37 passed / 2 files**, 9.17s, exit 0; `w08-task-compiler-adjacent-tests.txt`.
- Final scoped TypeScript and ESLint `--max-warnings=0`: both exit 0; `w08-task-compiler-regression-{types,eslint}.txt`.

Runtime change is limited to `resources/js/hooks/use-it-ticket-draft-memory.ts`. New test/config files are isolated from the ordinary test include; the compiler command must be explicitly included in W27 verification. No existing tests, protected design files, database, provider, migration, browser helper or public build was changed by this slice.

Next: root's coordinated build and actual desktop Keep/Resume/Discard journey. Plain task success wording replacing “canonical command result” / “canonical task command” is delegated to root/W00; this slice does not claim it changed or verified.
