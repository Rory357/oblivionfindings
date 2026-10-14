# HS17 duty facts, hour caps and policy examples

12 October 2026. **Source-only mapping note; no implementation or runtime acceptance.** This extends the accepted [HS17 interface proposal](HS17-WORKFORCE-INTERFACE-PROPOSAL.md). One operating organisation: current roles, approved Sites, canonical worker/parent ownership and independent private-source access remain the boundaries. Examples are invented planned duties, not private records or clinical advice. No persistence-agent files were changed.

## What can actually be mapped

Only an explicitly approved, revision-bound operational projection supplies a restriction. `ModifiedDuty.status=active`, `hours_per_day`, free-text restrictions/accommodations and `WorkCapacityAssessment.capacity_status` are source claims, not approval or an automatically effective cap. In current assessment creation, `user_id` can identify the assessor; the injury's canonical worker must govern attribution. For a modified duty, verify injury → return-to-work plan → duty worker equality. A legacy mismatch is unavailable/review, not a guessed worker.

The following are **candidate structured predicates**, not installed code names or an approved vocabulary. The organisation still needs to agree their exact meaning before a consumer is implemented.

| Predicate / accepted cap field | Exact canonical facts available | Limit of the conclusion |
| --- | --- | --- |
| Exclude named declared shift types | `Shift.shift_type`; normal create/edit accepts `standard`, `sleepover`, `on_call`, `split`, `travel`. | Describes the planned classification. `standard` does not mean light work; `split` alone contains no per-part intervals. Other/legacy values need review. |
| Exclude declared sleepover / on-call duties | Type plus `is_sleepover` / `is_on_call`; normal normalization forces the corresponding flag true for those types. | True is a declared demand. Conflicting/malformed facts need review. Neither flag proves active hours, sleep obtained or paid hours. |
| Exclude duties marked lone worker | `is_lone_worker=true`. | A false default means “not marked lone worker”, not verified colleague presence. A general “must not work alone” restriction needs a separately defined, complete contemporaneous staffing fact; House qualification counts cannot substitute for it. |
| Exclude declared driving responsibility | Explicit `coverage_roles` containing `driver`, type `travel`, or nonempty required licence class/endorsements are observable driving cues. Check these facts directly together. | Empty cues do not prove no driving. `rolesForShift()` returns configured roles before its travel fallback, so that fallback alone is incomplete. Existing driver approval/licence checks remain independent and do not certify medical capacity. |
| Match explicitly declared coverage roles | Known role keys are `caregiver`, `driver`, `med_competent`; inspect the canonical `coverage_roles`. | Coverage responsibility/competency is not a physical demand or proof every task is safe. `caregiver` alone supplies no lifting, posture or exertion measurement. Do not add a medical inference to eMAR competency. |
| Match an explicitly approved Site/service scope | Canonical Shift/Client/Site ownership and `service_context_id`/`ServiceType` classification. | Source injury Site is provenance, not an automatic restriction boundary. A residential/community label does not prove sedentary work. Only an approved structured scope may narrow applicability across Sites. |
| `maximum_elapsed_seconds_per_local_day` / `maximum_elapsed_seconds_per_local_week` | Valid planned `[starts_at,ends_at)` instants, the complete proposed+existing worker workload and configured worker timezone; local day / ISO Monday week segmentation. | Planned elapsed duty, not actual attendance or pay. No break/sleepover deduction or days×8. Null is unspecified; zero is distinct and requires agreed policy. Legacy decimal `hours_per_day` needs an explicit authorised conversion/meaning, not automatic activation. |

Site staff requirements supply qualification demand: `mandatory`/`recommended`, `all_workers` or a positive `minimum_staff` count, optionally mapped to HR qualification evidence. They do not measure lifting ability or recovery status. Preserve House “Choose per requirement”, existing qualification warning/configurable blocking policy and ordinary warning acknowledgement rules; none sets HS17 severity/override authority.

## Concrete implementation prerequisites

- Reuse the existing proposed-batch replacement/exclusion and carried-in workload logic. Its existing query counts all non-cancelled planned intervals, including completed planned duties; do not silently replace them with clock/pay history or rewrite completed work. Overlapping duties are summed, not unioned; the separate conflict rule still applies.
- `ProposedShiftWorkloadService::existing()` and its current signature load only IDs, status and planned intervals/context. They omit shift type, all three flags, roles and licence demand. Capability checks over existing duties therefore need a narrow canonical read/signature extension; an unloaded flag must not become a false/compatible fact. The proposed-candidate tuple already carries flags/roles/licence fields but omits `shift_type`; bind every fact the new predicate consumes there too.
- `LocalWorkTimeSegmenter` uses configured `app.worker_timezone` (fallback Auckland), real instants and local midnight/Monday boundaries. It returns float hours from whole-second timestamps. Compare cap seconds before display rounding; preserve supported timestamp precision explicitly. Invalid/missing windows cannot turn its empty segmentation into an assessed zero. A restriction timezone differing from the configured workload calendar needs explicit semantics, not silent rebucketing.
- No inspected canonical duty model provides a typed lifting mass/frequency, standing duration, posture, exertion class, guaranteed buddy or rest-work schedule. `ShiftTask.label`/encrypted steps, source narratives and service descriptions must not be parsed into these facts. Names of accommodations are not evidence they were delivered.

## Four examples where policy matters

1. **Cross-midnight and NZ DST.** A planned duty runs **26 September 2026 22:00 +12 → 27 September 04:00 +13**: five elapsed hours, split into two hours on Saturday and three on Sunday, not six elapsed hours. Those calculations are fixed implementation. If a two-hour daily restriction is issued “through 27 September”, an inclusive certificate end includes Sunday's three hours and exceeds the cap; interpreting the date as an exclusive 27 September midnight ends it before that segment. The issuer-date conversion is pending policy. Once an authorised explicit UTC end is recorded, there is no arbitrary choice or midnight-UTC substitution.

2. **Cap starts at midday.** Existing duty 08:00–12:00, proposed duty 12:00–16:00; a four-hour daily limit becomes effective at 12:00. Whole-local-day accounting yields eight hours and exceeds it; effective-slice accounting yields four. The organisation must choose the denominator for partial days/weeks. Clipping automatically would silently choose policy; neither arithmetic result grants permission to assign.

3. **Simultaneous restrictions across Sites.** One approved decision limits the worker to six hours/day; another overlapping decision limits them to four. A five-hour proposed duty at a second Site exceeds the applicable stricter cap. Intersecting the active constraints, considering cross-Site workload and retaining supersession/revocation revisions are fixed parts of the accepted interface; “last saved wins” or injury-Site-only filtering is invalid. The pending severity/proceeding-authority policy determines whether the incompatibility blocks or is handled by an expressly authorised review/warning workflow. No current qualification setting or manager label creates that authority; no automatic reassignment follows.

4. **Missing physical-demand facts.** An approved source requests “no lifting above 5 kg” / “no standing longer than 30 minutes”. A standard caregiver duty at a House with two manual-handling-qualified staff contains no typed task loads or standing intervals. The result cannot be compatible on those facts. A narrowly agreed recorded duty classification/demand source could make it assessable; choosing that vocabulary and who certifies the missing fact is organisational policy. Implementing a parser for the note, treating the two qualifications as physical clearance or letting a scheduler invent a value is not an ordinary coding choice.

## Safe wording and unresolved decisions

Use **“Approved work restriction”**, **“Planned duty exceeds the approved limit”**, **“Duty compatibility could not be assessed”** or **“Not marked as a lone-worker duty”** only when those exact facts apply. Avoid “medically fit”, “safe to lift”, “not working alone”, “cover accepted” or “restriction cleared” from a rule pass or queued refresh. Source saved, decision approved, evaluation complete and coordinator review accepted are distinct states. Never copy clinical narratives, assessor details or private source links into scheduler alerts.

Ordinary implementation choices are canonical locked reads, half-open overlap, local elapsed arithmetic, strict scalar validation, current revision checks, combining applicable constraints and truthful unavailable states. Genuine pending decisions are the approved code-to-fact vocabulary/completeness, date inclusivity/open-ended review semantics, partial-period cap denominator, any calendar mismatch, restriction severity/proceeding authority and the already-open approval audience/self-approval policy. This note asks no new question and resolves none of those policies by inference.

## Point-in-time source pins

SHA-256 of ordinary source bytes read for this note; no whole-tree freeze or runtime claim. The accepted interface pin is checked explicitly. Persistence proposals and private data were not read or repinned.

| Source | SHA-256 |
| --- | --- |
| `docs/workforce-audit-2026-10-05/HS17-WORKFORCE-INTERFACE-PROPOSAL.md` | `2b00ddbe21e3f9c628499cbb61abf7c55aeb2960c01ecc0ec8bef3dfd3ac3a12` |
| `docs/architecture/single-tenant-application.md` | `3dea6218db87ce22bed3cab6b9c500d1a850445d04e9325cb16c23a604979b3c` |
| `app/Models/WorkplaceInjury.php` | `139f16df9559ab4b3ad581f82333aeae58c26c80cec83c0a8361c15744e8ebf4` |
| `app/Models/ReturnToWorkPlan.php` | `d7d9110adb8dee94e572095cc570efd48c6b3f8684ee5aa13730e97e8459108b` |
| `app/Models/ModifiedDuty.php` | `54efa1caae748ab2360d7c64e220a20c6387cd918dcf408c4526b37839e98adc` |
| `app/Models/WorkCapacityAssessment.php` | `d51a86bcb1b6c9878df81326476c0666d80af64508f003ade0059fb6758ee118` |
| `app/Http/Controllers/HealthSafety/ReturnToWorkController.php` | `6236a1b99c8424435c4a5514b8a89b26780ab26cfc94804e2bbde0924f4766aa` |
| `app/Models/Shift.php` | `d22ee93a1efca89b9dcfc5b2d62b57ccda2bc83a90682979fe961a7ebbc94893` |
| `app/Http/Controllers/ShiftController.php` | `93e305a72c80939b139bdf6808652507206b8e10cc177b5d63a40e83cdf79dfc` |
| `app/Models/ShiftTask.php` | `c3088a9fb44d33619cb031c0fbaa9b10c60e34fee9f07d68e575546366226cca` |
| `app/Models/ServiceContext.php` | `4cc069bbc97ca5bd7a0187b465c2774e4e50d3719b93aaaf996f758400674b4a` |
| `app/Enums/ServiceType.php` | `e40a748a6732af81302101283467e19b5574485f83d6f8cd837a931e417dedc2` |
| `app/Services/CoverageRoleService.php` | `b622cb89d99d1c539a243901cf400520c656fb0ceaeb2f38ab91d8095d59caf6` |
| `app/Services/Eligibility/Rules/RequiredDriverLicenceRule.php` | `a72811e39393fc5d6963697ed7f714f33ffb81a7133f9a2f5d798090e0c87e6d` |
| `app/Models/SiteStaffRequirement.php` | `eb5e8dd17cf8747c03082c0f61987d3be48ffe7eeb75bc32010922bf58b1ed35` |
| `app/Http/Requests/StoreSiteRequest.php` | `271600e02da9e99801035c009e2889d4019350bb55d29b7fa39bf1d8939e3c3e` |
| `app/Services/Eligibility/HouseQualificationCoverageService.php` | `bf492aa0166aa5ddc29839c73aa1a81132e57b2c2d61ad50b192e22dff35e1b0` |
| `app/Services/Eligibility/ProposedShiftWorkloadService.php` | `bbefebd17d176082105ba9bdf8695a22b0bf525a3c143b851a87b6ee28041d02` |
| `app/Services/Eligibility/PreparedShiftWorkload.php` | `3bf0570156a0103d6af080686b659c4d2344045ec6703572a41bd288489964f9` |
| `app/Services/Eligibility/LocalWorkTimeSegmenter.php` | `439d7278fc64f1065bcc499f39fec515cc4dc98a0fbb32e22a33b8f1a9139785` |
| `app/Services/Eligibility/Rules/FatigueRule.php` | `db3f16de0f280baaff2df1c4ea61232f76cfd036dea3fd141fd5c43d211d1142` |
| `config/app.php` | `1771848bd51707f061c6b56a8912f4775106f82ea94cd673ae4f8c80408e2d8d` |
