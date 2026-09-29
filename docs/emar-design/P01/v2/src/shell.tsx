/* eslint-disable no-restricted-syntax -- The app shell chrome (ink top bar and sidebar) is reproduced with its own styled native controls, as the app-sidebar/app-header do; AppLayout itself needs live Inertia page props. Semantic tokens only. */
/* App shell chrome for the preview — the approved "Event Horizon" shell
 * (APP_SHELL_STYLE_GUIDE): one ink surface for the 58px top bar and the 256px
 * sidebar, the 20px gutter, and the Home-rooted breadcrumb strip rendered by
 * the REAL components/breadcrumbs.tsx. The shell's own React component needs
 * the live Inertia page props, so — as in the Fleet previews — its chrome is
 * reproduced here with the shell tokens; everything inside the page is real.
 * The hatched bar at the very top is the mockup viewer, never product UI. */
import { Breadcrumbs } from '@/components/breadcrumbs';
import { EventHorizonWordmark } from '@/components/event-horizon-wordmark';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn } from '@/lib/utils';
import {
    AlertTriangle,
    Bell,
    Building2,
    CalendarDays,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    Clock3,
    FileText,
    HelpCircle,
    Home,
    Info,
    ListTodo,
    MessageSquare,
    Pill,
    Save,
    Search,
    Settings2,
    Siren,
    Sun,
    Truck,
    Users,
    XCircle,
    type LucideIcon,
} from 'lucide-react';
import { type ReactNode } from 'react';
import { DAY_LABEL } from './clock';
import { SCENARIOS, type Scenario } from './contract';
import { PERSONAS, isFrontline, type PersonaId } from './data';
import { RejectedReviewDialog } from './dialogs';
import { hrefFor, useCounts, useStore } from './store';
import { useOverdueTaskCount } from './tasks-model';
import { useState } from 'react';

/* ───────────── mockup viewer (not product UI) ───────────── */
function Viewer() {
    const s = useStore();
    const r = s.route;
    const pages: [string, string][] = [
        ['/meds/today', 'Meds today'],
        ['/my-day', 'My Day'],
        ['/tasks', 'All Tasks'],
        ['/my-calendar', 'My Calendar'],
        ['/emar/mar', 'MAR chart'],
        ['/operations/clients/201', 'Client profile'],
        ['/fleet-assets/transports/12', 'Transport'],
        ['/p01/contract', 'The contract'],
    ];
    const groups = [...new Set(SCENARIOS.map((x) => x.group))];
    return (
        <div
            role="region"
            aria-label="Mockup viewer — not product UI"
            className="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-dashed border-border bg-muted px-4 py-2 text-[12px] text-muted-foreground"
            style={{ backgroundImage: 'repeating-linear-gradient(135deg, transparent 0 8px, color-mix(in oklch, var(--foreground) 5%, transparent) 8px 9px)' }}
        >
            <span className="font-bold tracking-wide text-foreground uppercase">eMAR P01 v2 · mockup viewer — not product UI</span>
            <span className="rounded-md bg-status-warning-bg px-2 py-0.5 font-semibold text-status-warning">Synthetic data · Monday 28 Sep 2026, 9:12 am NZDT</span>
            <label className="flex items-center gap-1.5">
                Signed in as
                <Select value={r.persona} onValueChange={(v) => s.setViewer({ persona: v as PersonaId })}>
                    <SelectTrigger className="h-7 min-w-44 bg-card text-[12px]" aria-label="Signed in as">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {Object.values(PERSONAS).map((p) => (
                            <SelectItem key={p.id} value={p.id}>
                                {p.name} — {p.role}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </label>
            <label className="flex items-center gap-1.5">
                Scenario
                <Select value={r.scenario} onValueChange={(v) => s.setViewer({ scenario: v as Scenario })}>
                    <SelectTrigger className="h-7 min-w-52 bg-card text-[12px]" aria-label="Scenario">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {groups.map((g) => (
                            <SelectGroup key={g}>
                                <SelectLabel>{g}</SelectLabel>
                                {SCENARIOS.filter((x) => x.group === g).map((x) => (
                                    <SelectItem key={x.key} value={x.key}>
                                        {x.label}
                                    </SelectItem>
                                ))}
                            </SelectGroup>
                        ))}
                    </SelectContent>
                </Select>
            </label>
            <label className="flex items-center gap-1.5">
                Allergy rule
                <Select value={r.allergyRule} onValueChange={(v) => s.setViewer({ allergyRule: v as 'warn' | 'confirm' })}>
                    <SelectTrigger className="h-7 min-w-48 bg-card text-[12px]" aria-label="Allergy rule">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="warn">Warn (current setting)</SelectItem>
                        <SelectItem value="confirm">Block unless the prescriber confirmed (later)</SelectItem>
                    </SelectContent>
                </Select>
            </label>
            <nav aria-label="Mockup pages" className="flex flex-wrap items-center gap-1">
                {pages.map(([p, l]) => (
                    <a key={p} href={hrefFor(p, {}, r)} className={cn('rounded px-1.5 py-0.5 underline-offset-2 hover:underline', r.path === p && 'bg-card font-semibold text-foreground')}>
                        {l}
                    </a>
                ))}
            </nav>
        </div>
    );
}

/* ───────────── top bar ───────────── */
function TopBar() {
    const s = useStore();
    const p = PERSONAS[s.route.persona];
    const notClocked = s.route.scenario === 'notClockedIn' && !s.clockedIn;
    const outside = (what: string) => () => s.toast('info', `${what} is global chrome — outside this preview.`);
    const pmRequests = s.route.persona === 'pm' && s.route.scenario === 'cdNoWitness' && s.override === 'waiting';
    return (
        <header className="relative grid h-[58px] shrink-0 grid-cols-[1fr_auto_1fr] items-center bg-sidebar px-4 text-sidebar-foreground">
            <div className="flex items-center gap-6">
                <EventHorizonWordmark />
                {/* APP_SHELL_STYLE_GUIDE §2: full date ≥ 1320 px, short form 1140–1320 px, hidden below. */}
                <span className="absolute left-[256px] hidden text-[14px] min-[1320px]:inline">
                    <span className="font-semibold text-sidebar-accent-foreground">Monday</span> 28 September 2026
                </span>
                <span className="absolute left-[256px] hidden text-[14px] min-[1140px]:inline min-[1320px]:hidden">
                    <span className="font-semibold text-sidebar-accent-foreground">Mon</span> 28 Sep
                </span>
            </div>
            <button type="button" aria-label="Search or jump to" onClick={outside('Command search')} className="grid size-8 place-items-center rounded-md outline-none hover:bg-sidebar-accent focus-visible:ring-2 focus-visible:ring-sidebar-ring lg:hidden">
                <Search className="size-4" />
            </button>
            <button type="button" onClick={outside('Command search')} className="hidden h-9 w-[430px] max-w-[36vw] items-center lg:flex gap-2 rounded-[10px] border border-sidebar-border bg-sidebar-accent px-3 text-[13px] text-sidebar-foreground outline-none focus-visible:ring-2 focus-visible:ring-sidebar-ring">
                <Search className="size-4" /> Search or jump to…
                <kbd className="ml-auto rounded border border-sidebar-border px-1.5 text-[11px]">Ctrl K</kbd>
            </button>
            <div className="flex items-center justify-end gap-2">
                <Button size="sm" onClick={outside('Report incident')}>
                    <Siren className="size-4" /> <span className="hidden lg:inline">Report incident</span>
                </Button>
                <button
                    type="button"
                    onClick={() => (notClocked ? (s.clockIn(), s.toast('success', 'Clocked in at 9:12 am · Kōwhai House. You can record now.')) : outside('Clock in/out')())}
                    className={cn('flex h-8 items-center gap-1.5 rounded-[10px] border px-2.5 text-[12.5px] font-semibold outline-none focus-visible:ring-2 focus-visible:ring-sidebar-ring', notClocked ? 'border-status-warning/70 text-status-warning' : 'border-sidebar-border text-sidebar-accent-foreground')}
                >
                    <Clock3 className="size-4" /> <span className={notClocked ? '' : 'hidden xl:inline'}>{notClocked ? 'Clock in' : 'Clocked in 7:02 am'}</span>
                </button>
                <button type="button" aria-label="Messages" onClick={outside('Messages')} className="relative grid size-8 place-items-center rounded-md outline-none hover:bg-sidebar-accent focus-visible:ring-2 focus-visible:ring-sidebar-ring">
                    <MessageSquare className="size-[18px]" />
                </button>
                <button type="button" aria-label={pmRequests ? 'Notifications — 1 witness override request waiting' : 'Notifications'} onClick={() => (pmRequests ? s.go('/tasks') : outside('Notifications')())} className="relative grid size-8 place-items-center rounded-md outline-none hover:bg-sidebar-accent focus-visible:ring-2 focus-visible:ring-sidebar-ring">
                    <Bell className="size-[18px]" />
                    {pmRequests ? <span className="absolute -top-0.5 -right-0.5 grid h-4 min-w-4 place-items-center rounded-full bg-status-critical px-1 text-[10px] font-bold text-primary-foreground">1</span> : null}
                </button>
                <span className="grid size-8 place-items-center rounded-full bg-sidebar-accent text-[12px] font-semibold text-sidebar-accent-foreground" title={`${p.name} · ${p.role}`}>
                    {p.initials}
                </span>
            </div>
        </header>
    );
}

/* ───────────── sidebar ───────────── */
function NavRow({ icon: Icon, label, href, active, count, alert, onClick }: { icon: LucideIcon; label: string; href?: string; active?: boolean; count?: ReactNode; alert?: boolean; onClick?: () => void }) {
    const cls = cn(
        'relative flex h-[37px] w-full items-center justify-center gap-2.5 rounded-lg px-0 text-[13.5px] lg:justify-start lg:px-3 outline-none focus-visible:ring-2 focus-visible:ring-sidebar-ring',
        active ? 'bg-sidebar-accent font-semibold text-sidebar-accent-foreground' : 'text-sidebar-foreground hover:bg-sidebar-accent/60',
    );
    const inner = (
        <>
            <Icon className={cn('size-[17px] shrink-0', active ? 'text-sidebar-primary' : 'opacity-70')} />
            <span className="hidden flex-1 truncate text-left lg:block">{label}</span>
            {count != null ? <span className={cn('absolute top-0 right-0 min-w-[18px] rounded-[6px] px-1 lg:static lg:min-w-[22px] lg:px-1.5 text-center text-[11px] font-bold', alert ? 'bg-status-critical-bg text-status-critical' : 'bg-sidebar-accent text-sidebar-accent-foreground')}>{count}</span> : null}
        </>
    );
    return href ? (
        <a href={href} className={cls} aria-current={active ? 'page' : undefined} aria-label={label} title={label}>
            {inner}
        </a>
    ) : (
        <button type="button" className={cls} onClick={onClick} aria-label={label} title={label}>
            {inner}
        </button>
    );
}
function Sidebar() {
    const s = useStore();
    const r = s.route;
    const c = useCounts();
    const overdueTasks = useOverdueTaskCount();
    const frontline = isFrontline(r.persona);
    const late = c.late.length;
    const unavailable = r.scenario === 'unavailable';
    const outside = (what: string) => () => s.toast('info', `${what} — outside this preview.`);
    const HUBS: [string, string, string | null][] = [
        ['Meds today', '/meds/today', null],
        ['MAR & medicines', '/emar/mar', null],
        ['Orders & reviews', '', 'P04'],
        ['Stock & controlled drugs', '', 'P06 / P07b'],
        ['Safety & oversight', '', 'P08a / P08b'],
        ['Reports & audit', '', 'P09'],
        ['Settings', '', 'P11'],
    ];
    const badge = unavailable ? '?' : late || undefined;
    return (
        <aside aria-label="Main navigation" className="flex w-16 shrink-0 flex-col gap-1 overflow-y-auto bg-sidebar px-2 py-3 lg:w-[256px] lg:px-3 text-sidebar-foreground">
            <NavRow icon={Sun} label="My Day" href={hrefFor('/my-day', {}, r)} active={r.path === '/my-day'} />
            <NavRow icon={Home} label="Overview" onClick={outside('Overview')} />
            <NavRow icon={CalendarDays} label="My Calendar" href={hrefFor('/my-calendar', {}, r)} active={r.path === '/my-calendar'} />
            <NavRow icon={ListTodo} label="All Tasks" href={hrefFor('/tasks', {}, r)} active={r.path === '/tasks'} count={overdueTasks || undefined} alert={!!overdueTasks} />
            {frontline ? (
                <NavRow icon={Pill} label="Meds today" href={hrefFor('/meds/today', {}, r)} active={r.path.startsWith('/meds/today')} count={badge} alert={!!badge} />
            ) : null}
            <div className="my-2 h-px bg-sidebar-border" />
            {frontline ? (
                <>
                    <GroupRow icon={Building2} label="Operations" onClick={outside('Operations')} />
                    <GroupRow icon={Users} label="People & HR" onClick={outside('People & HR')} />
                    <GroupRow icon={AlertTriangle} label="Incidents" onClick={outside('Incidents')} />
                    <GroupRow icon={Truck} label="Fleet" onClick={outside('Fleet')} />
                </>
            ) : (
                <>
                    <div className="relative flex h-9 items-center justify-center gap-2.5 px-0 text-[13.5px] font-semibold text-sidebar-accent-foreground lg:justify-start lg:px-3" title="Medication">
                        <Pill className="size-[17px]" />
                        <span className="hidden flex-1 lg:block">Medication</span>
                        <ChevronDown className="hidden size-3.5 lg:block" />
                        {badge ? <span className="absolute top-0 right-0 rounded-[6px] bg-status-critical-bg px-1 text-[11px] font-bold text-status-critical lg:hidden">{badge}</span> : null}
                    </div>
                    {HUBS.map(([label, path, pkg]) => (
                        <a
                            key={label}
                            href={path ? hrefFor(path, {}, r) : undefined}
                            onClick={path ? undefined : (e) => (e.preventDefault(), s.toast('info', `${label} is designed in ${pkg} — outside P01.`))}
                            role={path ? undefined : 'button'}
                            tabIndex={0}
                            className={cn('hidden h-[31px] items-center rounded-lg pr-3 pl-[38px] lg:flex text-[13px] outline-none focus-visible:ring-2 focus-visible:ring-sidebar-ring', path && r.path.startsWith(path) ? 'bg-sidebar-accent font-semibold text-sidebar-accent-foreground' : 'hover:bg-sidebar-accent/60')}
                        >
                            <span className="flex-1">{label}</span>
                            {label === 'Meds today' && badge ? <span className="rounded-[6px] bg-status-critical-bg px-1.5 text-[11px] font-bold text-status-critical">{badge}</span> : null}
                        </a>
                    ))}
                    <GroupRow icon={Building2} label="Operations" onClick={outside('Operations')} />
                    <GroupRow icon={Truck} label="Fleet" onClick={outside('Fleet')} />
                </>
            )}
            <div className="mt-auto border-t border-sidebar-border pt-2">
                <NavRow icon={Settings2} label="Settings" onClick={outside('Settings')} />
            </div>
        </aside>
    );
}
function GroupRow({ icon: Icon, label, onClick }: { icon: LucideIcon; label: string; onClick: () => void }) {
    return (
        <button type="button" onClick={onClick} aria-label={label} title={label} className="flex h-9 w-full items-center justify-center gap-2.5 rounded-lg px-0 text-[13.5px] font-semibold lg:justify-start lg:px-3 text-sidebar-foreground outline-none hover:bg-sidebar-accent/60 focus-visible:ring-2 focus-visible:ring-sidebar-ring">
            <Icon className="size-[17px] opacity-70" />
            <span className="hidden flex-1 text-left lg:block">{label}</span>
            <ChevronRight className="hidden size-3.5 opacity-60 lg:block" />
        </button>
    );
}

/* ───────────── app-wide medication banners (P00 contract; EM-26) ───────────── */
function AppWideBanners() {
    const s = useStore();
    const [review, setReview] = useState(false);
    if (!['sw', 'daniel'].includes(s.route.persona)) return null;
    const queued = Object.values(s.flags).filter((f) => f === 'queued').length + s.prnRecords.filter((p) => p.state === 'queued').length;
    const uncertain = Object.values(s.flags).filter((f) => f === 'uncertain').length;
    const out: ReactNode[] = [];
    if (s.route.scenario === 'offlineRefused' && !s.offlineRefusedDismissed)
        out.push(
            <div key="ref" role="alert" className="flex flex-wrap items-center gap-3 border-b border-status-critical/30 bg-status-critical-bg px-5 py-2 text-[13px] text-status-critical">
                <XCircle className="size-4 shrink-0" />
                <span className="flex-1 text-foreground">
                    A saved medication action was <strong>not</strong> recorded: Paracetamol for Mele, saved offline at 8:50 am — the as-needed limit on the prescription is reached. It won’t be sent again.
                </span>
                <Button className="frontline-tap" variant="outline" onClick={() => setReview(true)}>
                    What to do
                </Button>
                <Button className="frontline-tap" variant="ghost" onClick={s.dismissOfflineRefused}>
                    Dismiss
                </Button>
            </div>,
        );
    if (s.route.scenario === 'offline' || queued)
        out.push(
            <div key="off" role="status" className="flex flex-wrap items-center gap-3 border-b border-status-warning/30 bg-status-warning-bg px-5 py-2 text-[13px] text-status-warning">
                <Save className="size-4 shrink-0" />
                <span className="flex-1 text-foreground">
                    {s.route.scenario === 'offline' ? 'You’re offline. ' : ''}
                    {queued ? `${queued} medication ${queued === 1 ? 'record is' : 'records are'} saved on this device only — ${queued === 1 ? 'it sends' : 'they send'} when you reconnect. Other staff can’t see ${queued === 1 ? 'it' : 'them'} yet.` : 'Records you make now are saved on this device and sent when you reconnect.'}
                </span>
            </div>,
        );
    if (uncertain)
        out.push(
            <div key="unc" role="alert" className="flex flex-wrap items-center gap-3 border-b border-status-warning/30 bg-status-warning-bg px-5 py-2 text-[13px] text-status-warning">
                <HelpCircle className="size-4 shrink-0" />
                <span className="flex-1 text-foreground">{uncertain} medication {uncertain === 1 ? 'record wasn’t' : 'records weren’t'} confirmed. Check the chart before trying again — trying again won’t create a duplicate.</span>
            </div>,
        );
    return (
        <>
            {out}
            {review ? <RejectedReviewDialog onClose={() => setReview(false)} /> : null}
        </>
    );
}

/* ───────────── toasts (flash-toaster look: token pairs) ───────────── */
function Toasts() {
    const s = useStore();
    return (
        <div aria-live="polite" className="pointer-events-none fixed right-5 bottom-5 z-[300] flex w-[380px] max-w-[90vw] flex-col gap-2">
            {s.toasts.map((t) => {
                const Icon = t.kind === 'success' ? CheckCircle2 : t.kind === 'critical' ? XCircle : t.kind === 'warning' ? AlertTriangle : Info;
                return (
                    <div key={t.id} role={t.kind === 'critical' ? 'alert' : 'status'} className={cn('pointer-events-auto flex items-start gap-2.5 rounded-xl border bg-card p-3 text-sm shadow-lg', t.kind === 'success' ? 'border-status-success/40' : t.kind === 'critical' ? 'border-status-critical/40' : t.kind === 'warning' ? 'border-status-warning/40' : 'border-border')}>
                        <Icon className={cn('mt-0.5 size-4 shrink-0', t.kind === 'success' ? 'text-status-success' : t.kind === 'critical' ? 'text-status-critical' : t.kind === 'warning' ? 'text-status-warning' : 'text-status-info')} />
                        <span>{t.msg}</span>
                    </div>
                );
            })}
        </div>
    );
}

/* ───────────── the page frame ───────────── */
export function Shell({ crumbs, children }: { crumbs: { title: string; href?: string }[]; children: ReactNode }) {
    return (
        <div className="flex min-h-screen flex-col bg-background">
            <a href="#main" className="sr-only focus:not-sr-only focus:absolute focus:z-[400] focus:rounded-md focus:bg-card focus:p-2">
                Skip to content
            </a>
            <Viewer />
            <TopBar />
            <div className="flex min-h-0 flex-1">
                <Sidebar />
                <div className="flex min-w-0 flex-1 flex-col">
                    <AppWideBanners />
                    <div className="px-5 py-2.5">
                        <Breadcrumbs breadcrumbs={crumbs} />
                    </div>
                    <main id="main" tabIndex={-1} className="flex w-full min-w-0 flex-1 flex-col gap-5 px-5 pb-5 outline-none">
                        {children}
                    </main>
                </div>
            </div>
            <Toasts />
            <span className="sr-only">{DAY_LABEL}</span>
        </div>
    );
}
