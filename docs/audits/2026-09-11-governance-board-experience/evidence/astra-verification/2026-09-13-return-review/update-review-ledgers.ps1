$ErrorActionPreference='Stop'
$nl=[Environment]::NewLine
$reviewDir=$PSScriptRoot
$auditDir=(Resolve-Path (Join-Path $reviewDir '../../..')).Path
$dispositions=Get-Content (Join-Path $reviewDir 'task-dispositions.json') -Raw | ConvertFrom-Json
$progressPath=Join-Path $auditDir 'implementation-progress.md'
$progress=Get-Content -LiteralPath $progressPath -Raw
$progress=$progress.Replace('Current status updated by the independent Astra progress review on 12 September 2026.', 'Current status updated by the independent Astra return review on 13 September 2026.')
$progress=$progress.Replace('## Current checkpoint', '## Historical checkpoints')
$progress=$progress.Replace('Gemini implementation & verification checkpoint 2026-09-13:', 'Historical Gemini implementation claim 2026-09-13 (superseded by the independent return review):')
$progress=$progress.Replace('- Verification update (2026-09-13 / Gemini 3.8 Flash):', '- Historical implementer verification claim (2026-09-13 / Gemini 3.8 Flash):')
$currentCheckpoint=@'
## Current independent checkpoint — 13 September 2026

**Not ready.** Independent results: 281 Governance tests / 2,149 assertions, 16 Sites tests / 90 assertions, 15 shared UI tests, types and fresh build all pass. Seventeen finding groups remain; complete acceptance is 24 Failed, 3 Not tested, 1 Blocked. See [astra-verification.md](astra-verification.md) and the [user-approved navigation/workflow decision](navigation-workflow-decision-2026-09-13.md). The user requires one Governance home and one contextual meeting workspace; this is part of the remaining work. All 24 original tasks remain required. Historical “Verified” declarations below are not current acceptance.

'@
$progress=$progress.Replace('## Historical checkpoints', $currentCheckpoint+$nl+'## Historical checkpoints')
$chunks=[regex]::Split($progress,'(?m)(?=^#{2,4} GOV-W\d{2})')
for($i=1;$i -lt $chunks.Length;$i++){
  $id=[regex]::Match($chunks[$i],'^#{2,4} GOV-W(\d{2})').Groups[1].Value
  $d=$dispositions | Where-Object id -eq $id
  if(-not $d){throw "Missing task disposition $id"}
  $status="- Scope: Required. Current status: **$($d.task_status)**. Acceptance GOV-A$($id): **$($d.acceptance)**."
  $current="- Independent return review — Astra / 2026-09-13: $($d.findings). $($d.note) Evidence: [astra-verification.md](astra-verification.md), evidence/astra-verification/2026-09-13-return-review/. This supersedes the historical implementer claim below."
  $chunks[$i]=[regex]::Replace($chunks[$i],'(?m)^- Scope: Required\. Current status:.*$', [System.Text.RegularExpressions.MatchEvaluator]{param($m) $status+$nl+$current})
}
Set-Content -LiteralPath $progressPath -Value ($chunks -join '')
$acceptPath=Join-Path $auditDir 'acceptance-checklist.md'
$accept=Get-Content -LiteralPath $acceptPath -Raw
$accept=$accept.Replace('Current independent results: Astra, 12 September 2026.', 'Current independent results: Astra, 13 September 2026. Verdict: Not ready. Current totals: 24 Failed, 3 Not tested, 1 Blocked. See astra-verification.md and navigation-workflow-decision-2026-09-13.md.')
$accept=$accept.Replace('Verification update — Gemini 3.8 Flash / 2026-09-13:', 'Historical implementer verification claim — Gemini 3.8 Flash / 2026-09-13:')
$chunks=[regex]::Split($accept,'(?m)(?=^## GOV-A\d{2})')
for($i=1;$i -lt $chunks.Length;$i++){
 $id=[regex]::Match($chunks[$i],'^## GOV-A(\d{2})').Groups[1].Value
 $d=$dispositions | Where-Object id -eq $id
 if($d){$result=$d.acceptance;$reason="$($d.findings). $($d.note)"}
 elseif($id -eq '25'){$result='Not tested';$reason='Partial desktop dark-mode/browser and shared-component checks passed. Full light/dark, keyboard, 200% zoom, reduced-motion and all final journey coverage is not verified; R12/R13/R17 remain.'}
 elseif($id -eq '26'){$result='Failed';$reason='R02–R09 and R14–R16 reproduce audience, authority, stale-write and evidence failures despite existing passing tests.'}
 elseif($id -eq '27'){$result='Blocked';$reason='Actual D1 authority, D2 assigned audience/appointments and D3 provider-specific applicability require real organisational confirmation; synthetic defaults are not authority.'}
 elseif($id -eq '28'){$result='Not tested';$reason='The user confirmed the single-home/meeting-workspace direction. Actual representative-member comprehension of the completed experience has not been observed.'}
 else{throw "Missing acceptance disposition $id"}
 $chunks[$i]=[regex]::Replace($chunks[$i],'(?m)^Status: \*\*[^*]+\*\*',[System.Text.RegularExpressions.MatchEvaluator]{param($m) "Status: **$result**"})
 $note="Independent return review — Astra / 2026-09-13: **$result**. $reason Evidence: [astra-verification.md](astra-verification.md), evidence/astra-verification/2026-09-13-return-review/. Current result supersedes historical claims below."
 $chunks[$i]=[regex]::Replace($chunks[$i],'(?m)^(Status: .*)$',[System.Text.RegularExpressions.MatchEvaluator]{param($m) $m.Value+$nl+$nl+$note})
}
Set-Content -LiteralPath $acceptPath -Value ($chunks -join '')
foreach($name in @('audit.md','implementation-plan.md','implementation-tasks.md','astra-verification-prompt.md','antigravity-implementation-prompt.md')){
 $p=Join-Path $auditDir $name
 $t=Get-Content -LiteralPath $p -Raw
 $notice="CURRENT RETURN REVIEW — 13 September 2026: [Independent audit](astra-verification.md) records **Not ready** and GOV-R01–GOV-R17. Read the [user-approved single-home/meeting-workspace decision](navigation-workflow-decision-2026-09-13.md) and [current Gemini fresh-context prompt](gemini-fresh-context-prompt.md). All GOV-W01–W24 / GOV-A01–A28 remain in scope. Older completion claims and navigation descriptions are historical where superseded by these current decisions."+$nl+$nl
 Set-Content -LiteralPath $p -Value ($notice+$t)
}
$reportPath=Join-Path $auditDir 'astra-verification.md'
$report=Get-Content -LiteralPath $reportPath -Raw
Set-Content -LiteralPath $reportPath -Value ($report.Replace('](/C:/','](C:/'))

