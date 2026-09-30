/* Medication › Reports & audit — /emar/reports (Main, Q1). One hub on P02’s
 * hub pattern: the PageHeader with this view’s meters, the period, house and
 * person filters, and the rail: Standard reports · Report builder · Audit
 * trail · Print & exports. It replaces today’s Reports page, the older
 * /reports/medications and /medications/audit pages, the dashboard’s
 * ReportsModal and AuditLogModal, and the controlled register’s Audit Trail
 * tab (P07b). */
import { EntityContextMenu, type MenuItem } from '@/components/lists/entity-menu';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { SkeletonTable } from '@/components/ui/skeleton-table';
import { AlertTriangle, BarChart3, CalendarRange, Clock3, Download, FileBarChart, History, Home, Lock, Printer, RefreshCw, ShieldCheck, SlidersHorizontal, UserRound, type LucideIcon } from 'lucide-react';
import { useState, type MouseEvent, type ReactNode } from 'react';
import { HOUSES, PEOPLE, PERSONAS, has, type House, type PersonId } from '../data';
import { useOpen } from '../host';
import { KIND_LABEL, PERIOD_OPTIONS, WHO_AUDITS, WHO_REPORTS, canAudit, canReports, peopleOf, periodOf, stockOnly, type EventKind } from '../model';
import { Shell } from '../shell';
import { hrefFor, useStore } from '../store';
import { DesignNote, Notice, SubTabs } from '../ui';
import { AUDIT_SUBS, audit, type AuditSub } from './audit';
import { exportsView } from './exports';
import { BUILDERS, REPORTS, type Built, type Ctx, type ReportKey } from './std-reports';

/** The same menu for ⋯, right-click and the menu key (LIST_STYLE_GUIDE §1). */
export function useCtx(icon: LucideIcon = FileBarChart) {
    const [ctx, setCtx] = useState<{ x: number; y: number; title: string; items: MenuItem[] } | null>(null);
    const openAt = (e: MouseEvent, title: string, items: MenuItem[]) => {
        e.preventDefault();
        const r = (e.currentTarget as HTMLElement).getBoundingClientRect();
        setCtx({ x: e.clientX || r.left + 24, y: e.clientY || r.top + r.height / 2, title, items });
    };
    return { openAt, node: ctx ? <EntityContextMenu x={ctx.x} y={ctx.y} icon={icon} title={ctx.title} items={ctx.items} onClose={() => setCtx(null)} /> : null };
}

type View = 'reports' | 'builder' | 'audit' | 'exports';
const RAIL: { key: View; label: string; icon: LucideIcon }[] = [
    { key: 'reports', label: 'Standard reports', icon: BarChart3 },
    { key: 'builder', label: 'Report builder', icon: SlidersHorizontal },
    { key: 'audit', label: 'Audit trail', icon: History },
    { key: 'exports', label: 'Print & exports', icon: Printer },
];

export function ReportsHub() {
    const s = useStore();
    const r = s.route;
    const p = r.persona;
    const me = PERSONAS[p];
    const open = useOpen();
    const ctxMenu = useCtx();
    const view = (RAIL.some((x) => x.key === r.q.get('view')) ? r.q.get('view') : 'reports') as View;
    const period = periodOf(r.q.get('period'), r.q.get('from'), r.q.get('to'));
    const houseQ = r.q.get('house') as House | null;
    const people = peopleOf(p).filter((x) => !houseQ || PEOPLE[x].house === houseQ);
    const personQ = people.includes(r.q.get('person') as PersonId) ? (r.q.get('person') as PersonId) : null;
    const pids = personQ ? [personQ] : people;
    const scn = r.scenario;
    const loading = scn === 'loading';
    const failed = scn === 'unavailable';
    const empty = scn === 'empty';
    const dash = loading ? '—' : failed ? 'Unavailable' : null;
    const reports = stockOnly(p) ? REPORTS.filter((x) => x.key === 'stock') : REPORTS;
    const report = (reports.some((x) => x.key === r.q.get('sub')) ? r.q.get('sub') : reports[0].key) as ReportKey;
    const auditSub = (AUDIT_SUBS.some((x) => x.key === r.q.get('sub')) ? r.q.get('sub') : 'events') as AuditSub;
    const kind = r.q.get('kind') ?? 'all';
    const pg = Number(r.q.get('pg') ?? '1') || 1;
    const set = (patch: Record<string, string | undefined>) => s.set({ ...patch, pg: undefined, open: undefined });
    const ctx: Ctx = {
        p,
        period,
        pids: empty ? [] : pids,
        rt: s.rt,
        dash,
        open,
        toast: (msg) => s.toast('info', msg),
        go: s.go,
        sac: s.rt.org.sac || r.q.get('sac') === 'on',
        scenario: scn,
        href: (params) => {
            const q: Record<string, string | undefined> = {};
            r.q.forEach((v, k) => (q[k] = v));
            return hrefFor(r.path, { ...q, ...params }, r).slice(1);
        },
    };
    const allowed = canReports(p) || stockOnly(p);
    const locked = (title: string, text: string) => (
        <Card className="items-center gap-3 p-10 text-center">
            <Lock className="size-8 text-muted-foreground" aria-hidden="true" />
            <h2 className="text-section-title">{title}</h2>
            <p className="text-subtle">{text}</p>
        </Card>
    );
    let built: Built = { meters: undefined, body: null };
    let bodyLocked: ReactNode = null;
    if (!allowed) bodyLocked = locked('Reports & audit is for people who oversee medication', `It’s for ${WHO_REPORTS}. Your own doses are on your MAR and in Meds today.`);
    else if (view === 'reports') built = BUILDERS[report](ctx, ctxMenu);
    else if (view === 'audit') {
        if (!canAudit(p)) bodyLocked = locked('The audit trail is for people who review medication records', `It’s for ${WHO_AUDITS}. Ask your manager if you need it for your work.`);
        else built = audit(ctx, ctxMenu, auditSub, kind, personQ, pg);
    } else if (view === 'exports') built = exportsView(ctx, ctxMenu);

    const asAt =
        scn === 'stale' ? (
            <PageHeaderFilterButton icon={AlertTriangle} active onClick={() => s.toast('info', 'Refreshing… (mockup: set the scenario back to normal).')}>
                As at 8:40 am · couldn’t refresh — try again
            </PageHeaderFilterButton>
        ) : (
            <PageHeaderFilterButton icon={Clock3} onClick={() => s.toast('success', 'Up to date · as at 9:12 am · Pacific/Auckland')}>
                As at 9:12 am · NZDT
            </PageHeaderFilterButton>
        );
    const viewLabel = view === 'reports' ? reports.find((x) => x.key === report)!.label : RAIL.find((x) => x.key === view)!.label;
    const periodMatters = !(view === 'reports' && (report === 'stock' || report === 'competency'));
    const header = (
        <PageHeader
            icon={FileBarChart}
            title="Reports & audit"
            titleChip={<PageHeaderStatusChip variant="neutral">{me.houses.length > 1 && !houseQ ? '2 houses' : '1 house'}</PageHeaderStatusChip>}
            subline={`${viewLabel} · ${periodMatters ? period.text : 'as at now'} · NZ time`}
            actions={
                allowed ? (
                    <>
                        {view === 'audit' && canAudit(p) ? (
                            <PageHeaderGlassButton icon={ShieldCheck} onClick={() => open('verify')}>
                                Verify the chain
                            </PageHeaderGlassButton>
                        ) : null}
                        {view === 'audit' && has(p, 'audit.export') ? (
                            <PageHeaderPrimaryButton icon={Download} onClick={() => open('export:audit')}>
                                Export
                            </PageHeaderPrimaryButton>
                        ) : view === 'reports' ? (
                            <PageHeaderGlassButton icon={Printer} onClick={() => set({ view: 'exports', sub: undefined })}>
                                Print & exports
                            </PageHeaderGlassButton>
                        ) : null}
                    </>
                ) : undefined
            }
            meters={bodyLocked ? undefined : built.meters}
            filters={
                allowed ? (
                    <>
                        {periodMatters ? <PageHeaderFilterSelect icon={CalendarRange} label="Period" value={period.key} allValue="month" onChange={(v) => (v === 'custom' ? open('range') : set({ period: v === 'month' ? undefined : v, from: undefined, to: undefined }))} options={PERIOD_OPTIONS.map((o) => (o.value === 'custom' && period.key === 'custom' ? { value: 'custom', label: period.text } : o))} /> : null}
                        {me.houses.length > 1 && !stockOnly(p) ? <PageHeaderFilterSelect icon={Home} label="House" value={houseQ ?? 'all'} allValue="all" onChange={(v) => set({ house: v === 'all' ? undefined : v, person: undefined })} options={[{ value: 'all', label: 'All your houses' }, ...me.houses.map((h) => ({ value: h, label: HOUSES[h] }))]} /> : null}
                        {!stockOnly(p) && (view === 'audit' || (view === 'reports' && ['doses', 'prn', 'errors', 'reviews'].includes(report))) ? <PageHeaderFilterSelect icon={UserRound} label="Person" value={personQ ?? 'all'} allValue="all" onChange={(v) => set({ person: v === 'all' ? undefined : v })} options={[{ value: 'all', label: 'Everyone' }, ...people.map((x) => ({ value: x, label: PEOPLE[x].legal }))]} /> : null}
                        {view === 'audit' && auditSub === 'events' && canAudit(p) ? <PageHeaderFilterSelect label="Kind" value={kind} allValue="all" onChange={(v) => set({ kind: v === 'all' ? undefined : v })} options={[{ value: 'all', label: 'All events' }, ...(Object.keys(KIND_LABEL) as EventKind[]).map((k) => ({ value: k, label: KIND_LABEL[k] }))]} /> : null}
                        {asAt}
                    </>
                ) : undefined
            }
            rail={<PageHeaderRail<View> items={RAIL} value={view} onSelect={(k) => (k === 'builder' ? s.go('/emar/reports/builder', { view: undefined, sub: undefined, open: undefined, pg: undefined }) : set({ view: k === 'reports' ? undefined : k, sub: undefined, kind: undefined }))} ariaLabel="Reports & audit views" />}
        />
    );

    let body: ReactNode;
    if (bodyLocked) body = bodyLocked;
    else if (loading)
        body = (
            <Card className="p-4" aria-busy="true" aria-label={`Loading ${viewLabel.toLowerCase()}`}>
                <SkeletonTable rows={6} columns={6} />
            </Card>
        );
    else if (failed)
        body = (
            <Card className="p-2">
                <ErrorState title={`We couldn’t load ${viewLabel.toLowerCase()}`} message="Nothing is shown rather than numbers that might be wrong. Try again in a moment." onRetry={() => s.setViewer({ scenario: 'normal' })} />
            </Card>
        );
    else if (empty && view !== 'exports')
        body = (
            <Card className="p-2">
                <EmptyState icon={FileBarChart} title={view === 'audit' ? 'No medication events yet' : 'No medication records yet'} description={view === 'audit' ? 'Events appear here as doses, orders and checks are recorded — each one linked to the one before it.' : 'Reports fill in as doses are recorded against people’s orders. Numbers that can’t be worked out read “Not applicable”, with why.'} />
            </Card>
        );
    else body = built.body;

    const subTabs =
        allowed && view === 'reports' ? (
            <SubTabs tabs={reports.map((x) => ({ key: x.key, label: x.label, icon: x.icon }))} active={report} onTab={(k) => set({ sub: k === reports[0].key ? undefined : k, person: ['doses', 'prn', 'errors', 'reviews'].includes(k) ? personQ ?? undefined : undefined })} hrefOf={(k) => hrefFor('/emar/reports', { sub: k === reports[0].key ? undefined : k }, r)} label="Standard reports" />
        ) : allowed && view === 'audit' && canAudit(p) ? (
            <SubTabs tabs={AUDIT_SUBS.map((x) => ({ key: x.key, label: x.label, icon: x.icon }))} active={auditSub} onTab={(k) => set({ sub: k === 'events' ? undefined : k, kind: undefined })} hrefOf={(k) => hrefFor('/emar/reports', { view: 'audit', sub: k === 'events' ? undefined : k }, r)} label="Audit trail views" />
        ) : null;

    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Medication', href: hrefFor('/emar/reports', {}, r) }, { title: 'Reports & audit', href: hrefFor('/emar/reports', {}, r) }, { title: viewLabel }]}>
            {header}
            {subTabs}
            <div id="p09-reports-panel" role={subTabs ? 'tabpanel' : undefined} aria-label={viewLabel} className="flex min-w-0 flex-col gap-5">
                {scn === 'stale' && allowed ? (
                    <Notice tone="warning" icon={AlertTriangle} title="This may be out of date" actions={<Button size="sm" variant="outline" onClick={() => s.toast('info', 'Refreshing… (mockup: set the scenario back to normal).')}><RefreshCw className="size-4" /> Try again</Button>}>
                        We couldn’t refresh at 9:12 am. It shows what was known at 8:40 am.
                    </Notice>
                ) : null}
                {body}
                <DesignNote title={view === 'audit' ? 'Design note — the audit trail (Main, Q7)' : view === 'exports' ? 'Design note — print & exports (Main, Q8)' : 'Design note — standard reports (Main, Q2–Q6)'}>
                    <p>
                        {view === 'audit'
                            ? 'Today the audit page is rebuilt from other tables on every load, capped at 800 events and filtered in the browser; its export is a different dataset; “Verify integrity” only checks the record exists; audit rows can be edited, logging failures are swallowed and rows are pruned after 2 years (AUDIT 3). Here every medication write appends one event, linked by hash to the one before it at its house, in the same transaction — if it can’t be written, the change is refused. The list and the export are the same, paged on the server.'
                            : view === 'exports'
                              ? 'Today no eMAR export is recorded or asks why; the reports CSV silently stops at 93 days; the MAR PDF drops ceased medicines; the print link has no person, so it fails; timestamps are UTC with no label (AUDIT 2). Here each export states its range, asks for a purpose when it names people, and becomes an event in the audit trail.'
                              : 'Today “compliance” has three definitions, missed rounds always read 0 %, periods are UTC, a zero denominator reads 0 % or 100 %, and controlled doses drop out of totals for some readers (AUDIT 1, 7). Here every number is worked out once from the dose slots, by NZ date, and defined on the contract page; controlled doses are in everyone’s totals, and their breakdown needs controlled-medicine access.'}
                    </p>
                </DesignNote>
            </div>
        </Shell>
    );
}
