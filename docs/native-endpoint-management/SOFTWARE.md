# Approved Windows software — development implementation

This increment adds governed, machine-wide MSI installation and vendor-supported major upgrades to the native Windows computer agent. It does not complete the wider RMM or Security & Devices programme. Execution is disabled by default and installed Windows acceptance remains required.

## Technician and staff experience

IT & Support exposes Approved software according to current permissions, and Remote support links the selected computer to that catalogue and its Software policy. The catalogue searches and pages the permitted releases, showing drafts, awaiting review, approved and withdrawn releases with their review history. Install requests use the existing Device Management request, independent approval, dispatch, receipt and reconciliation lifecycle.

An uploader reviews the file, exact product identity, version and vendor signing-certificate fingerprint before submitting a draft. Another authorised reviewer must confirm their identity recently and independently approve that immutable release. Uploading or submitting a draft does not authorise deployment. Withdrawal blocks new installations; it does not imply that already installed software is removed or that a running installer is stopped.

For attended computers, Orbit shows the exact application, version, publisher, technician and purpose. Staff explicitly allow that one installation. The current computer session is verified at launch. Closing the helper after allowing installation does not cancel it. Unattended installation has a separate permission and current per-computer policy.

The technician view distinguishes a completion receipt from independently verified installed-product evidence. A required restart remains visible and uses the separate approved restart workflow. Unknown execution is shown as uncertain, never silently retried.

## Boundaries

- One operating organisation per independent deployment, approved Sites and canonical Device ownership. No tenant transport, selector or cross-customer queue.
- Only reviewed, signed, self-contained, machine-context MSI packages, at most 512 MiB. Initial formats exclude EXE, MSIX and arbitrary installer arguments, properties, transforms or scripts.
- Private, authenticated same-deployment download with bounded bytes and exact hash; MSI-aware signature and certificate identity, product/version/architecture and embedded-source checks precede a fresh signed execution claim.
- Fixed Windows Installer invocation suppresses automatic reboot. Reviewed MSI custom actions are privileged code; signature checking alone is not a software-safety guarantee.
- A durable process-entry record precedes launch; the suspended child identity is persisted before execution resumes. Interrupted or unproven entry is never killed or replayed automatically.
- All governed native mutations share physical exclusion. Accepted independent quiescence can release this gate; that is not approval to repeat the installation.
- Exit 0 or 3010 needs separate exact installed-product proof before success. A 3010 restart notice needs an accepted later-boot exact installed-product observation before clearing. Wrong-version, absent or advertised-product evidence may prove quiescence but does not clear that notice.
- Exit 1641 remains uncertain after a later boot. A reboot alone never proves the installer completed.
- Lost upload/approval/policy responses use explicit read recovery. A missing upload may be retried only with the original request key, file and reviewed metadata.

The exact PHP/Go/local UI handshake is documented in [SOFTWARE-CONTRACT.md](SOFTWARE-CONTRACT.md).

## Verified evidence at this checkpoint

- Frontend: 44 tests across five files passed: the original 42 (12388/b4ba0b), then the canonical management suite expanded from 18 to 20 and passed (67188/a81b13). These checks cover software policy review and uncertainty, approved-release selection/search, immutable upload recovery, independent approval, result truthfulness and existing canonical Device Management regression, including ignored URL package preselection and immutable canonical-request recovery.
- Full TypeScript passed in 9106/68a3dc. Later complete TypeScript and scoped ESLint passed in 20815/b219f7 after adding all software tests and replacing two plain panels with shared Card components. The final test-only full TypeScript and canonical test lint also passed (2315/cccc17). The intermediate 14093/20c54e run failed on two style warnings; those were corrected.
- Native Orbit: 68 check groups, actual Go producer local-view parsing, read-only recovery, late-response privacy, consent and uncertain-reboot parsing passed in 15931/70a313. Self-contained x64 and ARM64 tray builds also passed in that same terminal run. All machine effects were injected.
- Go: final 3010 notice correction and six negative changed-boot paths passed with software/SCM/restart/runner regression, vet and amd64/arm64 agent/service-host builds (owner 84342, terminal 0ffe6c).
- Browser: production catalogue and review/upload components passed light/dark/390px rendering with synthetic DTOs (c1037e). Policy and result view passed at 390px (e32c15), with no page errors or writes. Screenshots are under output/playwright/software-*.png. Orbit images are under output/software-native.
- Browser harness adjustments were required: an initial warm navigation timed out; unsaved-form beforeunload handling interrupted the first combined checks. A root scrollWidth assertion also counted deliberately clipped screen-reader text and the horizontal stepper. Final checks measure the visible wizard body, header and footer and page bounds; screenshots were inspected. No shared wizard change was needed.
- Independent source review required recent identity for publication/withdrawal, durable historical quiescence across later boots, and exact-product matching before clearing the 3010 notice. These fixes landed.
- The first broadened PHP run accidentally included an application TestCase and reached the isolated schema bootstrap. Fourteen pure cases passed (162 assertions); three service-contract cases failed during setup on an overlong generated database constraint name. It does not establish software application acceptance. Both constraint names were explicitly shortened and database-free MySQL migration compilation passed. Subsequent independent review found and corrected a PHP/Go mismatch for interrupted claims before process entry: only bounded uncertain/interrupted or uncertain/state_unavailable with no mutation, null exit and no reboot are accepted. Exact PHP checks pass 16 tests/190 assertions (9c1264); Go producer regression passes 564328. Final source freeze is independently approved. Application verification subsequently completed across a broad follow-up and a targeted fixture correction, as recorded below. Neither failed full batch is retrospectively labelled green.

## Actual producer and application integration

The complete PHP application batch has run: the first result was 61 passing and 6 failing cases (627 assertions), and the reviewed fixture-only follow-up was 65 passing and 2 failing cases (651 assertions). The remaining two failures incorrectly assumed an optional observation-ACK key would be present as an empty array; the response correctly omitted it. That assertion is corrected and independently reviewed. The independently reviewed targeted follow-up (58472/34eb90) passed those two cases and the existing 3010 scenario: 3 tests, 61 assertions, exit 0. All 67 unique planned cases have now passed across these runs; this was not one green 67-case batch. Cleanup (67c410) confirms the isolated database and process are gone and the frozen PHP source is unchanged.

Actual unchanged core PHP-to-Go signed interoperability passes (dc68c2 and refreshed a1de14). Actual Go-to-C# native parsing passes (5b92ce, 68 groups). The frontend consumes actual PHP catalogue, cursor, policy, upload and requested/confirmed/restart-needed management DTOs: all 27 software tests pass (6804/1cd533, terminal ef8b4a), including all three actual-producer cases. Actual catalogue paging/identity gating (39eda4), policy read/review (4982a6) and management states (c774a4) render in the browser without page errors or narrow overflow. Light/dark/390px evidence and inspected screenshots are retained. This is unchanged producer-data and injected-machine integration, not a live installed-agent or live provider witness. The separate same-scenario 3010 signed PHP-to-Go chain also passes (ec8b72). The final native run (406039) passes 68 check groups with both actual core and 3010 local views. It validates immutable identity, receipt versus product confirmation, the retained restart notice, and clearing after exact accepted later-boot evidence. Native screenshots were inspected. The native test extension was independently reviewed; unrelated scenario identities were never combined.

## Still required

Signed installed SYSTEM-service acceptance on Windows x64 and ARM64 remains required: real trusted/revoked MSI signatures, vendor upgrades and rejection cases, Installer busy/rollback, child/service/process interruption, restart-needed persistence, later-boot readback and protected staging behavior. No MSI was installed, no actual process or service mutation was requested, and no release was signed or published here.

The complete release still requires broader agreed software/update formats, OS updates, reviewed scripts and other technician tools, staff self-service catalogue, persistent/offline drafts and remaining helper features, plus the full network, physical security, care, tracking, attendance and incident-reconstruction scope in PROGRAMME.md. This MSI increment does not replace those requirements.
