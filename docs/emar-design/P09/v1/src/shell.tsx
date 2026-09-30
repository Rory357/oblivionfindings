/* eslint-disable no-restricted-syntax -- The app shell chrome (ink top bar and sidebar) is reproduced with its own styled native controls, as the app-sidebar/app-header do; AppLayout itself needs live Inertia page props. Semantic tokens only. */
/* App shell chrome for the preview — copied from P07a v1 / P01 v2 (src/shell.tsx):
 * the approved "Event Horizon" shell (APP_SHELL_STYLE_GUIDE), the 20px gutter
 * and the Home-rooted breadcrumb strip rendered by the REAL
 * components/breadcrumbs.tsx. The hatched bar at the very top is the mockup
 * viewer, never product UI. */
import { Breadcrumbs } from '@/components/breadcrumbs';
import { EventHorizonWordmark } from '@/components/event-horizon-wordmark';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
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
import { PERSONAS, frontline as isFrontline, type PersonaId } from './data';
import { SCENARIOS, type Scenario } from './model';
import { hrefFor, useStore } from './store';

/* ───────────── mockup viewer (not product UI) ───────────── */
function Viewer() {
    const s = useStore();
    const r = s.route;
    const pages: [string, string, (x: typeof r) => boolean][] = [
        ['/emar/reports', 'Reports & audit', (x) => x.path === '/emar/reports'],
        ['/emar/reports/builder', 'Report builder', (x) => x.path === '/emar/reports/builder'],
        ['/emar/errors', 'Medication errors › close with SAC (P08b)', (x) => x.path === '/emar/errors'],
        ['/emar/settings?view=rules&sec=records', 'Settings › Records & reporting (P11)', (x) => x.path === '/emar/settings'],
        ['/p09/contract', 'The contract', (x) => x.path === '/p09/contract'],
    ];
    return (
        <div
            role="region"
            aria-label="Mockup viewer — not product UI"
            className="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-dashed border-border bg-muted px-4 py-2 text-[12px] text-muted-foreground"
            style={{ backgroundImage: 'repeating-linear-gradient(135deg, transparent 0 8px, color-mix(in oklch, var(--foreground) 5%, transparent) 8px 9px)' }}
        >
            <span className="font-bold tracking-wide text-foreground uppercase">eMAR P09 v1.1 · mockup viewer — not product UI</span>
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
                        {SCENARIOS.map((x) => (
                            <SelectItem key={x.key} value={x.key}>
                                {x.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </label>
            <nav aria-label="Mockup pages" className="flex flex-wrap items-center gap-1">
                {pages.map(([p, l, on]) => (
                    <a key={p} href={hrefFor(p, {}, r)} className={cn('rounded px-1.5 py-0.5 underline-offset-2 hover:underline', on(r) && 'bg-card font-semibold text-foreground')}>
                        {l}
                    </a>
                ))}
            </nav>
        </div>
    );
}

/* ───────────── top bar ───────────── */
const CLOCKED: Partial<Record<PersonaId, string>> = { sw: 'Clocked in 7:02 am', lead: 'Clocked in 8:04 am', rimu: 'Clocked in 6:55 am' };
function TopBar() {
    const s = useStore();
    const p = PERSONAS[s.route.persona];
    const outside = (what: string) => () => s.toast('info', `${what} is global chrome — outside this preview.`);
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
            <button type="button" onClick={outside('Command search')} className="hidden h-9 w-[430px] max-w-[36vw] items-center gap-2 rounded-[10px] border border-sidebar-border bg-sidebar-accent px-3 text-[13px] text-sidebar-foreground outline-none focus-visible:ring-2 focus-visible:ring-sidebar-ring lg:flex">
                <Search className="size-4" /> Search or jump to…
                <kbd className="ml-auto rounded border border-sidebar-border px-1.5 text-[11px]">Ctrl K</kbd>
            </button>
            <div className="flex items-center justify-end gap-2">
                <Button size="sm" onClick={outside('Report incident')}>
                    <Siren className="size-4" /> <span className="hidden lg:inline">Report incident</span>
                </Button>
                <button type="button" onClick={outside('Clock in/out')} className="flex h-8 items-center gap-1.5 rounded-[10px] border border-sidebar-border px-2.5 text-[12.5px] font-semibold text-sidebar-accent-foreground outline-none focus-visible:ring-2 focus-visible:ring-sidebar-ring">
                    <Clock3 className="size-4" /> <span className="hidden xl:inline">{CLOCKED[p.id] ?? 'Not rostered today'}</span>
                </button>
                <button type="button" aria-label="Messages" onClick={outside('Messages')} className="relative grid size-8 place-items-center rounded-md outline-none hover:bg-sidebar-accent focus-visible:ring-2 focus-visible:ring-sidebar-ring">
                    <MessageSquare className="size-[18px]" />
                </button>
                <button type="button" aria-label="Notifications" onClick={outside('Notifications')} className="relative grid size-8 place-items-center rounded-md outline-none hover:bg-sidebar-accent focus-visible:ring-2 focus-visible:ring-sidebar-ring">
                    <Bell className="size-[18px]" />
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
        'relative flex h-[37px] w-full items-center justify-center gap-2.5 rounded-lg px-0 text-[13.5px] outline-none focus-visible:ring-2 focus-visible:ring-sidebar-ring lg:justify-start lg:px-3',
        active ? 'bg-sidebar-accent font-semibold text-sidebar-accent-foreground' : 'text-sidebar-foreground hover:bg-sidebar-accent/60',
    );
    const inner = (
        <>
            <Icon className={cn('size-[17px] shrink-0', active ? 'text-sidebar-primary' : 'opacity-70')} />
            <span className="hidden flex-1 truncate text-left lg:block">{label}</span>
            {count != null ? <span className={cn('absolute top-0 right-0 min-w-[18px] rounded-[6px] px-1 text-center text-[11px] font-bold lg:static lg:min-w-[22px] lg:px-1.5', alert ? 'bg-status-critical-bg text-status-critical' : 'bg-sidebar-accent text-sidebar-accent-foreground')}>{count}</span> : null}
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
function GroupRow({ icon: Icon, label, onClick }: { icon: LucideIcon; label: string; onClick: () => void }) {
    return (
        <button type="button" onClick={onClick} aria-label={label} title={label} className="flex h-9 w-full items-center justify-center gap-2.5 rounded-lg px-0 text-[13.5px] font-semibold text-sidebar-foreground outline-none hover:bg-sidebar-accent/60 focus-visible:ring-2 focus-visible:ring-sidebar-ring lg:justify-start lg:px-3">
            <Icon className="size-[17px] opacity-70" />
            <span className="hidden flex-1 text-left lg:block">{label}</span>
            <ChevronRight className="hidden size-3.5 opacity-60 lg:block" />
        </button>
    );
}
function Sidebar() {
    const s = useStore();
    const r = s.route;
    const frontline = isFrontline(r.persona);
    const outside = (what: string) => () => s.toast('info', `${what} — outside this preview.`);
    const HUBS: [string, string, string | null, string?][] = [
        ['Meds today', '', 'P01 / P07a'],
        ['MAR & medicines', '', 'P02 / P03', '/emar/mar'],
        ['Orders & reviews', '', 'P04 / P05'],
        ['Stock & controlled drugs', '', 'P06 / P07b'],
        ['Safety & oversight', '/emar/errors', null],
        ['Reports & audit', '/emar/reports', null],
        ['Settings', '/emar/settings', null],
    ];
    return (
        <aside aria-label="Main navigation" className="flex w-16 shrink-0 flex-col gap-1 overflow-y-auto bg-sidebar px-2 py-3 text-sidebar-foreground lg:w-[256px] lg:px-3">
            <NavRow icon={Sun} label="My Day" onClick={outside('My Day (P01)')} />
            <NavRow icon={Home} label="Overview" onClick={outside('Overview')} />
            <NavRow icon={CalendarDays} label="My Calendar" onClick={outside('My Calendar (P01)')} />
            <NavRow icon={ListTodo} label="All Tasks" onClick={outside('All Tasks')} />
            {frontline ? <NavRow icon={Pill} label="Meds today" onClick={outside('Meds today (P01)')} /> : null}
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
                    </div>
                    {HUBS.filter(([l]) => l !== 'Meds today' || !!CLOCKED[r.persona]).map(([label, path, pkg, activeOn]) => (
                        <a
                            key={label}
                            href={path ? hrefFor(path, path === '/emar/settings' ? { view: 'rules', sec: 'records' } : {}, r) : undefined}
                            onClick={path ? undefined : (e) => (e.preventDefault(), s.toast('info', `${label} is designed in ${pkg} — outside P09.`))}
                            role={path ? undefined : 'button'}
                            tabIndex={0}
                            className={cn('hidden h-[31px] items-center rounded-lg pr-3 pl-[38px] text-[13px] outline-none focus-visible:ring-2 focus-visible:ring-sidebar-ring lg:flex', (path && r.path.startsWith(path)) || (activeOn && r.path === activeOn) ? 'bg-sidebar-accent font-semibold text-sidebar-accent-foreground' : 'hover:bg-sidebar-accent/60')}
                        >
                            <span className="flex-1">{label}</span>
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

/* ───────────── app-wide banner (offline — P00 v5 / P01 wording, D7) ───────────── */
function AppWideBanners() {
    const s = useStore();
    if (s.route.scenario !== 'offline') return null;
    return (
        <div role="status" className="flex flex-wrap items-center gap-3 border-b border-status-warning/30 bg-status-warning-bg px-5 py-2 text-[13px] text-status-warning">
            <Save className="size-4 shrink-0" />
            <span className="flex-1 text-foreground">
                You’re offline. Reports, the audit trail and exports need a connection. Doses recorded on this device are kept and sent when you reconnect.
            </span>
        </div>
    );
}

/* ───────────── toasts (flash-toaster look: token pairs, from P01) ───────────── */
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
