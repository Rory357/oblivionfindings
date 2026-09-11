$ErrorActionPreference = 'Stop'
$auditEvidence = $PSScriptRoot
$auditFolder = Split-Path $auditEvidence -Parent
$auditRepo = (Resolve-Path (Join-Path $auditFolder '../../..')).Path
$auditFiles = @('audit.md','implementation-plan.md','implementation-tasks.md','acceptance-checklist.md','implementation-progress.md','antigravity-implementation-prompt.md','astra-verification-prompt.md')
$auditTexts = @{}
foreach ($auditFile in $auditFiles) { $auditTexts[$auditFile] = Get-Content -Raw -LiteralPath (Join-Path $auditFolder $auditFile) }
$auditFindings = @([regex]::Matches($auditTexts['audit.md'], '(?m)^### (GOV-F\d{2})') | ForEach-Object { $_.Groups[1].Value })
$auditTasks = @([regex]::Matches($auditTexts['implementation-tasks.md'], '(?m)^## (GOV-W\d{2})') | ForEach-Object { $_.Groups[1].Value })
$auditCriteria = @([regex]::Matches($auditTexts['acceptance-checklist.md'], '(?m)^## (GOV-A\d{2})') | ForEach-Object { $_.Groups[1].Value })
$auditGaps = [System.Collections.Generic.List[string]]::new()
if ($auditFindings.Count -ne 26) { $auditGaps.Add('Expected 26 findings') }
if ($auditTasks.Count -ne 24) { $auditGaps.Add('Expected 24 tasks') }
if ($auditCriteria.Count -ne 28) { $auditGaps.Add('Expected 28 acceptance criteria') }
foreach ($auditFinding in $auditFindings) {
    foreach ($auditTarget in @('implementation-tasks.md','acceptance-checklist.md')) {
        if (-not $auditTexts[$auditTarget].Contains($auditFinding)) { $auditGaps.Add("Missing $auditFinding in $auditTarget") }
    }
}
foreach ($auditTask in $auditTasks) {
    foreach ($auditTarget in @('implementation-progress.md','acceptance-checklist.md','antigravity-implementation-prompt.md','astra-verification-prompt.md')) {
        if (-not $auditTexts[$auditTarget].Contains($auditTask)) { $auditGaps.Add("Missing $auditTask in $auditTarget") }
    }
}
foreach ($auditCriterion in $auditCriteria) {
    if (-not $auditTexts['implementation-progress.md'].Contains($auditCriterion)) { $auditGaps.Add("Missing $auditCriterion in ledger") }
}
if ([regex]::Matches($auditTexts['implementation-tasks.md'], '\*\*P[012]; Required; Not started\.\*\*').Count -ne 24) { $auditGaps.Add('Task initial statuses are not all Not started') }
if ([regex]::Matches($auditTexts['acceptance-checklist.md'], 'Status: \*\*Not run\*\*').Count -ne 28) { $auditGaps.Add('Acceptance initial statuses are not all Not run') }
$auditLinks = @()
foreach ($auditMd in (Get-ChildItem -LiteralPath $auditFolder -Filter '*.md' -Recurse)) {
    $auditContent = Get-Content -Raw -LiteralPath $auditMd.FullName
    foreach ($auditMatch in [regex]::Matches($auditContent, '\]\(([^)]+)\)')) {
        $auditTarget = $auditMatch.Groups[1].Value
        if ($auditTarget -match '^(https?://|#)') { continue }
        $auditTarget = ($auditTarget -split '#')[0].Trim('<','>')
        if (-not (Test-Path -LiteralPath (Join-Path $auditMd.DirectoryName $auditTarget))) { $auditGaps.Add("Broken local link in $($auditMd.Name): $auditTarget") }
        $auditLinks += $auditTarget
    }
}
$auditHashResults = @()
foreach ($auditHash in (Get-Content -Raw -LiteralPath (Join-Path $auditEvidence 'asset-and-design-hashes.json') | ConvertFrom-Json)) {
    $auditActual = (Get-FileHash -Algorithm SHA256 -LiteralPath $auditHash.Path).Hash
    $auditHashResults += [ordered]@{path=$auditHash.Path;baseline=$auditHash.Hash;actual=$auditActual;unchanged=($auditActual -eq $auditHash.Hash)}
    if ($auditActual -ne $auditHash.Hash) { $auditGaps.Add("Hash changed: $($auditHash.Path)") }
}
$auditIgnoreFile = Join-Path $auditEvidence 'empty-git-ignore.txt'
[System.IO.File]::WriteAllText($auditIgnoreFile, '')
$auditStatus = @(git -C $auditRepo -c "core.excludesfile=$auditIgnoreFile" status --short)
if ($LASTEXITCODE -ne 0) { throw 'git status failed; package preservation check incomplete' }
$auditStatus | Set-Content -Encoding utf8 -LiteralPath (Join-Path $auditEvidence 'dirty-tree-final.txt')
$auditGovDiff = @(git -C $auditRepo -c "core.excludesfile=$auditIgnoreFile" diff --name-only -- app/Domain/Governance resources/js/pages/governance resources/js/pages/Governance resources/js/components/governance routes/governance.php DESIGN.md design_styles)
if ($LASTEXITCODE -ne 0) { throw 'git diff failed; package preservation check incomplete' }
if ($auditGovDiff.Count -gt 0) { $auditGaps.Add('Governance/protected tracked files have a diff; reconcile ownership before claiming preservation') }
$auditResult = [ordered]@{checked_at=(Get-Date -Format o);findings=$auditFindings.Count;required_tasks=$auditTasks.Count;acceptance_criteria=$auditCriteria.Count;local_links_checked=$auditLinks.Count;gaps=@($auditGaps);hash_checks=$auditHashResults;governance_or_protected_tracked_diff=$auditGovDiff;note='Package verification only. No implementation criterion is passed by these checks. Concurrent unrelated IT/Fleet changes are preserved and separately inventoried.'}
$auditResult | ConvertTo-Json -Depth 6 | Set-Content -Encoding utf8 -LiteralPath (Join-Path $auditEvidence 'package-check-results.json')
[pscustomobject]$auditResult | Select-Object checked_at,findings,required_tasks,acceptance_criteria,local_links_checked,gaps,governance_or_protected_tracked_diff | ConvertTo-Json -Depth 4
if ($auditGaps.Count -gt 0) { exit 1 }
