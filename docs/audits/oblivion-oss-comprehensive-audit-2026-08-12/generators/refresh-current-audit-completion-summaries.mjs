import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const generatorDir = path.dirname(fileURLToPath(import.meta.url));
const audit = path.dirname(generatorDir);
const source = path.join(audit, 'evidence', 'source');
const finalizeValidation = process.argv.includes('--finalize-validation');

const readText = (file) => fs.readFileSync(file, 'utf8');
const readJson = (file) => JSON.parse(readText(file));
const writeJson = (file, value) => fs.writeFileSync(file, `${JSON.stringify(value, null, 2)}\n`, 'utf8');
const sha256 = (file) => crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');

function replaceOnce(file, pattern, replacement, label) {
  const input = readText(file);
  const matches = input.match(pattern);
  if (!matches || matches.length !== 1) {
    throw new Error(`Expected exactly one ${label} replacement in ${file}`);
  }
  const output = input.replace(pattern, replacement);
  fs.writeFileSync(file, output, 'utf8');
}

const findingsPath = path.join(audit, 'findings.json');
const manifestPath = path.join(source, 'working-capability-manifest-902.json');
const completionPath = path.join(source, 'completion-gate-report.json');
const orchestrationPath = path.join(source, 'orchestration-status-2026-08-14.json');
const clinicalEvidencePath = path.join(source, 'browser-clinical-lead-current-main-pass-902.json');
const findings = readJson(findingsPath).findings;
const manifest = new Set(readJson(manifestPath).targets.map((target) => target.working_key));
const completion = readJson(completionPath);
const orchestration = readJson(orchestrationPath);
const clinicalEvidence = readJson(clinicalEvidencePath);
const byPriority = Object.fromEntries(['P0', 'P1', 'P2'].map((priority) => [
  priority,
  findings.filter((finding) => finding.priority === priority).length,
]));
const exactPairs = findings.flatMap((finding) =>
  (finding.feature_ids ?? [])
    .filter((featureId) => manifest.has(featureId))
    .map((featureId) => [finding.id, featureId]),
);
const findingsWithExact = new Set(exactPairs.map(([findingId]) => findingId));
const p0p1 = findings.filter((finding) => finding.priority === 'P0' || finding.priority === 'P1');
const p0p1WithExact = p0p1.filter((finding) => findingsWithExact.has(finding.id));
const counts = {
  findings: findings.length,
  p0: byPriority.P0,
  p1: byPriority.P1,
  p2: byPriority.P2,
  p0p1: p0p1.length,
  exactLinks: exactPairs.length,
  exactTargets: new Set(exactPairs.map(([, featureId]) => featureId)).size,
  p0p1WithExact: p0p1WithExact.length,
};
const historicalBrowserPin = String(clinicalEvidence.remediation_main_commit);

if (
  counts.findings !== 92 || counts.p0 !== 18 || counts.p1 !== 62 || counts.p2 !== 12
  || counts.p0p1 !== 80 || counts.exactLinks !== 159 || counts.p0p1WithExact !== 80
) {
  throw new Error(`Canonical finding counts drifted: ${JSON.stringify(counts)}`);
}
if (completion.status !== 'BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE') {
  throw new Error(`Completion status drifted: ${completion.status}`);
}
if (!Array.isArray(completion.completion_blockers) || completion.completion_blockers.length !== 19) {
  throw new Error('Completion blocker list is not the canonical 19-item list');
}
if (!/^[0-9a-f]{40}$/.test(historicalBrowserPin)) {
  throw new Error('Historical browser-evidence pin is invalid');
}

const executivePath = path.join(audit, '00-executive-summary.md');
replaceOnce(
  executivePath,
  /(?:Current remediation snapshot `origin\/main`|Historical browser-evidence pin \(not current `origin\/main`\)): `ad19f994a280835d039d1a31ebdcb05778733c5a`/,
  `Historical browser-evidence pin (not current \`origin/main\`): \`${historicalBrowserPin}\``,
  'historical browser-evidence pin label',
);
replaceOnce(
  executivePath,
  /\| P0\/P1 findings with exact current-ID links \| \d+ \/ \d+ \| All \d+ retained findings have at least one literal current ID through \d+ exact links; exact linkage remains attribution evidence, not runtime proof \|/,
  `| P0/P1 findings with exact current-ID links | ${counts.p0p1WithExact} / ${counts.p0p1} | All ${counts.findings} retained findings have at least one literal current ID through ${counts.exactLinks} exact links; exact linkage remains attribution evidence, not runtime proof |`,
  'executive finding-link counts',
);
replaceOnce(
  executivePath,
  /- \*\*\d+ P1\*\* — serious authorization/,
  `- **${counts.p1} P1** — serious authorization`,
  'executive P1 count',
);

const moduleFindingsPath = path.join(audit, '07-module-findings.md');
replaceOnce(
  moduleFindingsPath,
  /^This document is the human-readable companion to `findings\.json`\..*$/m,
  `This document is the human-readable companion to \`findings.json\`. Counts in the retained finding set are **${counts.p0} P0, ${counts.p1} P1 and ${counts.p2} P2**. The feature tables below are a **superseded 740-row projection**, not the final canonical register. The corrected current register is **902 capabilities (788 human, 111 download/API and three machine-ingress)**, and matrices 02–03 contain all 902 IDs. Finding linkage now resolves to ${counts.exactLinks} literal exact current-ID links across ${counts.exactTargets} targets: all ${counts.findings} retained findings and all ${counts.p0p1} P0/P1 findings have at least one current ID. The retained additions include email-verification contract enforcement, export-permission convergence, the inactive renewals selector, the signal-to-alert machine pipeline, System Users count animation, My Day and eMAR narrow-width overflow, the audited Clinical/Medication Lead account-creation blocker, the authenticated Shift task-provider failure, destructible safeguarding evidence, and the unsafe generic payment-allocation writer. Exact string matches remain lineage, not runtime proof. A blank findings cell means only that no distinct retained finding linked to the old projection row.`,
  'module finding counts',
);

const unresolvedPath = path.join(audit, '13-unresolved-questions-and-evidence-gaps.md');
replaceOnce(
  unresolvedPath,
  /- The stable-ID spelling and route\/page dispositions are reflected in the canonical register\. All \d+ retained findings and \d+\/\d+ P0\/P1 findings have at least one literal current ID through \d+ exact links; partial visual linkage and absent runtime proof still prevent completion\./,
  `- The stable-ID spelling and route/page dispositions are reflected in the canonical register. All ${counts.findings} retained findings and ${counts.p0p1WithExact}/${counts.p0p1} P0/P1 findings have at least one literal current ID through ${counts.exactLinks} exact links; partial visual linkage and absent runtime proof still prevent completion.`,
  'unresolved finding-link counts',
);
replaceOnce(
  unresolvedPath,
  /Completion remains blocked because \d+ benchmark targets remain unproved/,
  `Completion remains blocked because 451 benchmark targets remain unproved`,
  'unresolved benchmark count',
);
replaceOnce(
  executivePath,
  /Focused remediation has since demonstrated disposable-MySQL execution and repaired the supported Clinical Lead creation path on (?:current main|the historical browser-evidence pin `[^`]+`), but those later fixes do not count as audit-wide task, actor or test execution\./,
  `Focused remediation has since demonstrated disposable-MySQL execution and repaired the supported Clinical Lead creation path on the historical browser-evidence pin \`${historicalBrowserPin}\`, but those later fixes do not count as audit-wide task, actor or test execution.`,
  'executive historical remediation pin',
);
replaceOnce(
  executivePath,
  /The supported creation path (?:is now fixed on current main|(?:is|was) fixed on the historical browser-evidence pin `[^`]+`), and a later direct-login pass sampled the synthetic Clinical Lead on Health & Clinical and eMAR at all four required viewports\./,
  `The supported creation path was fixed on the historical browser-evidence pin \`${historicalBrowserPin}\`, and a later direct-login pass sampled the synthetic Clinical Lead on Health & Clinical and eMAR at all four required viewports.`,
  'executive historical Clinical Lead pin',
);
replaceOnce(
  unresolvedPath,
  /\| Clinical Lead account creation UI \| .*? \| Browser-execute the complete create-and-verify workflow only with a resettable fixture, then remove or retain the synthetic identity under the test-data policy\. \|/,
  `| Clinical Lead account creation UI | Fixed on historical browser-evidence pin ${historicalBrowserPin.slice(0, 7)} (not current origin/main); resulting synthetic actor directly browser-sampled | The audited snapshot recorded a safe pre-submit failure in \`evidence/source/browser-clinical-lead-account-creation-attempt.json\`. The historical browser-evidence pin has a supported HR People creation contract, and the resulting synthetic Clinical Lead can authenticate and render its core modules. The creation form itself was not resubmitted in this bounded pass. | Browser-execute the complete create-and-verify workflow only with a resettable fixture, then remove or retain the synthetic identity under the test-data policy. |`,
  'unresolved historical Clinical Lead pin',
);
replaceOnce(
  unresolvedPath,
  /\| Independent visual-finding resample \| .*? \| Restore a bootable immutable-baseline runtime without changing product state, then repeat the same four bounded interactions and retain DOM\/accessibility evidence\. \|/,
  `| Independent visual-finding resample | Audited baseline 0/4; supplemental historical browser-evidence pin ${historicalBrowserPin.slice(0, 7)} 4/4 | A fresh read-only historical browser-evidence pass reproduced mobile-navigation, overlay-focus and incident-recovery behavior and partially reproduced hero/task-first distance. It cannot fill the immutable-baseline gate because the sampled sources/build drifted after \`081ef…\`; evidence is in \`evidence/browser/current-main-visual-family-resample-2026-08-14.json\`. | Restore a bootable immutable-baseline runtime without changing product state, then repeat the same four bounded interactions and retain DOM/accessibility evidence. |`,
  'unresolved historical visual pin',
);

const historicalAgentCount = Number(readJson(path.join(source, 'agent-reconciliation-register.json')).assignment_count);
const historicalAgentDenominator = Number(completion.gates.agent_assignments_reconciled_and_none_running.denominator);
if (historicalAgentCount !== 105 || historicalAgentDenominator !== 111) {
  throw new Error('Historical agent reconciliation is no longer 105/111');
}
const activeTasks = Number(orchestration.summary.total_background_tasks_active);
completion.gates.agent_assignments_reconciled_and_none_running = {
  completed: historicalAgentCount,
  denominator: historicalAgentDenominator,
  percent: Number((100 * historicalAgentCount / historicalAgentDenominator).toFixed(2)),
  status: 'blocked-historical-reconciliation-live-tasks-active',
  historical_snapshot: true,
  detail: `Historical reconciliation snapshot only: ${historicalAgentCount}/${historicalAgentDenominator} assignment records were reconciled with explicit role/ID, scope, pass, returned-evidence count and unresolved gaps. Live orchestration currently records ${activeTasks} active audit/remediation tasks; the historical 105/111 ratio is not a live completion denominator and final freeze remains blocked.`,
};
completion.genuine_external_runtime_blockers = completion.genuine_external_runtime_blockers.map((blocker) =>
  blocker.replace(
    'the supported Clinical/Medication Lead creation path is fixed on current main but not yet resampled',
    `the supported Clinical/Medication Lead creation path was sampled only on historical browser-evidence pin ${historicalBrowserPin.slice(0, 7)} and cannot establish current origin/main state`,
  ),
);
writeJson(completionPath, completion);

if (finalizeValidation) {
  const dashboardPath = path.join(audit, 'audit-dashboard.html');
  const checkpointPath = path.join(source, 'coordinator-live-checkpoint-2026-08-21.json');
  const checkpointMarkdownPath = path.join(source, 'coordinator-live-checkpoint-2026-08-21.md');
  const validationPath = path.join(source, 'validation-report.json');
  const dashboardBeforeMetricRefresh = readText(dashboardPath);
  const issueMetricPattern = /(<span class="kicker">Issues found<\/span>\s*<strong>)\d+(<\/strong>)/;
  const issueMetricMatches = dashboardBeforeMetricRefresh.match(issueMetricPattern);
  if (!issueMetricMatches || issueMetricMatches.length !== 3) {
    throw new Error('Dashboard Issues found metric is absent or ambiguous');
  }
  const dashboard = dashboardBeforeMetricRefresh.replace(issueMetricPattern, `$1${counts.findings}$2`);
  fs.writeFileSync(dashboardPath, dashboard, 'utf8');
  const dashboardMatch = dashboard.match(/<script id="dashboardData" type="application\/json">([\s\S]*?)<\/script>/);
  if (!dashboardMatch) throw new Error('Dashboard data block is absent');
  const dashboardData = JSON.parse(dashboardMatch[1]);
  if (dashboardData.summary.completionBlockedGates !== completion.completion_blockers.length) {
    throw new Error('Dashboard completion blocker count does not match completion report');
  }
  if (!dashboard.includes(`${completion.completion_blockers.length} mandatory completion gates remain blocked`)) {
    throw new Error('Dashboard visible completion blocker count does not match completion report');
  }
  if (!fs.existsSync(checkpointPath) || !fs.existsSync(checkpointMarkdownPath)) {
    throw new Error('Coordinator checkpoint JSON or Markdown is absent');
  }

  const validation = readJson(validationPath);
  validation.checks = {
    ...validation.checks,
    current_human_facing_summary_counts_derived: true,
    completion_gate_count_matches_dashboard: true,
    historical_agent_reconciliation_not_live_completion_metric: true,
  };
  validation.current_human_facing_summary = {
    status: 'BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE',
    findings: counts.findings,
    p0: counts.p0,
    p1: counts.p1,
    p2: counts.p2,
    p0_p1: counts.p0p1,
    literal_exact_current_id_links: counts.exactLinks,
    completion_blockers: completion.completion_blockers.length,
    historical_browser_evidence_pin: historicalBrowserPin,
  };
  validation.current_artifact_hashes = {
    ...validation.current_artifact_hashes,
    '00_executive_summary_sha256': sha256(executivePath),
    '07_module_findings_sha256': sha256(moduleFindingsPath),
    '13_unresolved_questions_sha256': sha256(unresolvedPath),
    completion_gate_report_sha256: sha256(completionPath),
    orchestration_status_sha256: sha256(orchestrationPath),
    coordinator_live_checkpoint_sha256: sha256(checkpointPath),
    coordinator_live_checkpoint_markdown_sha256: sha256(checkpointMarkdownPath),
    audit_dashboard_sha256: sha256(dashboardPath),
  };
  writeJson(validationPath, validation);
}

console.log(JSON.stringify({
  mode: finalizeValidation ? 'refresh-and-finalize-validation' : 'refresh-current-completion-summaries',
  counts,
  completion_blockers: completion.completion_blockers.length,
  active_tasks: activeTasks,
  status: completion.status,
}, null, 2));
