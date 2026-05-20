/**
 * Governance-specific status colour helpers.
 *
 * Wraps the global `statusColors` map in `lib/status-colors.ts` with
 * module-specific overrides for governance workflows (meeting lifecycle,
 * resolution voting, budget proposal, etc.) — each entry resolves to one
 * of the four global semantic tokens (success / warning / critical / info
 * / neutral) so re-tinting via the Branding page still works.
 *
 * Use this when the global `statusColors` doesn't already cover a status,
 * which is true for most governance entities (meetings, resolutions,
 * policies, budgets, attestations).
 */
import { statusColors } from './status-colors';

// Token references — keep in sync with status-colors.ts.
const SUCCESS = 'bg-status-success-bg text-status-success border-status-success/30';
const WARNING = 'bg-status-warning-bg text-status-warning border-status-warning/30';
const CRITICAL = 'bg-status-critical-bg text-status-critical border-status-critical/30';
const INFO = 'bg-status-info-bg text-status-info border-status-info/30';
const NEUTRAL = 'bg-muted text-muted-foreground border-border';

const governanceOverrides: Record<string, string> = {
  // Meetings
  scheduled: INFO,
  agenda_draft: WARNING,
  agenda_final: SUCCESS,
  minutes_draft: WARNING,
  minutes_approved: SUCCESS,
  signed: SUCCESS,
  locked: NEUTRAL,

  // Resolutions
  proposed: WARNING,
  open: INFO,
  carried: SUCCESS,
  defeated: CRITICAL,
  withdrawn: NEUTRAL,

  // Budget
  drafting: NEUTRAL,
  submitted: WARNING,
  presented: SUCCESS,

  // Policies
  approved: SUCCESS,
  superseded: NEUTRAL,

  // Compliance obligation lifecycle
  not_due: NEUTRAL,
  due_soon: WARNING,
  complete: SUCCESS,

  // Spend approval / generic
  expired: NEUTRAL,

  // Action items
  blocked: CRITICAL,

  // Performance review lifecycle
  self_review: INFO,
  peer_review: 'bg-primary/10 text-primary border-primary/30',
  board_review: WARNING,

  // Strategy / goal progress
  achieved: SUCCESS,
  at_risk: WARNING,
  on_hold: WARNING,
  cancelled: CRITICAL,

  // Te Tiriti implementation progress
  not_started: NEUTRAL,
  implemented: INFO,
  embedded: SUCCESS,
};

/**
 * Get the badge classes for a governance entity status. Falls back to
 * the global `statusColors` map if not overridden here, and finally to
 * neutral if neither covers the status.
 */
export function governanceStatusColor(status: string | null | undefined): string {
  if (!status) return NEUTRAL;
  return governanceOverrides[status] ?? statusColors[status] ?? NEUTRAL;
}

/**
 * Tone-only variant (background + text, no border) for inline pills where
 * a border would be too heavy.
 */
export function governanceStatusTone(status: string | null | undefined): string {
  return governanceStatusColor(status)
    .replace(/ border-[^ ]+/g, '')
    .replace(/border-border/g, '')
    .trim();
}

/**
 * Map a numeric risk score (likelihood × impact, range 1–25) to a
 * background-only colour class for swatches, dots, and circular badges.
 *
 * Thresholds align with the NZ-style 5×5 heatmap:
 *  - 1–9   Low      → success
 *  - 10–14 Medium   → warning
 *  - 15–19 High     → warning (darker by token weight)
 *  - 20+   Critical → critical
 */
export function riskScoreColor(score: number | null | undefined): string {
  const n = Number(score ?? 0);
  if (n >= 20) return 'bg-status-critical';
  if (n >= 10) return 'bg-status-warning';
  return 'bg-status-success';
}

/**
 * Human-readable severity band for a risk score.
 */
export function riskScoreLevel(score: number | null | undefined): 'Critical' | 'High' | 'Medium' | 'Low' {
  const n = Number(score ?? 0);
  if (n >= 20) return 'Critical';
  if (n >= 15) return 'High';
  if (n >= 10) return 'Medium';
  return 'Low';
}
