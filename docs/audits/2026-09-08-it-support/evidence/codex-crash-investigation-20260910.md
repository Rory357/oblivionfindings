# Codex self-close investigation — 10 September 2026

User confirmed that the window closed by itself. Module implementation paused for local investigation. No product changes, deployments, provider changes or communications were made during investigation.

## Confirmed and repaired

The recommended-skills cache at `C:/Users/steph/.codex/vendor_imports/skills` had a zero-filled Git index header and malformed shallow metadata. Its fetch failed with `bad signature 0x00000000` / `index file corrupt`; alternate-index reconstruction failed with `bad shallow line`. The index timestamp predates this reported crash, so this defect is not evidence of its cause.

An official `https://github.com/openai/skills.git` clone matched the existing revision `49f948faa9258a0c61caceaf225e179651397431`. After reproducing the curated/experimental sparse-checkout layout, existing working files matched the clean metadata with an empty status. Only `.git` was replaced. Original metadata is preserved at `C:/Users/steph/.codex/recovery/skills-git-metadata-20260910-2017`. Skills, settings, project files and project Git history were preserved.

Verification after replacement: `git fetch --depth 1 origin main` exit 0; `git fsck --no-reflogs` exit 0; clean `git status --porcelain=v1`; unchanged HEAD. Preparation artifacts are under `storage/framework/testing/codex-skills-index-repair-20260910`. The early preview's 41 absent system-skill files reflect the original sparse checkout; matching that layout produced a clean status.

## Self-close remains unexplained

Installed package: OpenAI.Codex 26.903.8094.0 (desktop executable ChatGPT.exe). Current process started at 20:04:52 NZST. Previous desktop log ends at 20:02:36 NZST. It records 238 ResizeObserver notification errors, but no searched fatal renderer exit, panic or out-of-memory marker. These layout errors are observations, not a proven cause.

Bounded Windows Application crash/hang/error-report searches for both Codex and ChatGPT returned no matching events; no recent crash dump or resource-exhaustion event was found. Read-only backend-log inspection around the stop found a stream retry, not a fatal cause. Current free memory cannot establish memory usage at the crash. Current desktop log files were empty/buffered and do not prove an error-free restart.

No signed application files were patched, user databases cleared, processes killed, GPU settings changed or feedback/logs uploaded. A confirmed cache fault is repaired; prevention of another desktop self-close is **not verified**. A reproducible crash/fatal dump or vendor investigation remains necessary to establish that cause. Official troubleshooting reference: https://learn.chatgpt.com/docs/reference/troubleshooting.

## Resume

No IT build/test/browser runtime was running at this checkpoint. Existing loopback tabs point to removed verification runtimes and are not current application evidence. Resume W09/B07 canonical related-work lifecycle and scoped duplicate suggestions from the implementation ledger; desktop only, never resize the browser. All W00–W27 and release gates remain incomplete.
