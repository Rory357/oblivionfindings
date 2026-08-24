param()

$ErrorActionPreference = 'Stop'

$generatorDir = [System.IO.Path]::GetFullPath($PSScriptRoot)
$auditRoot = [System.IO.Path]::GetFullPath((Join-Path $generatorDir '..'))
$sourceDir = [System.IO.Path]::GetFullPath((Join-Path $auditRoot 'evidence\source'))
$manifestPath = Join-Path $sourceDir 'working-capability-manifest-902.json'
$inventoryPath = Join-Path $auditRoot 'inventory.json'
$findingsPath = Join-Path $auditRoot 'findings.json'
$sourceAdjudicationPath = Join-Path $sourceDir 'full-distinct-capability-adjudication.json'
$scorecardPath = Join-Path $auditRoot '04-workflow-usability-scorecard.csv'
$destination = [System.IO.Path]::GetFullPath((Join-Path $auditRoot 'task-scripts\final-902'))
$summaryPath = Join-Path $sourceDir 'final-902-task-script-generation-summary.json'

if (-not $destination.StartsWith($auditRoot, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw "Refusing to write outside audit root: $destination"
}

$manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json -Depth 100
$inventory = Get-Content -LiteralPath $inventoryPath -Raw | ConvertFrom-Json -Depth 100
$findings = Get-Content -LiteralPath $findingsPath -Raw | ConvertFrom-Json -Depth 100
$sourceAdjudication = Get-Content -LiteralPath $sourceAdjudicationPath -Raw | ConvertFrom-Json -Depth 100

$targets = @($manifest.targets)
$humanTargets = @($targets | Where-Object { $_.class -eq 'H' } | Sort-Object -Property @{Expression = { $_.working_key }; Ascending = $true })

if ($targets.Count -ne 902 -or $humanTargets.Count -ne 788) {
    throw "Manifest count mismatch: targets=$($targets.Count), H=$($humanTargets.Count)"
}

$stableIds = @($targets | ForEach-Object { [string]$_.working_key })
if (($stableIds | Sort-Object -Unique).Count -ne 902) {
    throw 'Manifest working_key values are not unique.'
}

$routeById = @{}
foreach ($route in @($inventory.routes)) {
    $routeById[[string]$route.route_id] = $route
}

$decisionByFamily = @{}
foreach ($decision in @($sourceAdjudication.decisions)) {
    $decisionByFamily[[string]$decision.legacy_family_id] = $decision
}

$routesByFamily = @{}
foreach ($route in @($inventory.routes)) {
    $family = [string]$route.legacy_family_id
    if ([string]::IsNullOrWhiteSpace($family)) { continue }
    if (-not $routesByFamily.ContainsKey($family)) { $routesByFamily[$family] = [System.Collections.Generic.List[string]]::new() }
    $routesByFamily[$family].Add([string]$route.route_id)
}

$pagesByFamily = @{}
$projectionFeatures = if ($null -ne $inventory.superseded_feature_projection -and $null -ne $inventory.superseded_feature_projection.features) {
    @($inventory.superseded_feature_projection.features)
} else {
    @($inventory.features)
}
foreach ($feature in $projectionFeatures) {
    $family = [string]$feature.legacy_family_id
    if ([string]::IsNullOrWhiteSpace($family)) { continue }
    if (-not $pagesByFamily.ContainsKey($family)) { $pagesByFamily[$family] = [System.Collections.Generic.List[string]]::new() }
    foreach ($pageId in @($feature.page_ids)) { $pagesByFamily[$family].Add([string]$pageId) }
}

$findingIdsByFeature = @{}
foreach ($finding in @($findings.findings)) {
    foreach ($featureId in @($finding.feature_ids)) {
        $key = [string]$featureId
        if (-not $findingIdsByFeature.ContainsKey($key)) { $findingIdsByFeature[$key] = [System.Collections.Generic.List[string]]::new() }
        $findingIdsByFeature[$key].Add([string]$finding.id)
    }
}

$actorByModule = @{
    ASSETS = 'Authorised asset custodian'
    AUTH = 'Account holder or authorised account administrator'
    CLIENTS = 'Authorised client-record practitioner'
    CLINICAL = 'Authorised clinical practitioner'
    CONTROL_ROOM = 'Authorised Control Room operator'
    EMAR = 'Authorised medication practitioner'
    FINANCE = 'Authorised finance practitioner or approver'
    FLEET = 'Authorised fleet practitioner or driver'
    FRONTLINE = 'Authorised frontline worker'
    GOVERNANCE = 'Authorised governance practitioner or approver'
    HEALTH_SAFETY = 'Authorised health and safety practitioner'
    HR = 'Authorised HR practitioner, manager or employee where self-service applies'
    INCIDENTS = 'Authorised incident or safeguarding practitioner'
    IT = 'Authorised IT service practitioner'
    OPERATIONS = 'Authorised operations practitioner, scheduler or frontline worker'
    PLATFORM = 'Authorised platform operator'
    PORTAL = 'Authorised client or delegate portal user'
    PRIVACY = 'Authorised privacy practitioner'
    PUBLIC = 'Public visitor or authenticated user where the route requires it'
    REPORTING = 'Authorised report reader'
    RESPITE = 'Authorised respite practitioner or approver'
    ROADMAP = 'Authorised roadmap contributor or decision-maker'
    SECURITY_DEVICES = 'Authorised security and device practitioner'
    SETTINGS = 'Authorised settings administrator or account holder where self-service applies'
    SITES = 'Authorised site practitioner'
}

function Unique-SortedStrings([object[]]$Values) {
    return @($Values | ForEach-Object { if ($null -ne $_ -and -not [string]::IsNullOrWhiteSpace([string]$_)) { [string]$_ } } | Sort-Object -Unique)
}

function Slug([string]$Value) {
    return (($Value.ToLowerInvariant() -replace '[^a-z0-9]+', '-') -replace '(^-+|-+$)', '')
}

function Job-Label([string]$Id) {
    $label = $Id -replace '^CAP-', ''
    $label = $label -replace '^(ASSET|AUTH|CLI|CLIN|COMP|CR|DAY|FIN|FLEET|GOV|HR|HS|INC|IT|MED|OPS|PLAT|PORT|PRIV|PUB|REP|RESP|ROAD|SEC|SET|SITE)-', ''
    $words = @($label -split '-' | Where-Object { $_ })
    $mapped = foreach ($word in $words) {
        switch ($word.ToUpperInvariant()) {
            'API' { 'API' }
            'CSV' { 'CSV' }
            'PDF' { 'PDF' }
            'QR' { 'QR' }
            'RAG' { 'RAG' }
            'SDS' { 'SDS' }
            'SLA' { 'SLA' }
            'SSO' { 'SSO' }
            'TOTP' { 'TOTP' }
            'IT' { 'IT' }
            default { (Get-Culture).TextInfo.ToTitleCase($word.ToLowerInvariant()) }
        }
    }
    return ($mapped -join ' ')
}

function Sha256-File([string]$Path) {
    return (Get-FileHash -Algorithm SHA256 -LiteralPath $Path).Hash.ToLowerInvariant()
}

function Sha256-Text([string]$Text) {
    $sha = [System.Security.Cryptography.SHA256]::Create()
    try {
        $bytes = [System.Text.UTF8Encoding]::new($false).GetBytes($Text)
        return ([System.BitConverter]::ToString($sha.ComputeHash($bytes))).Replace('-', '').ToLowerInvariant()
    } finally {
        $sha.Dispose()
    }
}

$expectedFilenames = @($humanTargets | ForEach-Object { "$(Slug ([string]$_.working_key)).md" })
if (($expectedFilenames | Sort-Object -Unique).Count -ne 788) {
    throw 'Human target IDs do not produce 788 unique task-script filenames.'
}

if (Test-Path -LiteralPath $destination) {
    $destinationItem = Get-Item -LiteralPath $destination
    if (-not $destinationItem.PSIsContainer) {
        throw "Task-script destination is not a directory: $destination"
    }

    $unexpectedChildren = @(Get-ChildItem -LiteralPath $destination -Force | Where-Object { $_.PSIsContainer -or $_.Name -notlike '*.md' })
    if ($unexpectedChildren.Count -gt 0) {
        throw "Task-script destination contains unexpected children: $($unexpectedChildren.Name -join ', ')"
    }

    $existingNames = @(Get-ChildItem -LiteralPath $destination -File -Filter '*.md' | ForEach-Object { $_.Name } | Sort-Object)
    if ($existingNames.Count -gt 0) {
        $expectedNamesSorted = @($expectedFilenames | Sort-Object)
        if ($existingNames.Count -ne $expectedNamesSorted.Count -or (Compare-Object -ReferenceObject $expectedNamesSorted -DifferenceObject $existingNames).Count -gt 0) {
            throw 'Task-script destination is non-empty but does not contain exactly the expected final-902 filename set. Refusing partial or stale overwrite.'
        }
    }
} else {
    New-Item -ItemType Directory -Path $destination | Out-Null
}

$scoreRows = [System.Collections.Generic.List[object]]::new()
$scriptIndex = [System.Collections.Generic.List[object]]::new()
$exactRouteScriptCount = 0
$exactPageScriptCount = 0
$envelopeOnlyCount = 0
$noEnvelopeCount = 0

foreach ($target in $humanTargets) {
    $id = [string]$target.working_key
    $module = [string]$target.canonical_module
    $sourceFamilies = Unique-SortedStrings @($target.source_family_ids)
    $exactRouteIds = Unique-SortedStrings @($target.route_ids)
    $exactPageIds = Unique-SortedStrings @($target.page_ids)
    $backendAnchors = Unique-SortedStrings @($target.backend_anchors)

    $familyRouteIds = [System.Collections.Generic.List[string]]::new()
    $familyPageIds = [System.Collections.Generic.List[string]]::new()
    foreach ($family in $sourceFamilies) {
        if ($decisionByFamily.ContainsKey($family)) {
            foreach ($routeId in @($decisionByFamily[$family].route_ids)) { $familyRouteIds.Add([string]$routeId) }
            foreach ($pageId in @($decisionByFamily[$family].page_ids)) { $familyPageIds.Add([string]$pageId) }
        }
        if ($routesByFamily.ContainsKey($family)) {
            foreach ($routeId in @($routesByFamily[$family])) { $familyRouteIds.Add([string]$routeId) }
        }
        if ($pagesByFamily.ContainsKey($family)) {
            foreach ($pageId in @($pagesByFamily[$family])) { $familyPageIds.Add([string]$pageId) }
        }
    }
    $familyRouteIds = Unique-SortedStrings $familyRouteIds
    $familyPageIds = Unique-SortedStrings $familyPageIds

    if ($exactRouteIds.Count -gt 0) { $exactRouteScriptCount++ }
    if ($exactPageIds.Count -gt 0) { $exactPageScriptCount++ }
    if ($exactRouteIds.Count -eq 0 -and ($familyRouteIds.Count -gt 0 -or $familyPageIds.Count -gt 0)) { $envelopeOnlyCount++ }
    if ($exactRouteIds.Count -eq 0 -and $exactPageIds.Count -eq 0 -and $familyRouteIds.Count -eq 0 -and $familyPageIds.Count -eq 0) { $noEnvelopeCount++ }

    $routeScope = if ($exactRouteIds.Count -gt 0) { 'target-supported exact/shared relation retained in the working manifest; not necessarily exclusive ownership' } elseif ($familyRouteIds.Count -gt 0) { 'source-family envelope only; within-family target allocation is not established' } else { 'no accepted-target route relation retained for this final target' }
    $pageScope = if ($exactPageIds.Count -gt 0) { 'target-supported exact/shared relation retained in the working manifest; not necessarily exclusive ownership' } elseif ($familyPageIds.Count -gt 0) { 'source-family envelope only; no exclusive target page is claimed' } else { 'no accepted-target page relation retained for this final target' }

    $routeRows = @($exactRouteIds | ForEach-Object { if ($routeById.ContainsKey($_)) { $routeById[$_] } })
    $routeNames = Unique-SortedStrings @($routeRows | ForEach-Object { $_.name })
    $routePaths = Unique-SortedStrings @($routeRows | ForEach-Object { $_.uri })
    $actions = Unique-SortedStrings @($routeRows | ForEach-Object {
        $action = [string]$_.action
        if ($action -match '@([^@]+)$') { $Matches[1] } elseif (-not [string]::IsNullOrWhiteSpace($action)) { $action }
    })
    $middleware = Unique-SortedStrings @($routeRows | ForEach-Object { @($_.middleware) })
    $permissionAtoms = Unique-SortedStrings @($middleware | ForEach-Object {
        if ($_ -like 'permission:*') { ($_ -replace '^permission:', '') -split '\|' }
    })
    $sharedRouteOwners = Unique-SortedStrings @($routeRows | ForEach-Object { @($_.working_canonical_feature_ids) } | Where-Object { $_ -ne $id })

    $job = Job-Label $id
    $actor = if ($actorByModule.ContainsKey($module)) { $actorByModule[$module] } else { "Authorised $module practitioner" }
    $findingIds = if ($findingIdsByFeature.ContainsKey($id)) { Unique-SortedStrings $findingIdsByFeature[$id] } else { @() }
    $filename = "$(Slug $id).md"
    $path = Join-Path $destination $filename

    $routeEvidenceLine = if ($exactRouteIds.Count -gt 0) { '`' + ($exactRouteIds -join '`, `') + '`' } elseif ($familyRouteIds.Count -gt 0) { 'Not target-exclusive. Source-family envelope: `' + ($familyRouteIds -join '`, `') + '`' } else { 'Not enriched.' }
    $pageEvidenceLine = if ($exactPageIds.Count -gt 0) { '`' + ($exactPageIds -join '`, `') + '`' } elseif ($familyPageIds.Count -gt 0) { 'Not target-exclusive. Source-family envelope: `' + ($familyPageIds -join '`, `') + '`' } else { 'Not enriched.' }
    $routeNameLine = if ($routeNames.Count -gt 0) { '`' + ($routeNames -join '`, `') + '`' } else { 'Not target-enriched.' }
    $routePathLine = if ($routePaths.Count -gt 0) { '`' + ($routePaths -join '`, `') + '`' } else { 'Not target-enriched.' }
    $actionLine = if ($actions.Count -gt 0) { '`' + ($actions -join '`, `') + '`' } else { 'No target-supported action list established.' }
    $permissionLine = if ($permissionAtoms.Count -gt 0) { '`' + ($permissionAtoms -join '`, `') + '`' } else { 'Target-supported route permission atoms not enriched; authorization must be checked at execution.' }
    $sharedRouteOwnerLine = if ($sharedRouteOwners.Count -gt 0) { '`' + ($sharedRouteOwners -join '`, `') + '`' } else { 'No other accepted working IDs share these retained route relations.' }
    $backendLine = if ($backendAnchors.Count -gt 0) { '`' + ($backendAnchors -join '`, `') + '`' } else { 'Not target-enriched; source-family/controller evidence remains in the audit inventory.' }
    $findingLine = if ($findingIds.Count -gt 0) { '`' + ($findingIds -join '`, `') + '`' } else { 'No exact working-ID finding link is currently established.' }
    $capabilityCode = '`' + $id + '`'
    $moduleCode = '`' + $module + '`'
    $idStatusCode = '`' + [string]$target.id_status + '`'
    $sourceFamilyLine = if ($sourceFamilies.Count -gt 0) { '`' + ($sourceFamilies -join '`, `') + '`' } else { 'None retained.' }

    $markdown = @"
# $id — $job

Status: **Blocked — source-derived final-ID script; representative-role execution and independent task validation not performed.**

## Evidence boundary

- Capability: $capabilityCode
- Canonical module: $moduleCode
- ID provenance: $idStatusCode
- Source families: $sourceFamilyLine
- Route scope: $routeScope
- Route evidence: $routeEvidenceLine
- Route names: $routeNameLine
- Route paths: $routePathLine
- Page scope: $pageScope
- Page evidence: $pageEvidenceLine
- Target-supported route actions: $actionLine
- Other accepted IDs sharing retained routes: $sharedRouteOwnerLine
- Backend anchors: $backendLine
- Exact working-ID findings: $findingLine

Blank or source-family-envelope evidence must not be read as proof that this capability has no route/page or that every family route belongs exclusively to it.

## Representative task

Actor: $actor

Goal: Complete **$job** on the authoritative record, then verify an unambiguous persisted outcome, actor/time provenance, downstream effect and next accountable owner or terminal state.

Prerequisites:

- A non-production representative account with the documented global/site/ownership scope.
- A resettable synthetic record in the correct prerequisite state.
- Target authorization evidence. Exact route permission atoms where enriched: $permissionLine
- Known wrong-site, wrong-parent and wrong-record fixtures for denial checks.

Steps:

1. Enter through an authorised route/page for this final capability. Do not assume a retained shared relation is an exclusive entry or ownership claim.
2. Confirm the actor, site, parent/child relation, owning record and prerequisite state before disclosing or changing data.
3. Perform only the action(s) evidenced for this capability; do not infer a split target's action from the entire source-family envelope.
4. Verify the authoritative persisted state and immutable/auditable actor, effective time and source provenance. A rendered page, toast or HTTP success alone is not completion.
5. Verify the next owner, notification/outbox/reporting effect or terminal outcome, then exercise the documented correction/retry path where safe.

## Required error and recovery checks

- Wrong site, person, parent or nested child: deny before disclosure or side effect.
- Invalid input: retain safe input, bind messages to fields and preserve authoritative state.
- Stale, concurrent or replayed action: at most one effect; expose the current state and a safe retry/review path.
- Background or integration failure: retain visible queued/failed evidence, stable source identity and authorised replay/reconciliation.
- Correction/reversal: preserve prior provenance and re-check authorization and state.

## Current ease scores

All ten current scores are **Not measured**. Under the audit rubric, numeric 0 means blocked, misleading, inaccessible or missing; it is therefore not used as a substitute for absent representative-user measurement.

| Dimension | Score |
|---|---:|
| Discoverability | Not measured |
| Comprehension | Not measured |
| Learnability | Not measured |
| Efficiency | Not measured |
| Error prevention | Not measured |
| Recovery | Not measured |
| Accessibility | Not measured |
| Safety and trust | Not measured |
| Consistency | Not measured |
| Cross-module continuity | Not measured |

Target scores are not assigned until the task is executed and independently reviewed. No ease or completion claim is made.
"@

    [System.IO.File]::WriteAllText($path, $markdown.TrimEnd() + "`n", [System.Text.UTF8Encoding]::new($false))

    $scoreRows.Add([pscustomobject][ordered]@{
        task_script_id = "TASK-$id"
        feature_id = $id
        module = $module
        actor = $actor
        task = "Complete $job and verify persisted completion and hand-off evidence"
        start_condition = "Authorised representative actor and resettable synthetic prerequisite; target entry is $routeScope."
        goal = "Authoritative persisted outcome, provenance, downstream effect and next owner or terminal state."
        prerequisites = "Representative account; correct site/ownership/parent relation; resettable fixture; wrong-object denial fixtures."
        observed_or_inferred = 'Source-derived final-ID task; runtime unverified'
        validation_status = 'Blocked—source-derived final-ID task exists; 0/788 representative-role tasks executed; independent semantic/usability review pending'
        score_measurement_status = 'Not measured'
        score_scale = '0-5; blank means not measured'
        discoverability = ''
        comprehension = ''
        learnability = ''
        efficiency = ''
        error_prevention = ''
        recovery = ''
        accessibility = ''
        safety_and_trust = ''
        consistency = ''
        cross_module_continuity = ''
        completion_time = 'Not measured'
        step_count = '5 source-bound steps; rendered/conditional count not measured'
        required_field_count = 'Not measured'
        decision_count = 'Not measured'
        context_switches = 'Not measured'
        dead_ends = 'Not measured'
        recovery_path = 'Wrong-object denial; validation preservation; concurrency/replay at-most-once; queued/failure visibility; authorised correction/reversal. Exact runtime paths unverified.'
        target_scores = '{"all_dimensions":null,"safety_critical_error_prevention_and_trust":null}'
        independent_review = 'Blocked—no representative-user task execution or independent score review'
        finding_ids = ($findingIds -join ' | ')
    })

    $scriptIndex.Add([pscustomobject][ordered]@{
        feature_id = $id
        file = "task-scripts/final-902/$filename"
        sha256 = Sha256-File $path
        route_scope = $routeScope
        exact_route_count = $exactRouteIds.Count
        source_family_route_envelope_count = $familyRouteIds.Count
        exact_page_count = $exactPageIds.Count
        source_family_page_envelope_count = $familyPageIds.Count
        finding_count = $findingIds.Count
    })
}

$scoreRows | Export-Csv -LiteralPath $scorecardPath -NoTypeInformation -Encoding utf8NoBOM

$existingFiles = @(Get-ChildItem -LiteralPath $destination -File -Filter '*.md')
$actualFilenames = @($existingFiles | ForEach-Object { $_.Name } | Sort-Object)
$expectedFilenamesSorted = @($expectedFilenames | Sort-Object)
if ($existingFiles.Count -ne 788 -or (Compare-Object -ReferenceObject $expectedFilenamesSorted -DifferenceObject $actualFilenames).Count -gt 0) {
    throw "Final task-script directory count mismatch: $($existingFiles.Count)"
}

$scriptIndexByFeature = [System.Collections.Generic.Dictionary[string, object]]::new([System.StringComparer]::Ordinal)
foreach ($scriptRow in $scriptIndex) {
    $scriptIndexByFeature.Add([string]$scriptRow.feature_id, $scriptRow)
}
[string[]]$orderedFeatureIds = @($scriptIndex | ForEach-Object { [string]$_.feature_id })
[System.Array]::Sort($orderedFeatureIds, [System.StringComparer]::Ordinal)
$orderedScriptIndex = @($orderedFeatureIds | ForEach-Object { $scriptIndexByFeature[$_] })

$indexLines = @($orderedScriptIndex | ForEach-Object { "$($_.feature_id)|$($_.file)|$($_.sha256)" })
$indexText = $indexLines -join "`n"
$scorecardSha = Sha256-File $scorecardPath

$summary = [ordered]@{
    schema_version = '1.0'
    artifact = 'final-902-task-script-generation-summary'
    audited_commit = [string]$manifest.audited_commit
    generated_at = (Get-Date).ToString('o')
    status = 'structurally_materialized_runtime_and_independent_validation_blocked'
    audit_boundary = 'Audit artifacts only. No application code, configuration, routes, data, tests, browser state, deployment or Git history changed.'
    input = [ordered]@{
        manifest = 'working-capability-manifest-902.json'
        manifest_sha256 = Sha256-File $manifestPath
        inventory = '../../inventory.json'
        inventory_sha256 = Sha256-File $inventoryPath
        findings = '../../findings.json'
        findings_sha256 = Sha256-File $findingsPath
        source_adjudication = 'full-distinct-capability-adjudication.json'
        source_adjudication_sha256 = Sha256-File $sourceAdjudicationPath
    }
    counts = [ordered]@{
        manifest_total = 902
        human_targets = 788
        generated_task_scripts = $existingFiles.Count
        scorecard_rows = $scoreRows.Count
        exact_route_enriched_scripts = $exactRouteScriptCount
        exact_page_enriched_scripts = $exactPageScriptCount
        source_family_envelope_only_scripts = $envelopeOnlyCount
        scripts_without_route_or_page_envelope = $noEnvelopeCount
        representative_role_tasks_executed = 0
        independently_validated_scripts = 0
        current_scores_measured = 0
    }
    proof_boundary = [ordered]@{
        structural_script_coverage = '788/788'
        substantive_runtime_validation = '0/788'
        ease_score_validation = '0/788; all current and target score cells are blank/null until representative execution and independent review'
        route_page_rule = 'Target-supported exact/shared manifest relations are retained without claiming exclusivity. Source-family envelopes are labelled non-exclusive and never promoted as target allocation.'
        target_scores = 'not assigned'
    }
    outputs = [ordered]@{
        directory = 'task-scripts/final-902'
        scorecard = '04-workflow-usability-scorecard.csv'
        scorecard_sha256 = $scorecardSha
        script_index_sha256 = Sha256-Text $indexText
        script_index_algorithm = 'Ordinal feature_id sort; UTF-8 LF/no-terminal-LF lines feature_id|file|sha256'
    }
    scripts = $orderedScriptIndex
}

[System.IO.File]::WriteAllText($summaryPath, (($summary | ConvertTo-Json -Depth 20) + "`n"), [System.Text.UTF8Encoding]::new($false))

$parsedSummary = Get-Content -LiteralPath $summaryPath -Raw | ConvertFrom-Json -Depth 30
$parsedScorecard = @(Import-Csv -LiteralPath $scorecardPath)
if ($parsedSummary.counts.generated_task_scripts -ne 788 -or $parsedScorecard.Count -ne 788 -or (@($parsedScorecard.feature_id | Sort-Object -Unique).Count -ne 788)) {
    throw 'Generated task-script validation failed.'
}

[pscustomobject]@{
    scripts = 788
    scorecard_rows = 788
    representative_role_tasks_executed = 0
    task_script_index_sha256 = $parsedSummary.outputs.script_index_sha256
    scorecard_sha256 = $parsedSummary.outputs.scorecard_sha256
    summary_sha256 = Sha256-File $summaryPath
}
