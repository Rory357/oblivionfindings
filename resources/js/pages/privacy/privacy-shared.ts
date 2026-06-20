/**
 * Privacy command-centre — shared display maps, NZ/IPP enums and formatters.
 *
 * Single source of truth so the dashboard, worklist rows, detail dialogs and
 * wizards can never drift. NZ-only framing (Privacy Act 2020, OPC, IPP 6/7,
 * 20 working days). Enum VALUES match the backend exactly; only labels are the
 * NZ/IPP re-skin of the old GDPR-flavoured strings.
 */
import type { IconType } from '@/components/wizard/primitives';
import {
    Activity,
    Ban,
    Database,
    FileText,
    Fingerprint,
    Gauge,
    RefreshCw,
    Scale,
    ShieldCheck,
} from 'lucide-react';

export type PrivacyTone = 'success' | 'warning' | 'critical' | 'info' | 'neutral';

/** Pill background + text (status/type chips). Covers `info` (TONE_BG does not). */
export const PRIVACY_PILL: Record<PrivacyTone, string> = {
    success: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
    info: 'bg-status-info-bg text-status-info',
    neutral: 'bg-muted text-muted-foreground',
};

/** Solid dot fill (row leading dot). */
export const PRIVACY_DOT: Record<PrivacyTone, string> = {
    success: 'bg-status-success',
    warning: 'bg-status-warning',
    critical: 'bg-status-critical',
    info: 'bg-status-info',
    neutral: 'bg-muted-foreground',
};

type ToneLabel = { tone: PrivacyTone; label: string };

/* ------------------------------------------------------------------ */
/*  Status / type → tone maps (canonical)                             */
/* ------------------------------------------------------------------ */

export const REQUEST_STATUS: Record<string, ToneLabel> = {
    received: { tone: 'warning', label: 'Received' },
    under_review: { tone: 'warning', label: 'Under review' },
    identity_verification: { tone: 'info', label: 'Identity check' },
    in_progress: { tone: 'info', label: 'In progress' },
    completed: { tone: 'success', label: 'Completed' },
    rejected: { tone: 'critical', label: 'Refused' },
    withdrawn: { tone: 'neutral', label: 'Withdrawn' },
};

/** Request type — NZ/IPP labels; values frozen (access…automated_decision). */
export const REQUEST_TYPE: Record<string, ToneLabel> = {
    access: { tone: 'info', label: 'Access · IPP 6' },
    rectification: { tone: 'info', label: 'Correction · IPP 7' },
    erasure: { tone: 'warning', label: 'Deletion' },
    restriction: { tone: 'warning', label: 'Restriction' },
    portability: { tone: 'info', label: 'Portability' },
    objection: { tone: 'warning', label: 'Objection' },
    automated_decision: { tone: 'critical', label: 'Automated decision' },
};

export const BREACH_STATUS: Record<string, ToneLabel> = {
    discovered: { tone: 'critical', label: 'Discovered' },
    under_investigation: { tone: 'warning', label: 'Investigating' },
    contained: { tone: 'info', label: 'Contained' },
    notified: { tone: 'info', label: 'OPC notified' },
    resolved: { tone: 'success', label: 'Resolved' },
};

export const RISK_LEVEL: Record<string, ToneLabel> = {
    low: { tone: 'success', label: 'Low' },
    medium: { tone: 'warning', label: 'Medium' },
    high: { tone: 'warning', label: 'High' },
    very_high: { tone: 'critical', label: 'Very high' },
};

export const DPIA_OUTCOME: Record<string, ToneLabel> = {
    approved: { tone: 'success', label: 'Approved' },
    approved_with_conditions: { tone: 'info', label: 'Approved with conditions' },
    requires_dpo_review: { tone: 'warning', label: 'Requires Privacy Officer review' },
    rejected: { tone: 'critical', label: 'Rejected' },
};

export const HOLD_STATUS: Record<string, ToneLabel> = {
    active: { tone: 'warning', label: 'Active' },
    released: { tone: 'neutral', label: 'Released' },
};

/* ------------------------------------------------------------------ */
/*  Lookups with safe fallbacks                                       */
/* ------------------------------------------------------------------ */

export const titleCase = (s: string): string =>
    s.replace(/[_-]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

const lookup = (map: Record<string, ToneLabel>, key: string | null | undefined): ToneLabel =>
    key && map[key] ? map[key] : { tone: 'neutral', label: key ? titleCase(key) : '—' };

export const requestStatus = (s: string | null | undefined) => lookup(REQUEST_STATUS, s);
export const requestType = (t: string | null | undefined) => lookup(REQUEST_TYPE, t);
export const breachStatus = (s: string | null | undefined) => lookup(BREACH_STATUS, s);
export const riskLevel = (r: string | null | undefined) => lookup(RISK_LEVEL, r);
export const holdStatus = (s: string | null | undefined) => lookup(HOLD_STATUS, s);
export const dpiaOutcome = (o: string | null | undefined): ToneLabel =>
    o ? lookup(DPIA_OUTCOME, o) : { tone: 'info', label: 'In review' };

/* ------------------------------------------------------------------ */
/*  en-NZ formatters                                                  */
/* ------------------------------------------------------------------ */

export const fmtDate = (d?: string | null): string =>
    d ? new Date(d).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';

export const fmtDateTime = (d?: string | null): string =>
    d
        ? new Date(d).toLocaleString('en-NZ', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
              hour: 'numeric',
              minute: '2-digit',
          })
        : '—';

export const fmtNum = (n?: number | null): string =>
    n == null ? '—' : n.toLocaleString('en-NZ');

/* ------------------------------------------------------------------ */
/*  Wizard option sets (FE = BE; lifted from existing pages + re-skin) */
/* ------------------------------------------------------------------ */

export type TileOption = { key: string; label: string; description?: string; icon?: IconType };

/** Request type TilePicker — NZ/IPP, values frozen. */
export const REQUEST_TYPE_TILES: TileOption[] = [
    { key: 'access', label: 'Access · IPP 6', description: 'See the personal information we hold', icon: FileText },
    { key: 'rectification', label: 'Correction · IPP 7', description: 'Correct inaccurate information', icon: RefreshCw },
    { key: 'erasure', label: 'Deletion', description: 'Delete personal information', icon: Ban },
    { key: 'portability', label: 'Portability', description: 'Export / transfer to another provider', icon: Database },
    { key: 'objection', label: 'Objection', description: 'Object to a particular use', icon: ShieldCheck },
    { key: 'restriction', label: 'Restriction', description: 'Limit how information is used', icon: Scale },
];

/** Identity verification methods (IPP 6 — confirm before release). */
export const VERIFICATION_METHODS: string[] = [
    'Not yet verified',
    'RealMe verified',
    'Drivers licence',
    'Passport',
    'In person',
    'Known to organisation',
];

/** Affected data categories for a breach (NZ — includes NHI). */
export const BREACH_DATA_CATEGORIES: string[] = [
    'Contact details',
    'Health information',
    'NHI number',
    'Financial',
    'Identity documents',
    'Support notes',
    'Photographs',
];

export const HOLD_TYPE_TILES: TileOption[] = [
    { key: 'litigation', label: 'Litigation', icon: Scale },
    { key: 'investigation', label: 'Investigation', icon: Fingerprint },
    { key: 'regulatory', label: 'Regulatory', icon: ShieldCheck },
    { key: 'audit', label: 'Audit', icon: FileText },
    { key: 'other', label: 'Other', icon: Activity },
];

export const ASSESSMENT_TYPE_TILES: TileOption[] = [
    { key: 'new_project', label: 'New project', icon: Activity },
    { key: 'process_change', label: 'Process change', icon: RefreshCw },
    { key: 'system_upgrade', label: 'System upgrade', icon: Database },
    { key: 'periodic_review', label: 'Periodic review', icon: RefreshCw },
];

export const RISK_TILES: TileOption[] = [
    { key: 'low', label: 'Low', icon: Gauge },
    { key: 'medium', label: 'Medium', icon: Gauge },
    { key: 'high', label: 'High', icon: Gauge },
    { key: 'very_high', label: 'Very high', icon: Gauge },
];

/** DPIA personal-data category chips (NZ). */
export const DPIA_DATA_TYPES: string[] = [
    'Contact details',
    'Health information',
    'NHI number',
    'Financial',
    'Identity documents',
    'Cultural / ethnicity',
    'Biometric',
    'Location',
];

/** Who the processing affects. */
export const DPIA_SUBJECTS: string[] = [
    'Clients',
    'Family / whānau',
    'Staff',
    'Contractors',
    'Visitors',
    'Job applicants',
];
