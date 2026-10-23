/**
 * The injury register table (approved H&S drawings pg-hsn-86, 86b — Stephan 23
 * Oct 2026): each row names the injury first with its reference under it and
 * shows where it is up to as the five stage dots (Reported › Under treatment ›
 * Return to work › Recovered › Closed) — the same stages as the injury page.
 * The kebab and right-click open the same permitted action list.
 */
import { dateWithYear } from '@/components/incidents/incident-stages';
import { RecordIdentity, RecordPerson, RecordTable } from '@/components/incidents/record-table';
import type { MenuItem } from '@/components/lists/entity-menu';
import { Ring, StageDots } from '@/components/signature/blocks';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatTime } from '@/lib/datetime';
import { injuryHref } from '@/lib/health-safety/injury-navigation';
import { Link, router } from '@inertiajs/react';
import { FileText, HeartPulse, History, Link2, Paperclip, Stethoscope } from 'lucide-react';
import type { ReactNode } from 'react';
import { SEVERITY_TONE, severityLabel, STATUS_ORDER, statusLabel } from './injury-constants';
import type { InjuryRow } from './injury-types';

export type InjuryRegisterRow = Omit<InjuryRow, 'rtw_count' | 'capacity_count' | 'attachment_count'> & {
    rtw_count: number | null; capacity_count: number | null; attachment_count: number | null;
    count_integrity?: Record<string, string>;
    source_integrity?: 'valid' | 'unknown' | 'repair_required';
};

/** Where an injury is up to on its five stages (1-based) — shared with the injury page. */
export function injuryStage(status: string): { stage: number; label: string; tone?: 'success' } {
    const index = STATUS_ORDER.indexOf(status as (typeof STATUS_ORDER)[number]);
    return { stage: index < 0 ? 1 : index + 1, label: statusLabel(status), tone: status === 'closed' ? 'success' : undefined };
}

/** One canonical case identity and one permitted action menu for every row. */
export function InjuryRegisterList({ rows, total, returnTo, medical, sub, footer }: {
    rows: InjuryRegisterRow[]; total: number; returnTo: string; medical: boolean;
    /** The table's sub-line (the WorkSafe caption). */
    sub?: ReactNode;
    /** The pages under the table. */
    footer?: ReactNode;
}) {
    const open = (row: InjuryRegisterRow, section: 'overview' | 'recovery' | 'capacity' | 'connections' | 'evidence' | 'history' = 'overview') => router.get(injuryHref(row.id, { section, returnTo }));
    const actions = (row: InjuryRegisterRow): MenuItem[] => [
        { label: 'Open injury', icon: FileText, onClick: () => open(row) },
        { label: 'Connected work', icon: Link2, onClick: () => open(row, 'connections') },
        ...(medical ? [
            { label: 'Recovery plans', icon: HeartPulse, onClick: () => open(row, 'recovery') },
            { label: 'Assessments', icon: Stethoscope, onClick: () => open(row, 'capacity') },
            { label: 'Evidence', icon: Paperclip, onClick: () => open(row, 'evidence') },
        ] : []),
        { label: 'History', icon: History, onClick: () => open(row, 'history') },
    ];
    const count = (value: number | null) => value === null ? 'Not confirmed' : String(value);
    return <RecordTable
        title="Injuries"
        sub={sub}
        ariaLabel="Workplace injuries"
        rows={rows}
        rowKey={(row) => row.id}
        onOpen={(row) => open(row)}
        actionsFor={actions}
        menuTitle={(row) => `${row.reference} · ${row.injury_type_label}`}
        menuIcon={HeartPulse}
        columns={[
            { key: 'injury', label: 'Injury', className: 'min-w-[12rem]', cell: (row) => <RecordIdentity
                title={<Link href={injuryHref(row.id, { returnTo })} onClick={(event) => event.stopPropagation()} aria-label={`Open injury ${row.reference}`}
                    className="rounded-sm text-foreground underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">{row.injury_type_label}</Link>}
                sub={<>{row.reference}{row.source_integrity === 'repair_required' ? <span className="mt-1 flex"><StatusBadge variant="warning">Source needs review</StatusBadge></span> : null}</>} /> },
            { key: 'worker', label: 'Worker', nowrap: true, cell: (row) => row.worker?.name ? <RecordPerson name={row.worker.name} /> : <span className="text-muted-foreground">Worker unavailable</span> },
            { key: 'site', label: 'Site', nowrap: true, cell: (row) => row.site?.name ?? <span className="text-muted-foreground">Site unavailable</span> },
            { key: 'date', label: 'Date', nowrap: true, cell: (row) => row.injury_date ? <RecordIdentity title={dateWithYear(row.injury_date)} sub={formatTime(row.injury_date)} /> : <span className="text-muted-foreground">Not recorded</span> },
            { key: 'severity', label: 'Severity', nowrap: true, cell: (row) => <div className="grid justify-items-start gap-1">
                <StatusBadge variant={SEVERITY_TONE[row.severity] ?? 'neutral'}>{severityLabel(row.severity)}</StatusBadge>
                {row.worksafe_notifiable ? <span className="text-subtle">WorkSafe flag</span> : null}
            </div> },
            { key: 'stage', label: 'Where it’s up to', nowrap: true, cell: (row) => { const stage = injuryStage(row.status); return <StageDots stage={stage.stage} of={STATUS_ORDER.length} label={stage.label} tone={stage.tone} />; } },
            { key: 'lost', label: 'Days lost', nowrap: true, cell: (row) => <span className="tabular-nums">{row.lost_time_days}</span> },
            ...(medical ? [{ key: 'linked', label: 'Linked records', nowrap: true, cell: (row: InjuryRegisterRow) => <span className="text-subtle">{count(row.rtw_count)} plans · {count(row.capacity_count)} assessments · {count(row.attachment_count)} files</span> }] : []),
        ]}
        empty={<p className="sg-quiet"><Ring tone="none" /><span>No injuries match these filters. Change the filters to find another record.</span></p>}
        footer={rows.length ? footer ?? <p className="text-subtle">{`${rows.length} of ${total} shown`}</p> : undefined}
    />;
}
