# Running a round gate on GitHub

The full Pest suite takes hours on the shared PC and crowds out everyone
else's checks. GitHub runs it in about half an hour for free (the repository
is public), so **a session's end-of-round gate runs on GitHub**, not locally.
Locally, keep to quick targeted checks of the files you touched.

The `tests` workflow (`.github/workflows/tests.yml`) runs:

| Job | What |
| --- | --- |
| `frontend` | `tsc --noEmit` and the vitest sets |
| `foundation` | `tests/Unit`, `tests/Integration`, `tests/Architecture` |
| `emar-release` | the named medication release gate files |
| `feature (1..12)` | `tests/Feature`, split into 12 shards |
| `verdict` | one answer for the whole run |

Every PHP job runs 3 fresh Pest processes at a time, 12 files per process
(30 for foundation), each with its own test database, with MySQL's data in
RAM.

## Green and red

The run is **green** when every failing test is on the reviewed list
[`tests/ci/known-failures.php`](../../tests/ci/known-failures.php), no Pest
process crashed and every shard reported. Any other failure makes it **red**.
Known failures that now pass are listed in the verdict so the list can be
trimmed; they don't turn the run red.

- Fixed a known failure? Remove its entry in the same change.
- A new failure that is really main's (it fails the same on main)? Ask MASTER
  to add it, with an owner. Don't add entries to make your own gate green.

## The commands

Replace `<branch>` with your branch. Run from your worktree.

```bash
git push origin HEAD:refs/heads/<branch>
```

```bash
gh workflow run tests.yml --repo Rory357/oblivionfindings --ref <branch>
```

Find the run it started (give GitHub a few seconds first):

```bash
gh run list --repo Rory357/oblivionfindings --workflow tests.yml --branch <branch> --event workflow_dispatch --limit 1 --json databaseId,url,status
```

Wait for it without polling: run this one in the background
(`run_in_background`) and you are told when it ends. It exits non-zero
when the run is red.

```bash
gh run watch <run-id> --repo Rory357/oblivionfindings --exit-status --interval 120
```

Read the verdict (`verdict.md` for people, `verdict.json` for scripts):

```bash
gh run download <run-id> --repo Rory357/oblivionfindings --name verdict --dir <scratch-folder>/verdict-<run-id>
```

The same text is on the run's summary page (`gh run view <run-id> --web`).
`verdict.json` has `green`, `new_failures` (test and message),
`known_failures`, `known_now_passing`, `crashed_batches` and
`shards_missing`.

For a failure's full output, download that shard's results. They hold the
JUnit file and complete log of every batch:

```bash
gh run download <run-id> --repo Rory357/oblivionfindings --name results-feature-7 --dir <scratch-folder>/results-<run-id>
```

`gh run view <run-id> --repo Rory357/oblivionfindings --log-failed` also
works, but it is long.

## Then

Put the run link and the verdict line in your merge request to MASTER. A
green gate on the branch you ask MASTER to merge needs no local re-run.

Pushing again and dispatching again on the same branch cancels the older run
of that branch. Runs on `main` queue instead, so every commit that lands on
main gets its own verdict.

## Before this workflow is on main

`gh workflow run` only works once `tests.yml` with `workflow_dispatch` is on
`main`. Until then, or on a branch cut before it landed (merge main first),
open a draft pull request instead. It runs the same workflow:

```bash
gh pr create --repo Rory357/oblivionfindings --draft --base main --head <branch> --title "Gate: <branch>" --body "Round gate only. MASTER merges."
```

```bash
gh run list --repo Rory357/oblivionfindings --workflow tests.yml --branch <branch> --event pull_request --limit 1 --json databaseId,url,status
```

Pull requests also start the lint, database-bootstrap and visual workflows.

## Machine settings

Repository variables (Settings → Secrets and variables → Actions → Variables)
change where and how wide the PHP jobs run, without editing the workflow:

| Variable | Default | Meaning |
| --- | --- | --- |
| `TESTS_RUNS_ON` | `"ubuntu-latest"` | runner labels as JSON, e.g. `["self-hosted","linux","x64","oblivion-tests"]` |
| `TESTS_FEATURE_SHARDS` | `[1,…,12]` | feature shard numbers as JSON; `[1]` puts all of Feature on one machine |
| `TESTS_WORKERS` | `3` | Pest processes at once per machine |
| `TESTS_MYSQL_MEMORY` | `2G` | InnoDB buffer pool of the job's MySQL |

A self-hosted runner needs Linux with Docker (the job starts MySQL 8.0 in a
container with its data on tmpfs), PHP extensions come from `setup-php`. Run
one runner instance per machine and use `TESTS_WORKERS` for parallelism
inside it: the job's MySQL listens on port 3306. Pull requests from forks
never use `TESTS_RUNS_ON`: the repository is public, and a stranger's pull
request must not run code on the office machine.
