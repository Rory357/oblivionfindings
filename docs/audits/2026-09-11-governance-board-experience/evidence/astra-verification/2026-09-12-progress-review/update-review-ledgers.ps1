$ErrorActionPreference='Stop'
$nl=[Environment]::NewLine
$auditRoot=(Resolve-Path (Join-Path $PSScriptRoot '../../..')).Path
if ((Split-Path $auditRoot -Leaf) -ne '2026-09-11-governance-board-experience') { throw 'Audit root guard failed' }
$disposition=Get-Content (Join-Path $PSScriptRoot 'review-disposition.json') -Raw | ConvertFrom-Json
$reviewLink='[independent review](astra-progress-review-2026-09-12.md)'
$promptLink='[Gemini correction prompt](gemini-correction-prompt.md)'
function WriteAudit([string]$name,[string]$value) {
 $target=[IO.Path]::GetFullPath((Join-Path $auditRoot $name))
 if (!$target.StartsWith($auditRoot+[IO.Path]::DirectorySeparatorChar)) { throw 'Path guard failed' }
 [IO.File]::WriteAllText($target,$value,[Text.UTF8Encoding]::new($false))
}
$progress=Get-Content (Join-Path $auditRoot 'implementation-progress.md') -Raw
$progress=$progress -replace 'Initial ledger prepared during audit\.[^\r\n]*','Current status updated by the independent Astra progress review on 12 September 2026. Original Gemini declarations are retained below as history; see evidence/astra-verification/2026-09-12-progress-review/gemini-progress-before-review.md.'
$checkpoint=@"
## Current checkpoint
Astra review 2026-09-12: **substantial useful progress; acceptance reopened; not ready for live board use.** Independent Governance suite: 281 passed / 2,148 assertions / exit 0; types and fresh build exit 0. Those checks do not resolve the 13 findings in $reviewLink.

Next action: use $promptLink. Repair the isolated harness (R01), privacy/rules (R02/R03), voting/version/pack/action/approval integrity (R04-R08), work/overview/calendar contracts (R09-R11), and connected authoring/Rory conformance (R12/R13), then complete all remaining GOV-W17-GOV-W24. W17 already has partial source/test work. Preserve unrelated working-tree edits and the good implementation.

W01-W15 are In progress (reopened); W16 is Implemented (not yet verified); W17 is In progress; W18-W24 remain Not started in the ledger. A27 external authority/appointments/service applicability remains Blocked; representative-user A28 is Not tested. The researched candidate rules do not authorize live operation. No application fixes were made by this review.

"@
$progress=[regex]::Replace($progress,'(?ms)^## Current checkpoint\r?\n.*?(?=^## Required task ledger)',$checkpoint+$nl)
$progress=[regex]::Replace($progress,'(?ms)(^### GOV-W(\d{2})[^\r\n]*\r?\n)(.*?)(?=^### GOV-W|^## Acceptance ledger)',{
 param($m)
 $n=[int]$m.Groups[2].Value
 $id='GOV-W'+$m.Groups[2].Value
 $aid='GOV-A'+$m.Groups[2].Value
 $state=if($n -le 15 -or $n -eq 17){'In progress'}elseif($n -eq 16){'Implemented (not yet verified)'}else{'Not started'}
 $astate=$disposition.acceptance.$aid
 $why=$disposition.tasks.$id
 $body=$m.Groups[3].Value
 $old=[regex]::Match($body,'(?m)^- Scope:[^\r\n]*').Value
 $new=@(
 "- Scope: Required. Current status: **$state**. Acceptance $($aid): **$astate**."
 "- Astra review 2026-09-12: $why. See $reviewLink and evidence/astra-verification/2026-09-12-progress-review/."
 "- Prior implementer declaration (historical): $($old -replace '^- ','')"
 ) -join $nl
 if(!$old){throw "Missing task scope $id"}
 $body=$body.Replace($old,$new)
 return $m.Groups[1].Value+$body
})
$progress=[regex]::Replace($progress,'(?m)^- (GOV-A(\d{2})) — ([^\r\n]+)$',{
 param($m)
 $id=$m.Groups[1].Value
 $n=[int]$m.Groups[2].Value
 $prior=$m.Value
 $title=($m.Groups[3].Value -split ': \*\*')[0]
 $result=$disposition.acceptance.$id
 $why=if($n -le 24){$disposition.tasks.('GOV-W'+$m.Groups[2].Value)}elseif($n -eq 25){'Partial desktop smoke only; full accessibility/shared regression not completed'}elseif($n -eq 26){'R02-R08 reproduced privacy/authority/integrity defects'}elseif($n -eq 27){'Actual D1 authority, assignments and D3 applicability not supplied'}else{'Representative-member sessions not run'}
 return "- $id — $($title): **$result**. Astra / 2026-09-12. $why. Evidence: $reviewLink and evidence/astra-verification/2026-09-12-progress-review/."+$nl+"  Prior implementer entry (historical): $($prior -replace '^- ','')"
})
WriteAudit 'implementation-progress.md' $progress

$accept=Get-Content (Join-Path $auditRoot 'acceptance-checklist.md') -Raw
$accept=$accept -replace 'Every item is initially \*\*Not run\*\*\.[^\r\n]*','Current independent results: Astra, 12 September 2026. These assess the full criteria; partial green tests are recorded without implying end-to-end acceptance. Original implementer evidence remains below and in evidence/astra-verification/2026-09-12-progress-review/acceptance-before-review.md.'
$accept=[regex]::Replace($accept,'(?ms)(^## (GOV-A(\d{2}))[^\r\n]*\r?\n)(.*?)(?=^## GOV-A|\z)',{
 param($m)
 $id=$m.Groups[2].Value
 $n=[int]$m.Groups[3].Value
 $body=$m.Groups[4].Value
 $old=[regex]::Match($body,'(?m)^Status: \*\*[^*]+\*\*\.').Value
 if(!$old){throw "Missing acceptance status $id"}
 $body=$body.Replace($old,"Status: **$($disposition.acceptance.$id)**.")
 $body=[regex]::Replace($body,'(?m)^Evidence:','Prior implementer evidence (historical claim):')
 $why=if($n -le 24){$disposition.tasks.('GOV-W'+$m.Groups[3].Value)}elseif($n -eq 25){'Both desktop widths were sampled, but the full keyboard/zoom/theme/motion/shared-regression matrix was not completed'}elseif($n -eq 26){'Privacy, rule activation, minute version, action concurrency and approval authority defects reproduced (R02-R08)'}elseif($n -eq 27){'Actual constitution/rule approval, role assignments and service applicability are outstanding external gates'}else{'No representative-member comprehension session has been completed'}
 return $m.Groups[1].Value+$body.TrimEnd()+$nl+$nl+"Independent review — Astra / 2026-09-12: $why. Evidence: $reviewLink and evidence/astra-verification/2026-09-12-progress-review/. Prior status was $($old -replace '^Status: ','')."+$nl+$nl
})
WriteAudit 'acceptance-checklist.md' $accept

$tasks=Get-Content (Join-Path $auditRoot 'implementation-tasks.md') -Raw
$tasks=$tasks -replace 'All 24 tasks are \*\*Required for this implementation\*\*\.[^\r\n]*','All 24 tasks remain **Required for this implementation**. Current implementation/acceptance statuses live in implementation-progress.md and acceptance-checklist.md; this file specifies scope.'
$tasks=$tasks -replace '; Required; Not started\.','; Required.'
$tasks=[regex]::Replace($tasks,'(# Required implementation tasks\r?\n)','$1'+$nl+"Resume update 2026-09-12: read $reviewLink and $promptLink. GOV-R01-GOV-R13 are required corrections mapped to these existing tasks; none of GOV-W01-GOV-W24 is dropped. Preserve the Sites calendar reuse and complete Rory's UI requirements."+$nl+$nl)
WriteAudit 'implementation-tasks.md' $tasks

$plan=Get-Content (Join-Path $auditRoot 'implementation-plan.md') -Raw
$plan=$plan -replace 'Status: specification complete; implementation Not started; acceptance Not run\.','Status: specification retained; implementation partial and acceptance reopened by Astra on 12 September 2026. Current statuses: implementation-progress.md and acceptance-checklist.md.'
$plan=[regex]::Replace($plan,'(# Governance implementation plan\r?\n)','$1'+$nl+"Current correction order and evidence: $reviewLink. Resume with $promptLink before moving past the failed foundational acceptance. The original complete required scope and external D1/D2/D3 boundaries remain."+$nl+$nl)
WriteAudit 'implementation-plan.md' $plan

$audit=Get-Content (Join-Path $auditRoot 'audit.md') -Raw
$audit=[regex]::Replace($audit,'(# Governance board-experience audit — 11 September 2026\r?\n)','$1'+$nl+"**Return review, 12 September 2026:** substantial implementation progress, 281 Governance tests passing, but 13 correction findings and reopened acceptance. Read $reviewLink and $promptLink for current results. The remainder of this file preserves the original baseline audit."+$nl+$nl)
WriteAudit 'audit.md' $audit

$impl=Get-Content (Join-Path $auditRoot 'antigravity-implementation-prompt.md') -Raw
$impl="CURRENT RESUME UPDATE — 12 September 2026: First read C:/Users/steph/Herd/oblivionfindings/docs/audits/2026-09-11-governance-board-experience/gemini-correction-prompt.md and astra-progress-review-2026-09-12.md in that same folder. Fix GOV-R01-GOV-R13, then finish the remaining required tasks. Do not rely on old W01-W16 Verified declarations. Preserve actual Sites calendar reuse and complete Rory's UI rules. This original prompt remains the full implementation scope."+$nl+$nl+$impl
WriteAudit 'antigravity-implementation-prompt.md' $impl

$verify=Get-Content (Join-Path $auditRoot 'astra-verification-prompt.md') -Raw
$verify="RETURN-REVIEW UPDATE — 12 September 2026: In addition to every original task/criterion below, independently verify all GOV-R01-GOV-R13 corrections in C:/Users/steph/Herd/oblivionfindings/docs/audits/2026-09-11-governance-board-experience/astra-progress-review-2026-09-12.md. Read gemini-correction-prompt.md and the preserved evidence/astra-verification/2026-09-12-progress-review results in that folder. The interim run passed 281 tests/2,148 assertions, types and build but reproduced privacy, voting, minute-version, action, authority, work-feed, calendar and authoring failures. Re-test actual behavior rather than accepting summaries, and inspect a guarded disposable setup before any seeding. Require normal board roles (not admin), real source/build identity and normal desktop browser journeys. Distinguish unfinished work, baseline defects and introduced regressions; preserve all task IDs, failures and external gates. Reconfirm that Governance uses the actual shared Sites calendar and that new/edit entry points use Rory's shared WizardShell."+$nl+$nl+$verify
WriteAudit 'astra-verification-prompt.md' $verify
Write-Output 'Updated all seven original audit/handoff documents; prior implementation evidence preserved.'

