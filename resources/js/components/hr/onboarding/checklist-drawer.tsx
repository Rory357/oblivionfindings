/* eslint-disable no-restricted-syntax -- The quick-peek drawer is a bespoke
 * slide-in surface (fixed overlay + panel) matching the redesign prototype;
 * every colour is a semantic design token. */
import { Check, X } from 'lucide-react';

import { avatarStyle, initials } from './shared';

export interface DrawerTask {
    id: number;
    title: string;
    category: string;
    assignee: string | null;
    sign_off_required: boolean;
    is_completed: boolean;
    is_overdue: boolean;
}

export interface DrawerChecklist {
    id: number;
    name: string;
    role: string | null;
    site: string | null;
    pct: number;
    tasks: DrawerTask[];
}

export function ChecklistDrawer({
    open,
    data,
    loading,
    canManage,
    onClose,
    onToggleTask,
    onReassign,
    onReminder,
    onOpenFull,
}: {
    open: boolean;
    data: DrawerChecklist | null;
    loading: boolean;
    canManage: boolean;
    onClose: () => void;
    onToggleTask: (task: DrawerTask) => void;
    onReassign: () => void;
    onReminder: () => void;
    onOpenFull: () => void;
}) {
    if (!open) return null;

    const av = data ? avatarStyle(data.name) : { background: 'var(--muted)', color: 'var(--muted-foreground)' };

    return (
        <div
            className="fixed inset-0 z-40 bg-black/40 motion-safe:animate-in motion-safe:fade-in-0"
            onClick={onClose}
        >
            <div
                className="absolute top-0 right-0 flex h-full w-[440px] max-w-[92vw] flex-col bg-card shadow-2xl motion-safe:animate-in motion-safe:slide-in-from-right"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="border-b border-border px-5 py-4">
                    <div className="flex items-center justify-between">
                        <span className="text-[11px] font-bold tracking-[0.08em] text-muted-foreground uppercase">
                            Quick peek
                        </span>
                        <div className="flex items-center gap-1.5">
                            <button
                                type="button"
                                onClick={onOpenFull}
                                className="text-[12px] font-semibold text-primary hover:underline"
                            >
                                Open full page →
                            </button>
                            <button
                                type="button"
                                onClick={onClose}
                                aria-label="Close"
                                className="grid h-7 w-7 place-items-center rounded-lg bg-muted text-muted-foreground hover:bg-accent"
                            >
                                <X className="h-4 w-4" />
                            </button>
                        </div>
                    </div>

                    {data && (
                        <>
                            <div className="mt-3.5 flex items-center gap-3">
                                <span className="grid h-11 w-11 flex-none place-items-center rounded-full text-[15px] font-bold" style={av}>
                                    {initials(data.name)}
                                </span>
                                <div className="min-w-0">
                                    <div className="text-base font-bold">{data.name}</div>
                                    <div className="truncate text-xs text-muted-foreground">
                                        {[data.role, data.site].filter(Boolean).join(' · ') || '—'}
                                    </div>
                                </div>
                            </div>
                            <div className="mt-3.5 flex items-center gap-2.5">
                                <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                                    <div className="h-full rounded-full bg-primary" style={{ width: `${data.pct}%` }} />
                                </div>
                                <span className="text-xs font-bold">{data.pct}%</span>
                            </div>
                        </>
                    )}
                </div>

                <div className="flex-1 overflow-y-auto px-3 py-2.5">
                    {loading || !data ? (
                        <div className="space-y-2 px-2 py-3">
                            {Array.from({ length: 6 }).map((_, i) => (
                                <div key={i} className="h-10 animate-pulse rounded-lg bg-muted" />
                            ))}
                        </div>
                    ) : (
                        data.tasks.map((t) => (
                            <div key={t.id} className="flex items-center gap-3 rounded-[10px] px-2.5 py-2.5 hover:bg-muted">
                                <button
                                    type="button"
                                    disabled={!canManage}
                                    onClick={() => onToggleTask(t)}
                                    aria-label={t.is_completed ? 'Reopen task' : 'Complete task'}
                                    className={`grid h-5 w-5 flex-none place-items-center rounded-md border-[1.5px] ${
                                        t.is_completed
                                            ? 'border-primary bg-primary text-primary-foreground'
                                            : t.is_overdue
                                              ? 'border-status-critical'
                                              : 'border-border'
                                    } ${canManage ? 'cursor-pointer' : 'cursor-default'}`}
                                >
                                    {t.is_completed && <Check className="h-3 w-3" strokeWidth={3} />}
                                </button>
                                <div className="min-w-0 flex-1">
                                    <div
                                        className={`text-[12.5px] font-medium ${
                                            t.is_completed ? 'text-muted-foreground line-through' : ''
                                        }`}
                                    >
                                        {t.title}
                                    </div>
                                    <div className="truncate text-[10.5px] text-muted-foreground">
                                        {t.category} · {t.assignee ?? 'Unassigned'}
                                    </div>
                                </div>
                                {t.is_overdue && <span className="h-1.5 w-1.5 flex-none rounded-full bg-status-critical" />}
                            </div>
                        ))
                    )}
                </div>

                {canManage && (
                    <div className="flex gap-2 border-t border-border px-5 py-3.5">
                        <button
                            type="button"
                            onClick={onReminder}
                            className="flex-1 rounded-[9px] bg-muted py-2.5 text-[12.5px] font-semibold hover:bg-accent"
                        >
                            Send reminder
                        </button>
                        <button
                            type="button"
                            onClick={onReassign}
                            className="flex-1 rounded-[9px] bg-primary py-2.5 text-[12.5px] font-semibold text-primary-foreground hover:opacity-90"
                        >
                            Reassign owner
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}

export default ChecklistDrawer;
