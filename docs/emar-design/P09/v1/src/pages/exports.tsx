/* Reports & audit › Print & exports (Main, Q8). The evidence documents (MAR,
 * controlled drug register, round sheet) and the data files, each with what’s
 * in it and its range limit. Every identifiable export asks for a purpose and
 * is recorded in the audit trail. Without permission to export, the action
 * isn’t offered and the view says who can export. */
import { compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { PageHeaderMeterBig, PageHeaderMeterBlock, PageHeaderMeterCaption } from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { Download, FileSpreadsheet, FileText, Info } from 'lucide-react';
import { type MouseEvent } from 'react';
import { has } from '../data';
import { EXPORT_DEFS, WHO_EXPORTS, canExportKind, eventsFor, housesOf, stockOnly, type ExportDef } from '../model';
import { Notice, Wrap } from '../ui';
import { useCtx } from './hub';
import type { Built, Ctx } from './std-reports';

const stop = (fn: () => void) => (e: MouseEvent) => (e.stopPropagation(), fn());
export const whoCan = (d: ExportDef) => (d.key === 'audit' ? `${WHO_EXPORTS} and auditors` : d.key === 'stock' ? `${WHO_EXPORTS} and finance` : d.key === 'cdreg' ? `${WHO_EXPORTS} with controlled-medicine access` : WHO_EXPORTS);

export function exportsView(c: Ctx, ctxMenu: ReturnType<typeof useCtx>): Built {
    const list = EXPORT_DEFS.filter((d) => (stockOnly(c.p) ? d.key === 'stock' : true));
    const mine = list.filter((d) => canExportKind(c.p, d.key));
    const made = eventsFor(c.rt, c.period, c.pids, housesOf(c.p), 'export', null);
    const menu = (d: ExportDef): MenuItem[] => compactMenu([canExportKind(c.p, d.key) && { label: `Make the ${d.name === 'MAR' ? 'MAR' : d.name.charAt(0).toLowerCase() + d.name.slice(1)} ${d.format}`, icon: Download, onClick: () => c.open(`export:${d.key}`) }, { label: 'What’s in it and who can make it', icon: Info, onClick: () => c.open(`about:${d.key}`) }]);
    const meters = (
        <>
            <PageHeaderMeterBlock label="Documents" ariaLabel={`${list.filter((d) => d.format === 'PDF').length} evidence documents`}>
                <PageHeaderMeterBig>{c.dash ?? list.filter((d) => d.format === 'PDF').length}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>{c.dash ? '—' : 'Printable PDFs'}</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock label="Data files" ariaLabel={`${list.filter((d) => d.format === 'CSV').length} data files`}>
                <PageHeaderMeterBig>{c.dash ?? list.filter((d) => d.format === 'CSV').length}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>{c.dash ? '—' : 'CSV files'}</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock label="You can make" ariaLabel={`You can make ${mine.length} of ${list.length}`}>
                <PageHeaderMeterBig>{c.dash ?? (mine.length ? `${mine.length} of ${list.length}` : 'None')}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>{c.dash ? '—' : mine.length ? 'Each asks for a purpose' : 'You can view reports'}</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock label="Exports made" ariaLabel={`${made.length} exports made`} onClick={has(c.p, 'audit.view') ? () => c.go('/emar/reports', { view: 'audit', sub: 'exports', open: undefined }) : undefined}>
                <PageHeaderMeterBig>{c.dash ?? made.length}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>{c.dash ? '—' : c.period.text}</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
        </>
    );
    const body = (
        <>
            {!mine.length ? (
                <Notice tone="neutral" icon={Info} title="You can view reports, not make exports">
                    {`Exports and prints are made by ${WHO_EXPORTS}. Ask one of them — each export is recorded with its purpose.`}
                </Notice>
            ) : mine.length < list.length ? (
                <Notice tone="neutral" icon={Info} title={`You can make ${mine.map((d) => `the ${d.name.toLowerCase()} ${d.format}`).join(' and ')}`}>
                    {stockOnly(c.p) ? 'Finance makes the stock file.' : `The others are made by ${WHO_EXPORTS}.`}
                </Notice>
            ) : null}
            <section className="flex flex-col gap-2.5" aria-label="Documents and data files">
                <ListCaption title="Documents and data files" caption={`${list.length} shown`} />
                <EntityTable<ExportDef>
                    rows={list}
                    rowKey={(d) => d.key}
                    rowHeight="content"
                    minWidth={960}
                    identityLabel="Export"
                    identityWidth="2fr"
                    identity={(d) => ({ icon: d.format === 'PDF' ? FileText : FileSpreadsheet, name: `${d.name} (${d.format})`, subline: <Wrap>{d.what}</Wrap> })}
                    columns={[
                        { key: 'limit', label: 'Range', width: '1fr', cell: (d) => <span className="text-[12.5px]">{d.limit}</span> },
                        { key: 'who', label: 'Made by', width: '1.3fr', cell: (d) => <span className="text-[12.5px]">{whoCan(d).charAt(0).toUpperCase() + whoCan(d).slice(1)}</span> },
                        { key: 'purpose', label: 'Purpose', width: '0.8fr', cell: (d) => (d.identifiable ? <StatusBadge variant="info" className="rounded-[8px]">Asked for</StatusBadge> : <span className="text-[12.5px] text-muted-foreground">No people in it</span>) },
                        {
                            key: 'act',
                            label: '',
                            width: '110px',
                            align: 'right',
                            cell: (d) =>
                                canExportKind(c.p, d.key) ? (
                                    <Button size="sm" variant="outline" data-return={`ex-${d.key}`} onClick={stop(() => c.open(`export:${d.key}`))}>
                                        Make it
                                    </Button>
                                ) : null,
                        },
                    ]}
                    actionsFor={menu}
                    onOpen={(d) => c.open(canExportKind(c.p, d.key) ? `export:${d.key}` : `about:${d.key}`)}
                    onRowContextMenu={(e, d) => ctxMenu.openAt(e, `${d.name} (${d.format})`, menu(d))}
                />
            </section>
            <p className="text-caption">Times in the files are NZ time, labelled NZDT or NZST. Every export that names people asks for a purpose, and every export goes in the audit trail.</p>
            {ctxMenu.node}
        </>
    );
    return { meters, body };
}
