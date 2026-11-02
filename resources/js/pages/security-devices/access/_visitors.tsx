/** Access › Visitors (drawing pg-sdr-19c): visitor passes, today first, then coming up and the last 7 days. */
import { EntityTable, type MenuItem } from '@/components/lists';
import { KnowList, Note, QuietLine } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { formatDate, formatDateTime, formatTime, WORKER_TIMEZONE } from '@/lib/datetime';
import { Info, Plus, Ticket } from 'lucide-react';
import type { MouseEvent } from 'react';
import { BlockHead } from '../devices/_parts';
import { StatusCell } from './_parts';
import type { VisitorRow } from './_types';

const nzDay = (iso: string) => new Date(iso).toLocaleDateString('en-NZ', { timeZone: WORKER_TIMEZONE });

/** "Today, 9:00 am – 12:00 pm", "Tue 3 Nov, 10:00 am – 12:00 pm", or both dates when it runs over days. */
export function passWhen(row: Pick<VisitorRow, 'starts_at' | 'ends_at'>, now: Date): string {
    if (!row.starts_at || !row.ends_at) return 'Not available';
    if (nzDay(row.starts_at) === nzDay(row.ends_at)) {
        const day = nzDay(row.starts_at) === nzDay(now.toISOString()) ? 'Today' : formatDate(row.starts_at);
        return `${day}, ${formatTime(row.starts_at)} – ${formatTime(row.ends_at)}`;
    }
    return `${formatDateTime(row.starts_at)} – ${formatDateTime(row.ends_at)}`;
}

export function VisitorsView({
    rows,
    total,
    canManage,
    filtered,
    actionsFor,
    onContextMenu,
    onAdd,
    now,
}: {
    rows: VisitorRow[];
    total: number;
    canManage: boolean;
    filtered: boolean;
    actionsFor: (row: VisitorRow) => MenuItem[];
    onContextMenu: (event: MouseEvent, row: VisitorRow) => void;
    onAdd: () => void;
    now: Date;
}) {
    return (
        <div className="flex flex-col gap-5">
            <Note icon={Info}>
                <b className="font-semibold">Visitor passes are for contractors and visits Oblivion arranges.</b> Residents’ own guests are their business: they come and go as they would at any home, without a pass.
            </Note>
            <section className="flex flex-col gap-3">
                <BlockHead
                    title="Visitor passes"
                    sub="Today first, then coming up and the last 7 days"
                    right={
                        canManage ? (
                            <Button type="button" variant="outline" size="sm" onClick={onAdd}>
                                <Plus aria-hidden="true" />
                                Add visitor pass
                            </Button>
                        ) : undefined
                    }
                />
                {rows.length === 0 ? (
                    <QuietLine text={filtered && total > 0 ? 'No passes match. Try another search, Site or time.' : 'No visitor passes in the last 7 days or coming up.'} />
                ) : (
                    <EntityTable<VisitorRow>
                        rows={rows}
                        rowKey={(row) => row.id}
                        identityLabel="Visitor"
                        identity={(row) => ({ icon: Ticket, name: row.name, subline: row.site, linkLabel: `Actions for ${row.name}` })}
                        columns={[
                            { key: 'where', label: 'Where', width: '1fr', cell: (row) => row.site },
                            { key: 'when', label: 'When', width: '1.6fr', cell: (row) => passWhen(row, now) },
                            { key: 'status', label: 'Status', width: '1.2fr', cell: (row) => <StatusCell badge={row.badge} now={now} /> },
                        ]}
                        actionsFor={actionsFor}
                        onRowContextMenu={onContextMenu}
                        rowHeight="content"
                        minWidth={760}
                    />
                )}
            </section>
            <KnowList
                title="How visitor passes work"
                items={[
                    { tone: 'success', text: <>A pass works only at the doors and times you choose, then <b className="font-semibold">stops by itself</b></> },
                    { tone: 'info', text: 'Give the pass a PIN or QR code in UniFi Access, then send it to the visitor' },
                    { tone: 'info', text: 'Visitors’ door events keep their name 90 days, like everyone’s' },
                ]}
            />
        </div>
    );
}
