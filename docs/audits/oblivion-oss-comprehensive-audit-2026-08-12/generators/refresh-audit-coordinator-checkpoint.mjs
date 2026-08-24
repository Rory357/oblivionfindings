import childProcess from 'node:child_process';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const generatorDir = path.dirname(fileURLToPath(import.meta.url));
const audit = path.dirname(generatorDir);
const repository = path.dirname(path.dirname(path.dirname(audit)));
const source = path.join(audit, 'evidence', 'source');
const orchestrationPath = path.join(source, 'orchestration-status-2026-08-14.json');
const checkpointPath = path.join(source, 'coordinator-live-checkpoint-2026-08-21.json');
const markdownPath = path.join(source, 'coordinator-live-checkpoint-2026-08-21.md');
const findingsPath = path.join(audit, 'findings.json');
const manifestPath = path.join(source, 'working-capability-manifest-902.json');
const completionPath = path.join(source, 'completion-gate-report.json');

const readJson = (file) => JSON.parse(fs.readFileSync(file, 'utf8'));
const hash = (file) => crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
const runGit = (args, cwd = repository) => childProcess.execFileSync('git', args, { cwd, encoding: 'utf8' }).trim();

function worktreeMap() {
  const records = runGit(['worktree', 'list', '--porcelain']).split(/\r?\n\r?\n/).filter(Boolean);
  return new Map(records.map((record) => {
    const fields = Object.fromEntries(record.split(/\r?\n/).map((line) => {
      const separator = line.indexOf(' ');
      return separator === -1 ? [line, ''] : [line.slice(0, separator), line.slice(separator + 1)];
    }));
    return [fields.worktree.replace(/\\/g, '/'), {
      path: fields.worktree.replace(/\\/g, '/'),
      head: fields.HEAD,
      branch: fields.branch?.replace(/^refs\/heads\//, '') ?? 'detached',
    }];
  }));
}

function registeredWorktree(worktrees, suffix, context) {
  const entry = [...worktrees.values()].find((candidate) => candidate.path.endsWith(suffix));
  if (!entry) throw new Error(`Expected protected worktree is not registered: ${suffix}`);
  const statusEntries = runGit(['-C', entry.path, 'status', '--porcelain'])
    .split(/\r?\n/)
    .filter(Boolean).length;
  const base = entry.branch === 'detached'
    ? 'not-applicable'
    : runGit(['merge-base', entry.branch, 'refs/remotes/origin/main']);
  return {
    ...context,
    worktree: entry.path,
    branch: entry.branch,
    head: entry.head,
    merge_base_with_cached_origin_main: base,
    git_status_entries: statusEntries,
    retention: 'protected/reserved in coordination; do not prune, reclaim or reuse without explicit release',
  };
}

const orchestration = readJson(orchestrationPath);
const findings = readJson(findingsPath).findings;
const manifest = readJson(manifestPath).targets;
const completion = readJson(completionPath);
const worktrees = worktreeMap();
const auditLanes = orchestration.audit_research_tasks.filter((task) => task.status === 'active_pinned_read_only_module_audit');
const sourcePivots = orchestration.audit_research_tasks.filter((task) => task.status === 'queued_pinned_source_remediation_pivot');
const p0p1 = findings.filter((finding) => finding.priority === 'P0' || finding.priority === 'P1').length;
const exactLinks = findings.flatMap((finding) => finding.feature_ids ?? [])
  .filter((featureId) => new Set(manifest.map((target) => target.working_key)).has(featureId)).length;

if (findings.length !== 92 || p0p1 !== 80 || exactLinks !== 159) throw new Error('Canonical finding/link counts drifted');
if (completion.status !== 'BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE' || completion.completion_blockers.length !== 19) throw new Error('Completion state drifted');
if (auditLanes.length !== orchestration.summary.audit_research_tasks_active) throw new Error('Pinned audit/research lane count does not match the orchestration summary');
if (sourcePivots.length > orchestration.summary.remediation_tasks_active) throw new Error('Queued source pivots exceed the active remediation count');
if (orchestration.summary.total_background_tasks_active !== orchestration.summary.audit_research_tasks_active + orchestration.summary.remediation_tasks_active) throw new Error('Orchestration total does not equal audit/research plus remediation tasks');

const protectedWorktrees = [
  registeredWorktree(worktrees, '/797b/oblivionfindings', {
    finding_id: 'FLEET-MED-WITNESS-01',
    source_state: 'reviewed 15-path source is merged on current main at 109750de7d03eb9dc258640b991a3b9d84f6c535',
    runtime_state: 'automated gates and bounded browser workflow are green; baseline-wide visual/product completion remains unproved',
    publication_state: 'merged and pushed to main at 109750de7; no immutable baseline completion credit',
    next_action: 'retain fixed-pending-verification until the required release/baseline evidence is accepted',
  }),
  registeredWorktree(worktrees, '/7365/oblivionfindings', {
    finding_id: 'FIN-PAYMENT-MATCH-01',
    source_state: 'bounded source-only formatting correction state retained',
    runtime_state: 'released at the first behavior gate on MySQL 1215; bounded migration review active',
    publication_state: 'not published from this checkpoint',
    next_action: 'take the next appropriate serialized finance verification grant after lane release',
  }),
  registeredWorktree(worktrees, '/f310/oblivionfindings', {
    finding_id: 'GOV-RESOLUTION-QUORUM-01',
    source_state: 'protected queued source state',
    runtime_state: 'reserved; no runtime grant active',
    publication_state: 'not published from this checkpoint',
    next_action: 'preserve worktree until explicit task-specific source/remediation dispatch',
  }),
  registeredWorktree(worktrees, '/7e90/oblivionfindings', {
    finding_id: 'MED-ORDER-ERASURE-01',
    source_state: 'protected queued source state',
    runtime_state: 'reserved pending dependency order; no runtime grant active',
    publication_state: 'not published from this checkpoint',
    next_action: 'preserve worktree until dependency acceptance and an explicit proportional continuation grant',
  }),
];

const checkpoint = {
  schema_version: '1.0.0',
  generated_at: orchestration.generated_at,
  status: 'BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE',
  purpose: 'Durable audit-only coordinator handoff. It records live orchestration and protected worktree context; it grants no runtime authority and creates no completion credit.',
  inputs: {
    orchestration_status: {
      path: 'evidence/source/orchestration-status-2026-08-14.json',
      sha256: hash(orchestrationPath),
    },
    completion_gate_report: {
      path: 'evidence/source/completion-gate-report.json',
      sha256: hash(completionPath),
      blockers: completion.completion_blockers.length,
    },
    cached_origin_main: runGit(['rev-parse', 'refs/remotes/origin/main']),
    worktree_observation: 'git worktree list --porcelain and scoped git status/merge-base reads only',
  },
  canonical_current_counts: {
    findings: findings.length,
    p0_p1: p0p1,
    literal_exact_current_id_links: exactLinks,
    benchmark_decided: 451,
    benchmark_unproved: 451,
    visual_assigned: 8153,
    visual_unresolved: 600,
  },
  sole_lane: {
    holder: orchestration.summary.slot_holder,
    kind: 'released; no active heavy process',
    dependency: 'fresh task-specific grant only after bounded migration correction review',
    queued_after_holder: ['FIN-PAYMENT-MATCH-01', 'FLEET-MED-WITNESS-01'],
    boundary: 'No task infers a runtime, browser, PHP, Composer, database or publication grant from this checkpoint.',
  },
  active_lane_balance: {
    total_background_tasks_active: orchestration.summary.total_background_tasks_active,
    remediation_tasks_active: orchestration.summary.remediation_tasks_active,
    total_pinned_tasks: auditLanes.length + sourcePivots.length,
    read_only_audit_research_lanes: auditLanes.map((task) => ({
      ref: task.orchestration_ref,
      title: task.title,
      status: task.status,
    })),
    queued_source_remediation_pivots: sourcePivots.map((task) => ({
      ref: task.orchestration_ref,
      title: task.title,
      status: task.status,
      next_action: 'await protected worktree allocation or explicit task-specific remediation dispatch',
    })),
  },
  protected_worktrees: protectedWorktrees,
  constraint: 'This checkpoint is coordination metadata only. It does not alter findings, benchmark credit, visual ownership, runtime evidence, historical evidence bytes or the blocked audit status.',
};

fs.writeFileSync(checkpointPath, `${JSON.stringify(checkpoint, null, 2)}\n`, 'utf8');
const table = protectedWorktrees.map((item) => `| ${item.finding_id} | \`${item.worktree}\` | \`${item.branch}\` | \`${item.head}\` | \`${item.merge_base_with_cached_origin_main}\` | ${item.source_state}; ${item.runtime_state} | ${item.next_action} |`).join('\n');
const markdown = `# Durable coordinator checkpoint\n\nStatus: **BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE**. This is audit-only coordination metadata, not a runtime grant or completion claim.\n\n- Live orchestration: **${orchestration.summary.total_background_tasks_active}** background tasks = **${orchestration.summary.audit_research_tasks_active}** read-only audit/research + **${orchestration.summary.remediation_tasks_active}** remediation/pivot lanes.\n- Pinned task register: **${auditLanes.length}** read-only audit/research lanes and **${sourcePivots.length}** queued source-remediation pivots (${auditLanes.length + sourcePivots.length} listed tasks), plus separately protected worktrees where applicable.\n- Sole heavy/frontend holder: **${checkpoint.sole_lane.holder}** (${checkpoint.sole_lane.dependency}).\n- Queued after the holder: ${checkpoint.sole_lane.queued_after_holder.map((item) => `\`${item}\``).join(', ')}.\n- Canonical counts remain 92 findings, 80 P0/P1, 159 literal exact current-ID links, ${checkpoint.canonical_current_counts.benchmark_decided}/${checkpoint.canonical_current_counts.benchmark_unproved} benchmark and ${checkpoint.canonical_current_counts.visual_assigned.toLocaleString('en-US')}/${checkpoint.canonical_current_counts.visual_unresolved} visual.\n- Completion report has ${completion.completion_blockers.length} blockers; historical reconciliation is not a live completion metric.\n\n## Protected worktrees\n\n| Finding | Worktree | Branch | HEAD | Cached-origin merge base | Source/runtime state | Next action |\n|---|---|---|---|---|---|---|\n${table}\n\n## Pinned source-remediation pivots\n\n${sourcePivots.map((task) => `- **${task.title}** (\`${task.orchestration_ref}\`): await protected worktree allocation or explicit task-specific remediation dispatch.`).join('\n')}\n\n## Boundary\n\n${checkpoint.constraint}\n`;
fs.writeFileSync(markdownPath, markdown, 'utf8');
console.log(JSON.stringify({
  checkpoint: checkpointPath,
  markdown: markdownPath,
  protected_worktrees: protectedWorktrees.length,
  audit_lanes: auditLanes.length,
  source_pivots: sourcePivots.length,
  sha256: hash(checkpointPath),
}, null, 2));
