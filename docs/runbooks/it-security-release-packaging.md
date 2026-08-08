# IT, Security & Monitoring release packaging

## Purpose

This runbook defines the source-only packaging boundary for IT & Support, Security & Devices, native Oblivion Monitoring, and their approved cross-module projections. It prevents browser/runtime evidence, local environment state, generated output, or unrelated loose files from entering a release.

Packaging is a read-only inventory and review activity. A packaging manifest is not permission to stage, commit, push, deploy, delete, clean, or move any file. Each later action requires its own explicit approval and evidence.

## Release identity and clean-source gate

Record all of these values before reviewing file content:

- approved base revision;
- candidate source revision;
- exact candidate `HEAD` SHA;
- exact `origin/main` SHA;
- branch name;
- packaging time in UTC; and
- reviewer and requirement or release owner.

The release checkout must be a separate clean checkout or worktree at the approved candidate revision. Before dependency installation or build work, require:

```bash
git rev-parse --verify HEAD
git rev-parse --verify refs/remotes/origin/main
git status --porcelain=v1 --untracked-files=all
```

`HEAD` must equal the approved candidate revision and `origin/main`. The status output must be empty. A dirty development worktree can be inventoried, but it cannot be labelled the release checkout and cannot produce release success.

The manifest must identify every intended file individually. An allowed source family below is a review boundary, not permission to include every file in that directory.

## Required manifest fields

Create a value-free manifest with one row per intended file:

| Field | Requirement |
| --- | --- |
| `path` | Exact repository-relative path; no glob in the final manifest. |
| `change` | Added, modified, renamed, or deleted. |
| `sha256` | Hash of the reviewed candidate file; deleted files use the approved base hash and explicit deletion state. |
| `owner` | IT, Security & Devices, Monitoring, collector, runtime packaging, or named cross-module owner. |
| `requirement` | Ledger or release requirement served by the file. |
| `source_or_generated` | Must be `source`; generated/runtime/evidence files are rejected. |
| `review` | Structured `approved` decision, identified reviewer, and explicit UTC review time. Pending, placeholder, self, unknown, or unapproved reviews fail. |
| `verification` | Structured `passed` result, exact focused test/static/build/runbook evidence, and explicit UTC observation time. Pending, missing, future, or unverified evidence fails. |

The manifest itself must not contain credentials, environment values, endpoint addresses, private targets, raw provider payloads, Client or staff data, location history, certificate material, or copied log output.

## Intentional source families

Only reviewed source inside these families may be proposed. Every proposed file still needs an exact manifest row and a diff review against the approved base revision.

### IT & Support

- `app/Domain/It/**`
- `app/Http/Controllers/It/**`
- IT-owned models, policies, requests, notifications, mail, jobs, console commands, and support classes only when named individually.
- IT routes in `routes/web.php` and approved service API/email routes in `routes/api-hr.php`, limited to the reviewed hunks.
- `resources/js/pages/it/**`
- `resources/js/components/it/**`
- IT navigation and permission-safe shared primitives only when named individually.
- IT migrations, factories, seeders, and tests only when named individually.

### Security & Devices

- `app/Domain/SecurityDevices/**`
- `routes/security-devices.php`
- `resources/js/pages/security-devices/**`
- `resources/js/components/security-devices/**`
- Security & Devices migrations, factories, seeders, policies, and tests only when named individually.

### Native Monitoring and optional remote collector

- `app/Domain/Monitoring/**`
- Monitoring-owned console commands, scheduled commands, jobs, services, models, and listeners only when named individually.
- `routes/monitoring-collector.php` and reviewed Monitoring hunks in `routes/console.php`.
- `collector/**`, excluding dependencies, generated state, identity material, spool data, certificates, keys, and runtime logs.
- `scripts/monitoring/**`
- `ops/supervisor/oblivion-monitoring-workers.conf`
- `ops/supervisor/oblivion-monitoring-listeners.conf`
- Monitoring configuration, migrations, fixtures, and tests only when named individually.

### Release runtime source

- `scripts/deploy-server.sh`
- `scripts/inertia/install-supervisor.sh`
- other installer or verifier scripts only when directly connected to the reviewed release contract and named individually.
- `config/inertia.php`, `package.json`, `package-lock.json`, `composer.json`, `composer.lock`, and `vite.config.ts` only when their reviewed change is intentional for this release.
- Supervisor/systemd source definitions only when their installer and fail-closed verification contract are in the same manifest.

### Canonical cross-module projections

Connected files are allowed only when they preserve their existing owner and are named individually. Relevant review families include:

- Site Profile Technology and monitoring projection: reviewed Site controller/presenter, `routes/sites.php`, and Site page/test files.
- Client Profile Healthcare Devices and consent-aware location projection: reviewed Client controller/presenter, `routes/operations.php`, and Client page/test files.
- HR Equipment & Access projection: reviewed HR controller/presenter, `routes/hr.php`, and HR page/test files.
- Fleet, Resident Tracking, vehicle technology, Asset, and Finance reconciliation: reviewed Fleet/Asset/Finance controllers or presenters, `routes/fleet-assets.php`, and connected page/test files.
- Control Room Device signals, map, alert, and sealed monitoring evidence: reviewed Control Room controllers/presenters, `routes/control-room.php`, and connected page/test files.
- Canonical shared models, policies, access services, migrations, and tests only when the manifest states which owner and source-of-truth invariant they preserve.

A cross-module projection may read or link the canonical owner. It must not introduce a second Device, ticket, alert, Site, Client, staff, vehicle, Asset, monitoring, credential, or tracking store.

### Tests, documentation, and operator contracts

- `tests/Unit/**`, `tests/Feature/It/**`, `tests/Feature/Monitoring/**`, `tests/Feature/SecurityDevices/**`, reviewed connected Fleet/Control Room/Site/Client/HR tests, and named `tests/Architecture/**` guards.
- Source fixtures under `tests/fixtures/**` only when they are static, synthetic, reviewed inputs. For example, the checked-in RFC syslog fixture files are source inputs, not runtime log output.
- `resources/js/**/*.test.ts`, `resources/js/**/*.test.tsx`, and reviewed browser test source under `tests/Browser/**`.
- `docs/it-support-security-devices-completion-goal.md` when the release owner explicitly includes the reviewed ledger update.
- Reviewed operator documentation under `docs/runbooks/monitoring/**`, `docs/runbooks/security-devices/**`, and the named IT/Security release runbooks.

Browser test source is allowed. Browser test output is not.

## Exact release exclusions

The following are never source-package entries, even when untracked status or a broad filesystem scan finds them.

### Environment and local tool state

- `.env`
- `.env.dusk.local`
- every other `.env.*` value file except a separately reviewed, value-free `.env.example`
- `.playwright-cli/**`
- `.phpunit.result.cache`
- IDE, browser profile, authentication state, cookies, local certificates, local keys, and tool caches

### Runtime, browser, and manual evidence output

- `output/**`
- `playwright-report/**`
- `test-results/**`
- `tests/Browser/screenshots/**` except the existing source-control placeholder
- `tests/Browser/console/**` except the existing source-control placeholder
- screenshots, videos, traces, HAR files, downloaded exports, browser storage state, and manual-audit evidence wherever they appear
- `*.png`, `*.jpg`, `*.jpeg`, `*.gif`, `*.webp`, `*.mp4`, `*.webm`, `*.mov`, and `*.har` browser/manual evidence cannot be relabelled as source inside an otherwise allowed path family
- `storage/logs/**`
- `storage/framework/testing/**`
- `*.log`, `*.out`, `*.err`, `*.trace`, and runtime process transcripts, except a specifically reviewed static protocol fixture under `tests/fixtures/**`

These files may support an external evidence pack under its approved retention controls. They must not be copied into the application source package.

### Databases, queues, and runtime state

- `database/database.sqlite`
- every `*.sqlite`, `*.sqlite-journal`, `*.sqlite-wal`, and `*.sqlite-shm` file
- MySQL dumps, Redis dumps, time-series exports, object-store payloads, secret-manager exports, collector spool/checkpoint state, queue/inbox/outbox payload dumps, and restored-store evidence
- certificates, private keys, identity bundles, credential leases, tokens, provider responses, raw frames, packet captures, syslog captures produced by a live run, and discovery target lists

Migrations and synthetic factory/fixture source are allowed only through exact manifest rows; database contents are never source.

### Generated dependencies and build artifacts

- `vendor/**`
- `node_modules/**`
- `public/hot`
- `public/build/**`
- `bootstrap/ssr/**`
- `resources/js/actions/**`, `resources/js/routes/**`, and `resources/js/wayfinder/**` Wayfinder output, even if someone force-added it to a candidate revision
- coverage output, compiled caches, framework caches, temporary manifests, and other generated route/type output unless the repository explicitly tracks the reviewed generated source

Client and SSR builds are verification outputs. Rebuild them from the clean reviewed revision during deployment; do not package a dirty worktree's generated assets as source.

### Root command-output and loose scratch artifacts

The audit found these exact root command-output artifacts; they are never release source:

- `count()])`
- `pluck('migration'))`
- `toSql())`
- `value('migration')])`

Unapproved root-level copied migrations, models, jobs, ad-hoc test scripts, SQL snippets, and temporary PHP files are also excluded. In particular, a legitimate application class or migration must live in its canonical reviewed source directory; a same-named loose root copy is not a shortcut into the manifest.

## Executable read-only manifest verification

Prepare the exact JSON manifest outside the candidate checkout. Keeping it outside is mandatory: adding it inside the checkout would make the checkout dirty or create a self-referential source hash. The manifest records source identity and review metadata only; it must never contain environment values, credentials, live payloads, database contents, or runtime evidence.

The schema is exact. Unknown or missing fields fail verification:

```json
{
  "schema_version": 2,
  "base_revision": "<40 lowercase hexadecimal characters>",
  "candidate_revision": "<40 lowercase hexadecimal characters>",
  "origin_main_revision": "<the same 40-character candidate revision>",
  "files": [
    {
      "path": "app/Domain/It/Example.php",
      "change": "modified",
      "sha256": "<64 lowercase hexadecimal characters>",
      "owner": "IT",
      "requirement": "V10",
      "source_or_generated": "source",
      "review": {
        "decision": "approved",
        "reviewer": "release-review@example.invalid",
        "reviewed_at_utc": "2026-08-08T10:00:00Z"
      },
      "verification": {
        "result": "passed",
        "evidence": "focused test or static contract",
        "observed_at_utc": "2026-08-08T10:01:00Z"
      }
    }
  ]
}
```

`change` is exactly `added`, `modified`, `renamed`, or `deleted`. A renamed row must also contain `previous_path`; no other row may contain it. The hash for an added, modified, or renamed file is the candidate file's SHA-256. The hash for a deleted file is the base revision's file-content SHA-256. Review and verification are machine-readable attestations: both require their exact object keys, explicit UTC timestamps that are not in the future, an `approved` review with an identified reviewer, and a `passed` verification naming the completed evidence. Non-empty placeholder text is not approval evidence.

From any trusted working directory, run the repository verifier with absolute paths:

```bash
php /absolute/candidate/scripts/release/verify-source-manifest.php \
  --manifest=/absolute/external/release-source-manifest.json \
  --checkout=/absolute/candidate
```

The verifier performs read-only Git and filesystem inspection. It requires a clean checkout including no untracked files, exact `HEAD` and `origin/main` revisions, a valid base ancestor, unique literal paths, source classification, approved source families, no excluded or generated path, exact source hashes, and an exact match between all manifest rows and the complete base-to-candidate Git diff. Therefore an unlisted changed file also fails verification. It fails closed on duplicate paths, globs, missing files or fields, mismatched hashes, generated classification, revision mismatch, dirty state, and unsupported change types. Known Wayfinder output paths and browser/manual evidence extensions are rejected from the revision diff itself, so relabelling force-added generated or evidence files as `source` cannot bypass the gate.

The verifier does not create a manifest, stage, commit, push, delete, clean, reset, move, build, deploy, or contact a live service. A successful read-only result authorises no later phase; staging still requires separate approval and exact pathspecs.

## Read-only inventory procedure

1. Pin the approved base and candidate revisions. Refresh remote references without changing the working tree.
2. Produce the tracked changed-file candidate list from those exact revisions.
3. Produce an untracked candidate list only inside an approved source family. Do not use a repository-wide untracked list as an inclusion decision.
4. Reject every exact exclusion before reviewing source content.
5. Review every remaining diff and classify it as intentional source, unrelated user work, duplicate source, generated output, runtime evidence, or unresolved.
6. Add only intentional source rows to the manifest. An unresolved file blocks packaging; it is not silently included or deleted.
7. Verify each manifest path exists in the candidate revision, is inside an allowed family, is not an exclusion, and matches its recorded hash.
8. Compare the manifest to focused verification evidence and the requirement owner. Missing migrations, policies, routes, tests, runbooks, or runtime definitions block the package.
9. Reproduce the manifest from a clean checkout at the candidate revision. Require no additional file and no missing file.
10. Sign off the manifest as reviewed. Stop before staging unless separate authority is given.

Never use `git add -A`, `git add .`, `git commit -am`, or a blanket directory path to make the repository match the manifest. Do not delete, clean, reset, or relocate an excluded artifact as part of packaging review.

## Packaging is not publication

| Phase | State change | Required outcome |
| --- | --- | --- |
| Inventory | None | Candidate paths classified; exclusions recorded externally without copying their contents. |
| Packaging manifest | None | Exact source paths, revisions, hashes, owners, reviews, and verification recorded. |
| Staging | Git index changes | Separate approval; stage only explicit manifest pathspecs and compare the staged diff back to the manifest. |
| Commit | New repository revision | Separate approval; commit only the reviewed staged tree and record the resulting revision. |
| Push or pull request | Remote repository change | Separate approval and remote checks. |
| Deployment | Runtime and data change | Separate deployment approval, clean-release gate, migrations, builds, supervised runtime checks, and deployed acceptance. |

A manifest does not prove a commit exists. A commit does not prove it was pushed. A push does not prove deployment. A deployment does not prove the final desktop browser, live provider/protocol, outage, observation, or restore evidence passed.

## Final packaging decision

Packaging passes only when the candidate revision is exact, the release checkout is clean, every manifest row is intentional reviewed source, every exclusion is absent, all source owners and verification contracts are present, and the manifest reproduces exactly from the clean revision. Otherwise record the unresolved path and owner and leave the release blocked without mutating the shared development worktree.
