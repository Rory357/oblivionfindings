# Health and safety vocabulary — the words people see

Every H&S screen, pop-up, toast, validation message, notification and server-generated label uses these words. It
follows the Governance vocabulary's house style (`docs/audits/2026-09-14-governance-plain-language-ux/vocabulary.md`)
and `design_styles/WRITING_STYLE_GUIDE.md`. If a term isn't here, write it the way you'd explain it to a new support
worker in one breath.

**Approved 29 Oct 2026** by MASTER on Stephan's behalf ("you are in control of health and safety"), from the plain-language
audit of 29 Oct. Owner choices: **"safety review"** for H&S's follow-up record, **"restraint"** (one word on screens;
the legal meaning is unchanged), **"Urgent"** for the top action priority.

## House style

- **Sentence case** for every heading, tab, button, label, chip and menu item.
- **NZ English** (organisation, recognise, programme).
- **Dates and times** only through `resources/js/lib/datetime.ts` (e.g. "7 Sep 2026, 5:00 pm"). Never ISO dates, raw
  offsets or "Pacific/Auckland" on screen.
- **No raw values.** Every stored value (`near_miss`, `in_progress`) goes through the shared labels:
  `resources/js/lib/health-safety/hs-labels.ts` and its server mirror `App\Support\HealthSafety\HsLabels`. Both match
  `tests/fixtures/health-safety/labels.json`; a test on each side fails if one changes without the other.
- **Explain, don't abbreviate.** Unavoidable terms get a hint (see the Hint column).
- **Missing or hidden data** says "Not available", "No data yet", "Not recorded" (only when nobody recorded it) or
  "Hidden — you don't have access". Never "Not confirmed", "Unconfirmed", "Restricted", "Withheld", a blank or a 0.
- **Blocked actions** say what's blocked, why, and who can unblock it, as text on the page — never only a tooltip.
- **Consequential actions** (close, cancel, archive, remove, delete, stand down) use `components/confirm-dialog.tsx`,
  saying the effect and whether it can be undone.
- **Legal wording keeps its exact meaning** (HSWA 2015, WorkSafe notifiable events, restraint under Ngā Paerewa NZS
  8134:2021, Privacy Act 2020, Hazardous Substances Regulations 2017). Only the wording around it becomes plainer.

## Terms

| Thing | Use | Hint | Don't use |
|---|---|---|---|
| Something that happened and was reported | **incident** ("Report an incident") | | event (alone), occurrence, record |
| H&S's follow-up record (`HsEvent`) | **safety review** ("Safety reviews", "Open safety review") | Health and safety's record of what happened, why, and what we're doing about it | event, parent event, governance record, journey, Events register |
| Kind of safety review | Incident · Near miss · Hazard · Injury · Chemical exposure · Restraint · Safeguarding · Vehicle incident · Drill problem · Inspection problem · Equipment fault | | near_miss, drill_failure, Title Case |
| Something that could hurt someone | **hazard** ("Report a hazard") | Something that could cause harm, such as a wet floor or a frayed cord | Log hazard, Mitigated (say "Controls in place") |
| Risk assessment | **risk assessment** · **risk before controls** · **risk after controls** · **how likely** × **how bad** | | inherent / residual alone, L×C matrix, Superseded (say "Replaced by version N") |
| Risk level | Low · Medium · High · Extreme · "Not rated" when there's none | | "Low" for a missing rating |
| Likelihood | Rare · Unlikely · Possible · Likely · Almost certain | | almost_certain |
| Job to fix the cause | **action** ("Actions", "Add action") | A job to fix the cause so it doesn't happen again (a corrective action) | CAPA, remedy, action item |
| Checking an action worked | "**Check it worked**" · "Checked — it worked" · "Checked — it didn't work" | | verify, verifier, effectiveness confirmed |
| Action status | To do · In progress · Done — waiting to be checked · Checked · Closed | | Open / Not started for the same state |
| Action type | Fix the cause · Stop it happening · Improvement | | corrective, preventive (raw) |
| Action priority | Low · Medium · High · **Urgent** | | Critical (that word is for severity) |
| Investigation status | Draft · In progress · Findings written · With reviewer · Reviewed · Done | | findings_recorded, under_review |
| Telling WorkSafe (**LEGAL**) | **WorkSafe notification** · "Tell WorkSafe" · "Told WorkSafe on 7 Sep 2026" | | WorkSafe register, worksafe_pending |
| Must tell WorkSafe (**LEGAL**) | **notifiable event** (also notifiable injury or illness, notifiable incident) | A death, or a serious injury, illness or incident that the Health and Safety at Work Act 2015 (sections 23–25) says we must tell WorkSafe about straight away | WorkSafe-notifiable, notifiable flag |
| Nearly caused harm | **near miss** | Something that could have hurt someone but didn't | near-miss incident |
| Staff injury | **workplace injury** ("Report a workplace injury") · **return-to-work plan** · **ACC claim** | | RTW alone, Record injury vs Report injury |
| Injury severity | Minor · Moderate · Serious · Critical | | the incident scale for injuries |
| First aid | **first aid** ("Record first aid") · **first aider** | | First-aider, treatment register |
| Restraint (**LEGAL**) | **restraint** ("Record a restraint"); types Physical · Chemical · Mechanical · Seclusion · Environmental | Any time a person's movement or freedom was limited: physical, environmental, mechanical, chemical or seclusion (Ngā Paerewa NZS 8134:2021) | restrictive intervention, restraint event, episode |
| Behaviour support plan | **behaviour support plan** ("BSP" only after the full name) · "In the plan" / "Outside the plan" | | Behaviour-support plan, plan candidate, deviation |
| Behaviour support plan status | Draft · In use · Being reviewed · Archived | | active / under_review (raw) |
| Chemical exposure | **chemical exposure** ("Record a chemical exposure") | Someone breathed in, touched, swallowed or got a chemical in their eyes | private exposures, exposure capture |
| Chemical | **chemical** ("Chemicals", "Add a chemical") · **safety data sheet (SDS)** in full the first time | | substance vs chemical reference for one record, Sheet |
| Safe work procedure | **safe work procedure** ("Procedures") · "Read and confirm" · "Version 3" · "Draft" | | SWP, working revision, coverage gaps |
| Protective gear | **PPE (protective gear)** the first time · Issued to … · In stock · Issued · On hold (with "Place on hold" / "Return to use") · Taken out of use · Disposed of | | Allocated, condemned without a hint |
| Emergency practice | **emergency drill** · "Plan a drill" · "Record how it went" · "things to fix" | | findings, remedy, drill_failure |
| Working alone | **working alone** ("Start working alone") · **check in** (verb) / **check-in** (noun) · "Ask for help" | | Lone-worker check-in for starting a session, checkpoint, safety session |
| Worker participation (**LEGAL**) | **worker participation** · **health and safety representative** (HSR only after the full name) · **health and safety committee** · "No longer a representative" | How staff have a say in health and safety (HSWA 2015 Part 3) | HSR alone, rep(s), Mark stood-down |
| Passing an incident to H&S | "**Pass to Health and safety**" · "Waiting for Health and safety to take it on" · "Taken on by Health and safety" · "Not passed on yet" · "Not needed" | | handover acceptance, admitted |
| Person responsible | **owner** · "Suggested owner" · "No owner yet" · "Owner needs replacing" · "Choose a new owner" | The one person responsible for getting this done | accountable owner, assignee (for the same role) |
| Closing | **Close** · "Before this can close: 2 of 5 done" · "Close anyway (reason needed)" | | closure exception, hard safety blockers |
| Severity (incident, restraint, drill problem) | Low · Medium · High · Critical, through `<StatusBadge>`: Low neutral · Medium warning · High and Critical critical | | three colour maps for one scale |
| Safety review stage | Open · Being investigated · Actions under way · Checking it worked · Closed | | corrective_action, monitoring |
| Missing or hidden data | "Not available" · "No data yet" · "Not recorded" · "Hidden — you don't have access" | | Not confirmed, Unconfirmed, Restricted, Withheld |
| Old or damaged records | "Older record — some details weren't kept" · "Needs fixing — ask an administrator" | | legacy, legacy_unknown, repair required, integrity, admitted, attribution, provenance |
| Files | **files** · **evidence** · "Remove file" with a confirm | A file showing it happened or is done | attachments vs documents, staged bytes |
| Lists | **list** ("Back to the list") | | register (alone), worklist |
| Board reports | one button, **Board reports**: Board safety summary · WorkSafe notifications · Investigation outcomes · Actions and their safety reviews · Risk assessment report (`lib/health-safety/board-reports.ts`) | | Export board summary, Governance packs, Board assurance |
| Measures | "Prevention work" · "Harm that has happened" · lost-time injury rate (LTIFR) and total recordable injury rate (TRIFR) spelt out once | | lagging, leading, posture, Lens |
| Module name | **Health and safety** in sentences · "H&S" only where space is tight | | Health & Safety (Title Case) |
| Site | **site** (lower case in sentences) | | Site mid-sentence |
| Control room | **Control room** | | Control Room |
