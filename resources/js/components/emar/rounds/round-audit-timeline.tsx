/* eslint-disable no-restricted-syntax -- the audit trail is a custom timeline
   (border-l rail + absolute status dots) reusing the timesheets/view-timesheet
   idiom; all colours are semantic tokens. */
import { cn } from '@/lib/utils';
import { Activity, AlertTriangle, Ban, Check, CheckCircle2, Hand, Play, UserRound, Zap } from 'lucide-react';
import type { ComponentType } from 'react';
import { doseStatusMeta, type RoundCell, type RoundItem, type RoundSummary } from './types';

export interface AuditAdminEntry {
    status: string;
    medication_name: string;
    dose: string | null;
    resident_name: string;
    staff: string | null;
    at: string | null;
    witnessed_by: string | null;
    blood_glucose_level: number | null;
    pulse_bpm: number | null;
    reason: string | null;
}

export interface RoundAuditMeta {
    template_name?: string | null;
    created_at?: string | null;
    assignee?: string | null;
    started_at?: string | null;
    started_by?: string | null;
    completed_at?: string | null;
    completed_by?: string | null;
}

const DOT_BG: Record<string, string> = {
    success: 'bg-status-success',
    warning: 'bg-status-warning',
    critical: 'bg-status-critical',
    muted: 'bg-muted-foreground',
};

const STATUS_ICON: Record<string, ComponentType<{ className?: string }>> = {
    given: Check,
    refused: Ban,
    withheld: Hand,
    held: Hand,
    missed: AlertTriangle,
};

function fmtTime(iso: string | null): string {
    if (!iso) return '';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '' : d.toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' });
}

/** Flatten round cells → actioned audit entries (skips still-due doses). */
export function cellsToAuditEntries(cells: RoundCell[]): AuditAdminEntry[] {
    return cells
        .filter((c) => c.status !== 'due')
        .map((c) => ({
            status: c.status,
            medication_name: c.medication_name,
            dose: c.dose,
            resident_name: c.resident_name,
            staff: c.administered_by,
            at: c.administered_at,
            witnessed_by: c.witnessed_by,
            blood_glucose_level: c.blood_glucose_level,
            pulse_bpm: c.pulse_bpm,
            reason: c.reason,
        }))
        .sort((a, b) => (a.at ?? '').localeCompare(b.at ?? ''));
}

/** Flatten guided items → actioned audit entries (skips still-due doses). */
export function itemsToAuditEntries(items: RoundItem[]): AuditAdminEntry[] {
    return items
        .filter((it) => it.administration)
        .map((it) => ({
            status: it.administration!.status,
            medication_name: it.medication_name,
            dose: it.dose,
            resident_name: it.client_name,
            staff: it.administration!.administered_by,
            at: it.administration!.administered_at,
            witnessed_by: it.administration!.witnessed_by,
            blood_glucose_level: it.administration!.blood_glucose_level,
            pulse_bpm: it.administration!.pulse_bpm,
            reason: it.administration!.reason,
        }))
        .sort((a, b) => (a.at ?? '').localeCompare(b.at ?? ''));
}

export function auditMetaFromRound(round: RoundSummary): RoundAuditMeta {
    return {
        template_name: round.template_name,
        created_at: round.created_at,
        assignee: round.assignee,
        started_at: round.started_at,
        started_by: round.started_by,
        completed_at: round.completed_at,
        completed_by: round.completed_by,
    };
}

function TimelineRow({
    tone,
    icon: Icon,
    title,
    meta,
    note,
}: {
    tone: string;
    icon: ComponentType<{ className?: string }>;
    title: string;
    meta: string;
    note?: string | null;
}) {
    return (
        <li className="relative">
            <span className={cn('absolute -left-[22px] grid h-4 w-4 place-items-center rounded-full text-white shadow', DOT_BG[tone] ?? DOT_BG.muted)}>
                <Icon className="h-2.5 w-2.5" />
            </span>
            <div className="text-[12.5px] font-semibold">{title}</div>
            {meta ? <div className="text-[11px] text-muted-foreground">{meta}</div> : null}
            {note ? <div className="mt-1 rounded-md bg-muted px-2 py-1 text-[11.5px] text-foreground">{note}</div> : null}
        </li>
    );
}

export default function RoundAuditTimeline({ meta, entries }: { meta: RoundAuditMeta; entries: AuditAdminEntry[] }) {
    const hasCompleted = Boolean(meta.completed_at);

    return (
        <ol className="space-y-3 border-l-2 border-border pl-4">
            <TimelineRow
                tone="muted"
                icon={Zap}
                title="Round generated from template"
                meta={`${meta.template_name ?? 'Template'}${meta.created_at ? ` · ${fmtTime(meta.created_at)}` : ''}`}
            />
            {meta.assignee ? <TimelineRow tone="muted" icon={UserRound} title={`Assigned to ${meta.assignee}`} meta="Roster" /> : null}
            {meta.started_at ? (
                <TimelineRow tone="muted" icon={Play} title="Round started" meta={`${meta.started_by ?? 'Staff'} · ${fmtTime(meta.started_at)}`} />
            ) : null}

            {entries.map((e, i) => {
                const dm = doseStatusMeta(e.status);
                const Icon = STATUS_ICON[e.status] ?? Activity;
                const detail = [
                    e.witnessed_by ? `Witness: ${e.witnessed_by}` : null,
                    e.blood_glucose_level != null ? `BG ${e.blood_glucose_level} mmol/L` : null,
                    e.pulse_bpm != null ? `Pulse ${e.pulse_bpm} bpm` : null,
                    e.reason ? `“${e.reason}”` : null,
                ].filter(Boolean) as string[];
                return (
                    <TimelineRow
                        key={`${e.medication_name}-${e.at}-${i}`}
                        tone={dm.tone}
                        icon={Icon}
                        title={`${dm.label} — ${e.medication_name}${e.dose ? ` ${e.dose}` : ''}`}
                        meta={[e.resident_name, e.staff, fmtTime(e.at)].filter(Boolean).join(' · ')}
                        note={detail.length ? detail.join('   ·   ') : null}
                    />
                );
            })}

            {hasCompleted ? (
                <TimelineRow
                    tone="success"
                    icon={CheckCircle2}
                    title="Round completed"
                    meta={`${meta.completed_by ?? 'Staff'} · ${fmtTime(meta.completed_at ?? null)}`}
                />
            ) : null}
        </ol>
    );
}
