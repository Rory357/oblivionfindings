/**
 * Records pane — a unified, read-only view of the stay-scoped records (daily
 * notes, handovers, comms logs, evidence packs, risk-plan activations) with a
 * type filter and a detail pop-up. Records are authored from within a stay, so
 * this tab is for browsing across stays.
 */
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { AlertTriangle, ClipboardList, FileText, FolderClosed, MessageSquare, NotebookText } from 'lucide-react';
import { useState, type ComponentType, type ReactNode } from 'react';
import { fmtDate, Pill, type Tone } from '../shared';
import { Empty, FilterChip, PaneHead, SearchBox } from '../pane-kit';
import type { RespiteRecordRow, RespiteRecordType } from '../types';

const TYPE_ICON: Record<RespiteRecordType, ComponentType<{ className?: string }>> = {
    daily_note: FileText,
    handover: ClipboardList,
    comms: MessageSquare,
    evidence: FolderClosed,
    risk_plan: AlertTriangle,
};

const STATUS_TONE: Record<string, Tone> = {
    incident: 'critical',
    pending: 'warning',
    acknowledged: 'success',
    drafting: 'warning',
    sealed: 'success',
    active: 'success',
    pending_review: 'warning',
    suspended: 'warning',
    completed: 'neutral',
    modified: 'info',
};

const FILTERS: [string, string][] = [
    ['all', 'All'],
    ['daily_note', 'Daily notes'],
    ['handover', 'Handovers'],
    ['comms', 'Comms'],
    ['evidence', 'Evidence'],
    ['risk_plan', 'Risk plans'],
];

export function RecordsPane({ records }: { records: RespiteRecordRow[] }) {
    const [q, setQ] = useState('');
    const [type, setType] = useState('all');
    const [detail, setDetail] = useState<RespiteRecordRow | null>(null);

    const rows = records.filter(
        (r) =>
            (type === 'all' || r.type === type) &&
            (q === '' || `${r.title} ${r.client ?? ''} ${r.subtitle ?? ''}`.toLowerCase().includes(q.toLowerCase())),
    );

    return (
        <div>
            <PaneHead icon={NotebookText} title="Records" count={`${rows.length} of ${records.length}`} />
            <div className="mb-4 flex flex-wrap items-center gap-2">
                <SearchBox value={q} onChange={setQ} placeholder="Search records, client or content…" />
                {FILTERS.map(([k, label]) => (
                    <FilterChip key={k} active={type === k} onClick={() => setType(k)}>
                        {label}
                    </FilterChip>
                ))}
            </div>
            <div className="grid gap-2.5">
                {rows.map((r) => (
                    <RecordCard key={`${r.type}-${r.id}`} r={r} onView={() => setDetail(r)} />
                ))}
                {rows.length === 0 ? <Empty icon={NotebookText} title="No records here" sub="Records are authored from within a stay." /> : null}
            </div>
            <RecordDetail record={detail} onClose={() => setDetail(null)} />
        </div>
    );
}

function RecordCard({ r, onView }: { r: RespiteRecordRow; onView: () => void }) {
    const Icon = TYPE_ICON[r.type] ?? FileText;
    return (
        <button
            type="button"
            onClick={onView}
            className="flex w-full items-center gap-3 rounded-[14px] border border-border bg-card p-3.5 text-left transition-shadow hover:shadow-sm"
        >
            <span className="grid h-9 w-9 shrink-0 place-items-center rounded-[9px] bg-primary/10 text-primary">
                <Icon className="h-4 w-4" />
            </span>
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-[14px] font-bold">{r.title}</span>
                    <Pill tone="neutral">{r.typeLabel}</Pill>
                    {r.status ? <Pill tone={STATUS_TONE[r.status] ?? 'neutral'}>{r.status.replace(/_/g, ' ')}</Pill> : null}
                </div>
                {r.subtitle ? <p className="mt-1 truncate text-[12.5px] text-muted-foreground">{r.subtitle}</p> : null}
                <div className="mt-1 flex flex-wrap gap-x-4 text-xs text-muted-foreground">
                    {r.client ? <span>{r.client}</span> : null}
                    <span>{fmtDate(r.date)}</span>
                </div>
            </div>
        </button>
    );
}

function RecordDetail({ record, onClose }: { record: RespiteRecordRow | null; onClose: () => void }) {
    const rows: [string, ReactNode][] = record
        ? [
              ['Date', fmtDate(record.date)],
              ['Status', record.status ? record.status.replace(/_/g, ' ') : '—'],
              ['Detail', record.subtitle ?? '—'],
          ]
        : [];
    return (
        <Dialog open={record != null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-md">
                {record ? (
                    <>
                        <DialogTitle className="text-left text-lg">{record.title}</DialogTitle>
                        <DialogDescription className="text-left">
                            {record.typeLabel}
                            {record.client ? ` · ${record.client}` : ''}
                        </DialogDescription>
                        <dl className="rounded-xl border border-border px-3.5">
                            {rows.map(([k, v], i) => (
                                <div
                                    key={i}
                                    className={`flex justify-between gap-4 py-2 text-[13px] ${i < rows.length - 1 ? 'border-b border-border/60' : ''}`}
                                >
                                    <dt className="text-muted-foreground">{k}</dt>
                                    <dd className="max-w-[60%] text-right font-medium capitalize">{v}</dd>
                                </div>
                            ))}
                        </dl>
                    </>
                ) : null}
            </DialogContent>
        </Dialog>
    );
}
