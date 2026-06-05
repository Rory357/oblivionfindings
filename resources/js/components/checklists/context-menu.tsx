import { router } from '@inertiajs/react';
import {
    ArrowLeft,
    CalendarClock,
    Check,
    Eye,
    Loader2,
    type LucideIcon,
    Play,
    PlayCircle,
    Search,
    SkipForward,
    UserMinus,
    UserRound,
} from 'lucide-react';
import { type ReactNode, useEffect, useLayoutEffect, useRef, useState } from 'react';

import { cn } from '@/lib/utils';

import { initials } from './category';
import { useChecklistConfig } from './context';
import type { ChecklistRun } from './types';

const MENU_W = 240;

/* ---- Floating menu shell (right-click context menu) ----------------------
 * Mechanics mirror the site-calendar QuickAddMenu: fixed at the cursor, then
 * repositioned to stay inside the viewport; closes on outside-click, Esc,
 * scroll or resize; arrow keys rove between menu items. */
function FloatingMenu({ x, y, onClose, children }: { x: number; y: number; onClose: () => void; children: ReactNode }) {
    const ref = useRef<HTMLDivElement>(null);
    const [pos, setPos] = useState<{ left: number; top: number }>({ left: x, top: y });

    useLayoutEffect(() => {
        const el = ref.current;
        if (!el) return;
        const r = el.getBoundingClientRect();
        let left = x;
        let top = y;
        if (left + r.width > window.innerWidth - 8) left = Math.max(8, window.innerWidth - r.width - 8);
        if (top + r.height > window.innerHeight - 8) top = Math.max(8, window.innerHeight - r.height - 8);
        setPos({ left, top });
    }, [x, y]);

    useEffect(() => {
        const onDoc = (e: MouseEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node)) onClose();
        };
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                onClose();
                return;
            }
            if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
            const items = Array.from(ref.current?.querySelectorAll<HTMLElement>('[role="menuitem"]:not([disabled])') ?? []);
            if (items.length === 0) return;
            e.preventDefault();
            const idx = items.indexOf(document.activeElement as HTMLElement);
            const next = e.key === 'ArrowDown' ? (idx + 1) % items.length : (idx - 1 + items.length) % items.length;
            items[next]?.focus();
        };
        const onScroll = () => onClose();
        document.addEventListener('mousedown', onDoc);
        document.addEventListener('keydown', onKey);
        window.addEventListener('scroll', onScroll, true);
        window.addEventListener('resize', onScroll, true);
        return () => {
            document.removeEventListener('mousedown', onDoc);
            document.removeEventListener('keydown', onKey);
            window.removeEventListener('scroll', onScroll, true);
            window.removeEventListener('resize', onScroll, true);
        };
    }, [onClose]);

    return (
        <div
            ref={ref}
            role="menu"
            className="fixed z-[60] overflow-hidden rounded-xl border border-border bg-popover p-1 text-popover-foreground shadow-xl ring-1 ring-black/5"
            style={{ left: pos.left, top: pos.top, width: MENU_W }}
            onContextMenu={(e) => e.preventDefault()}
        >
            {children}
        </div>
    );
}

function MenuItem({
    Icon,
    label,
    onClick,
    tone = 'default',
    autoFocus,
}: {
    Icon: LucideIcon;
    label: string;
    onClick: () => void;
    tone?: 'default' | 'critical';
    autoFocus?: boolean;
}) {
    return (
        <button
            type="button"
            role="menuitem"
            autoFocus={autoFocus}
            onClick={onClick}
            className={cn(
                'flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-sm outline-none transition-colors focus:bg-accent',
                tone === 'critical'
                    ? 'text-status-critical hover:bg-status-critical-bg focus:bg-status-critical-bg'
                    : 'hover:bg-accent',
            )}
        >
            <Icon className="h-4 w-4 shrink-0" />
            <span className="flex-1 truncate font-medium">{label}</span>
        </button>
    );
}

function SubHeader({ onBack, title }: { onBack: () => void; title: string }) {
    return (
        <div className="mb-1 flex items-center gap-2 px-1.5 py-1">
            <button
                type="button"
                onClick={onBack}
                className="flex h-6 w-6 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                aria-label="Back"
            >
                <ArrowLeft className="h-3.5 w-3.5" />
            </button>
            <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{title}</span>
        </div>
    );
}

type Mode = 'menu' | 'reschedule' | 'reassign' | 'skip';

function RunContextMenu({ run, x, y, onClose }: { run: ChecklistRun; x: number; y: number; onClose: () => void }) {
    const { can, openRun, assignableUsers } = useChecklistConfig();
    const [mode, setMode] = useState<Mode>('menu');
    const [date, setDate] = useState(run.scheduled_date ?? '');
    const [q, setQ] = useState('');
    const [busy, setBusy] = useState(false);

    const completed = run.status === 'completed';
    const skipped = run.status === 'skipped';
    const primary = completed || !can.run
        ? { label: 'View run', Icon: Eye }
        : run.status === 'in_progress'
          ? { label: 'Continue run', Icon: PlayCircle }
          : { label: 'Start run', Icon: Play };

    const opts = { preserveScroll: true, onSuccess: onClose, onFinish: () => setBusy(false) };
    const run_ = () => {
        openRun(run.id);
        onClose();
    };
    const reschedule = () => {
        if (!date) return;
        setBusy(true);
        router.patch(`/checklists/runs/${run.id}/schedule`, { scheduled_date: date }, opts);
    };
    const reassign = (userId: number | null) => {
        setBusy(true);
        router.patch(`/checklists/runs/${run.id}/assign`, { assigned_to_user_id: userId }, opts);
    };
    const skip = () => {
        setBusy(true);
        router.post(`/checklists/runs/${run.id}/skip`, {}, opts);
    };

    const filtered = q
        ? assignableUsers.filter((u) => u.name.toLowerCase().includes(q.toLowerCase()))
        : assignableUsers;

    const showSchedule = can.schedule && !completed;
    const showSkip = can.run && !completed && !skipped;

    return (
        <FloatingMenu x={x} y={y} onClose={onClose}>
            {mode === 'menu' ? (
                <>
                    <div className="truncate px-2.5 pb-1 pt-1 text-xs font-semibold text-muted-foreground">
                        {run.template?.name ?? 'Checklist run'}
                    </div>
                    <MenuItem Icon={primary.Icon} label={primary.label} onClick={run_} autoFocus />
                    {showSchedule || showSkip ? <div className="my-1 h-px bg-border" /> : null}
                    {showSchedule ? (
                        <MenuItem Icon={CalendarClock} label="Reschedule" onClick={() => setMode('reschedule')} />
                    ) : null}
                    {showSchedule ? (
                        <MenuItem Icon={UserRound} label="Reassign" onClick={() => setMode('reassign')} />
                    ) : null}
                    {showSkip ? (
                        <MenuItem Icon={SkipForward} label="Skip this run" tone="critical" onClick={() => setMode('skip')} />
                    ) : null}
                </>
            ) : null}

            {mode === 'reschedule' ? (
                <div className="p-1.5">
                    <SubHeader onBack={() => setMode('menu')} title="Reschedule" />
                    <input
                        type="date"
                        value={date}
                        autoFocus
                        onChange={(e) => setDate(e.target.value)}
                        className="h-9 w-full rounded-md border border-input bg-background px-2.5 text-sm outline-none focus:border-ring focus:ring-2 focus:ring-ring/30"
                    />
                    <button
                        type="button"
                        disabled={busy || !date}
                        onClick={reschedule}
                        className="mt-2 flex h-9 w-full items-center justify-center gap-1.5 rounded-md bg-primary text-sm font-medium text-primary-foreground transition hover:bg-primary/90 disabled:opacity-50"
                    >
                        {busy ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Check className="h-3.5 w-3.5" />}
                        Move run
                    </button>
                </div>
            ) : null}

            {mode === 'reassign' ? (
                <div className="p-1.5">
                    <SubHeader onBack={() => setMode('menu')} title="Reassign" />
                    <div className="relative">
                        <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                        <input
                            value={q}
                            autoFocus
                            onChange={(e) => setQ(e.target.value)}
                            placeholder="Search staff…"
                            className="h-9 w-full rounded-md border border-input bg-background pl-8 pr-2 text-sm outline-none focus:border-ring focus:ring-2 focus:ring-ring/30"
                        />
                    </div>
                    <div className="mt-1.5 max-h-52 overflow-y-auto">
                        <button
                            type="button"
                            disabled={busy}
                            onClick={() => reassign(null)}
                            className="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm text-muted-foreground transition hover:bg-accent disabled:opacity-50"
                        >
                            <span className="flex h-6 w-6 items-center justify-center rounded-full bg-muted">
                                <UserMinus className="h-3.5 w-3.5" />
                            </span>
                            Unassign
                        </button>
                        {filtered.length === 0 ? (
                            <div className="px-2 py-4 text-center text-xs text-muted-foreground">No staff found</div>
                        ) : (
                            filtered.map((u) => {
                                const active = run.assigned_to_id === u.id;
                                return (
                                    <button
                                        key={u.id}
                                        type="button"
                                        disabled={busy}
                                        onClick={() => reassign(u.id)}
                                        className={cn(
                                            'flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm transition hover:bg-accent disabled:opacity-50',
                                            active && 'bg-accent/60',
                                        )}
                                    >
                                        <span className="flex h-6 w-6 items-center justify-center rounded-full bg-muted text-[9px] font-semibold">
                                            {initials(u.name)}
                                        </span>
                                        <span className="flex-1 truncate">{u.name}</span>
                                        {active ? <Check className="h-3.5 w-3.5 shrink-0 text-primary" /> : null}
                                    </button>
                                );
                            })
                        )}
                    </div>
                </div>
            ) : null}

            {mode === 'skip' ? (
                <div className="p-2">
                    <SubHeader onBack={() => setMode('menu')} title="Skip run" />
                    <p className="px-1 text-xs text-muted-foreground">
                        Mark this run as skipped for this cycle? It will stop counting as due or overdue.
                    </p>
                    <button
                        type="button"
                        disabled={busy}
                        onClick={skip}
                        className="mt-2 flex h-9 w-full items-center justify-center gap-1.5 rounded-md bg-status-critical text-sm font-medium text-white transition hover:opacity-90 disabled:opacity-50"
                    >
                        {busy ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <SkipForward className="h-3.5 w-3.5" />}
                        Skip run
                    </button>
                </div>
            ) : null}
        </FloatingMenu>
    );
}

/**
 * Hook for wiring a right-click context menu onto run chips/cards. Returns an
 * `open(run)` handler factory for `onContextMenu`, and the `element` to render
 * once in the same component.
 */
export function useRunContextMenu() {
    const [state, setState] = useState<{ run: ChecklistRun; x: number; y: number } | null>(null);

    const open = (run: ChecklistRun) => (e: React.MouseEvent) => {
        e.preventDefault();
        setState({ run, x: e.clientX, y: e.clientY });
    };

    const element = state ? (
        <RunContextMenu run={state.run} x={state.x} y={state.y} onClose={() => setState(null)} />
    ) : null;

    return { open, element };
}
