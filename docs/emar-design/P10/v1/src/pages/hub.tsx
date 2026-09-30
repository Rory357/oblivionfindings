/* A FRAME of P09’s Medication › Reports & audit — /emar/reports — showing
 * only P10’s additions (Main, Q8, Q9): emergency access events and downtime
 * events in the audit trail (kind “Emergency access”, “Downtime & paper”),
 * and the downtime pack in Print & exports. The hub, its rail, the audit
 * trail and the export dialog are P09 v1.1’s, unchanged; Standard reports
 * and the Report builder are P09’s and link-only here. */
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
import { Card } from '@/components/ui/card';
import { ErrorState } from '@/components/ui/error-state';
import { SkeletonTable } from '@/components/ui/skeleton-table';
import { BarChart3, CalendarRange, Clock3, Download, FileBarChart, History, Home, Lock, Printer, ShieldCheck, SlidersHorizontal, UserRound, type LucideIcon } from 'lucide-react';
import { useState, type MouseEvent, type ReactNode } from 'react';
import { HOUSES, PEOPLE, PERSONAS, has, type House, type PersonId, type PersonaId } from '../data';
import { useOpen } from '../host';
import { KIND_LABEL, PERIOD_OPTIONS, WHO_AUDITS, canAudit, canReports, peopleOf, periodOf, type EventKind, type Period, type Runtime, type Scenario } from '../model';
import { Shell } from '../shell';
import { hrefFor, useStore } from '../store';
import { DesignNote, SubTabs } from '../ui';
import { AUDIT_SUBS, audit, type AuditSub } from './audit';
import { exportsView } from './exports';

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
/** What a P09 view is given (P09’s std-reports Ctx, trimmed to what the audit trail and exports use). */
export interface Ctx {
    p: PersonaId;
    period: Period;
    pids: PersonId[];
    rt: Runtime;
    dash: string | null;
    open: (spec: string) => void;
    toast: (msg: string) => void;
    go: (path: string, params?: Record<string, string | undefined>) => void;
    scenario: Scenario;
    href: (params: Record<string, string | undefined>) => string;
}
export interface Built {
    meters: ReactNode;
    body: ReactNode;
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
    const view = (['audit', 'exports'].includes(r.q.get('view') ?? '') ? r.q.get('view') : canAudit(p) ? 'audit' : 'exports') as View;
    const period = periodOf(r.q.get('period'), r.q.get('from'), r.q.get('to'));
    const houseQ = r.q.get('house') as House | null;
    const people = peopleOf(p).filter((x) => !houseQ || PEOPLE[x].house === houseQ);
    const personQ = people.includes(r.q.get('person') as PersonId) ? (r.q.get('person') as PersonId) : null;
    const pids = personQ ? [personQ] : people;
    const scn = r.scenario;
    const loading = scn === 'loading';
    const failed = scn === 'unavailable';
    const dash = loading ? '—' : failed ? 'Unavailable' : null;
    const auditSub = (AUDIT_SUBS.some((x) => x.key === r.q.get('sub')) ? r.q.get('sub') : 'events') as AuditSub;
    const kind = r.q.get('kind') ?? 'all';
    const pg = Number(r.q.get('pg') ?? '1') || 1;
    const set = (patch: Record<string, string | undefined>) => s.set({ ...patch, pg: undefined, open: undefined });
    const ctx: Ctx = {
        p,
        period,
        pids,
        rt: s.rt,
        dash,
        open,
        toast: (msg) => s.toast('info', msg),
        go: s.go,
        scenario: scn,
        href: (params) => {
            const q: Record<string, string | undefined> = {};
            r.q.forEach((v, k) => (q[k] = v));
            return hrefFor(r.path, { ...q, ...params }, r).slice(1);
        },
    };
    const allowed = canReports(p);
    const locked = (title: string, text: string) => (
        <Card className="items-center gap-3 p-10 text-center">
            <Lock className="size-8 text-muted-foreground" aria-hidden="true" />
            <h2 className="text-section-title">{title}</h2>
            <p className="text-subtle">{text}</p>
        </Card>
    );
    let built: Built = { meters: undefined, body: null };
    let bodyLocked: ReactNode = null;
    if (!allowed) bodyLocked = locked('Reports & audit is for people who oversee medication', 'Your own doses are on your MAR and in Meds today.');
    else if (view === 'audit') {
        if (!canAudit(p)) bodyLocked = locked('The audit trail is for people who review medication records', `It’s for ${WHO_AUDITS}. Ask your manager if you need it for your work.`);
        else built = audit(ctx, ctxMenu, auditSub, kind, personQ, pg);
    } else built = exportsView(ctx, ctxMenu);
    const viewLabel = RAIL.find((x) => x.key === view)!.label;
    const header = (
        <PageHeader
            icon={FileBarChart}
            title="Reports & audit"
            titleChip={<PageHeaderStatusChip variant="neutral">{me.houses.length > 1 && !houseQ ? '2 houses' : '1 house'}</PageHeaderStatusChip>}
            subline={`${viewLabel} · ${period.text} · NZ time`}
            actions={
                allowed ? (
                    <>
                        {view === 'audit' && canAudit(p) ? (
                            <PageHeaderGlassButton icon={ShieldCheck} onClick={() => s.toast('info', 'P09’s “Verify the chain” — outside this preview.')}>
                                Verify the chain
                            </PageHeaderGlassButton>
                        ) : null}
                        {view === 'audit' && has(p, 'audit.export') ? (
                            <PageHeaderPrimaryButton icon={Download} onClick={() => open('export:audit')}>
                                Export
                            </PageHeaderPrimaryButton>
                        ) : null}
                    </>
                ) : undefined
            }
            meters={bodyLocked ? undefined : built.meters}
            filters={
                allowed ? (
                    <>
                        <PageHeaderFilterSelect icon={CalendarRange} label="Period" value={period.key} allValue="month" onChange={(v) => (v === 'custom' ? s.toast('info', 'P09’s custom period — outside this preview.') : set({ period: v === 'month' ? undefined : v, from: undefined, to: undefined }))} options={PERIOD_OPTIONS} />
                        {me.houses.length > 1 ? <PageHeaderFilterSelect icon={Home} label="House" value={houseQ ?? 'all'} allValue="all" onChange={(v) => set({ house: v === 'all' ? undefined : v, person: undefined })} options={[{ value: 'all', label: 'All your houses' }, ...me.houses.map((h) => ({ value: h, label: HOUSES[h] }))]} /> : null}
                        {view === 'audit' ? <PageHeaderFilterSelect icon={UserRound} label="Person" value={personQ ?? 'all'} allValue="all" onChange={(v) => set({ person: v === 'all' ? undefined : v })} options={[{ value: 'all', label: 'Everyone' }, ...people.map((x) => ({ value: x, label: PEOPLE[x].legal }))]} /> : null}
                        {view === 'audit' && auditSub === 'events' && canAudit(p) ? <PageHeaderFilterSelect label="Kind" value={kind} allValue="all" onChange={(v) => set({ kind: v === 'all' ? undefined : v })} options={[{ value: 'all', label: 'All events' }, ...(Object.keys(KIND_LABEL) as EventKind[]).map((k) => ({ value: k, label: KIND_LABEL[k] }))]} /> : null}
                        <PageHeaderFilterButton icon={Clock3} onClick={() => s.toast('success', 'Up to date · as at 9:12 am · Pacific/Auckland')}>
                            As at 9:12 am · NZDT
                        </PageHeaderFilterButton>
                    </>
                ) : undefined
            }
            rail={<PageHeaderRail<View> items={RAIL} value={view} onSelect={(k) => (k === 'reports' || k === 'builder' ? s.toast('info', `${RAIL.find((x) => x.key === k)!.label} is P09’s — outside this preview.`) : set({ view: k, sub: undefined, kind: undefined }))} ariaLabel="Reports & audit views" />}
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
    else body = built.body;
    const subTabs = allowed && view === 'audit' && canAudit(p) ? <SubTabs tabs={AUDIT_SUBS.map((x) => ({ key: x.key, label: x.label, icon: x.icon }))} active={auditSub} onTab={(k) => set({ sub: k === 'events' ? undefined : k, kind: undefined })} hrefOf={(k) => hrefFor('/emar/reports', { view: 'audit', sub: k === 'events' ? undefined : k }, r)} label="Audit trail views" /> : null;
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Medication', href: hrefFor('/emar/reports', {}, r) }, { title: 'Reports & audit', href: hrefFor('/emar/reports', {}, r) }, { title: viewLabel }]}>
            {header}
            {subTabs}
            <div id="p09-reports-panel" role={subTabs ? 'tabpanel' : undefined} aria-label={viewLabel} className="flex min-w-0 flex-col gap-5">
                {body}
                <DesignNote title={view === 'audit' ? 'Design note — P10’s events in P09’s audit trail (Main, Q8)' : 'Design note — the downtime pack in P09’s Print & exports (Main, Q9, Q10)'}>
                    <p>
                        {view === 'audit'
                            ? 'Today no emergency access action writes an audit event, and P09’s audit trail has no source for it. Here each start (“opened”, as P09’s event E-bg-1 reads), extension, end, review, correction and repeat-use flag is an event in the house’s chain, filtered by the kind “Emergency access”; every dose recorded under a grant says which grant; downtimes and each dose entered from paper are events too, with both times. P09’s event numbers move up by the events P10 adds; the order and times are P09’s.'
                            : 'Today the only paper path is three PDFs, and the round sheet lists only doses already recorded — an unstarted round prints “No medications assigned to this round.” Here the downtime pack is one of P09’s documents: one house, today or tomorrow, made by house leads, clinical leads, coordinators and managers for their houses, with the purpose “Downtime” recorded. Its controlled register pages print only for someone with controlled-medicine access.'}
                    </p>
                </DesignNote>
            </div>
        </Shell>
    );
}
