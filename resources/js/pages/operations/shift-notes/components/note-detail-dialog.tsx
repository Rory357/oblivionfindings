/* Shift-note detail + edit pop-up. Read mode shows the full note, a permission
 * banner and a meta grid; edit mode (gated by the server edit-window policy)
 * writes type / body / flags / privacy via PUT and stamps the audit trail. */
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import {
    Check,
    Clock,
    Flag,
    Home,
    Info,
    Lock,
    PenLine,
    ShieldCheck,
    User,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

import {
    HueAvatar,
    NOTE_TYPES,
    type ShiftNote,
    TYPE_META,
    TypeBadge,
    clientName,
    fmtClock,
    fmtDateLong,
    fmtDayShort,
    fmtShiftChip,
    shiftRole,
    typeMeta,
} from './shared';

type CurrentUser = { id: number; name: string; is_manager: boolean };

const DAY_MS = 86400000;

function Switch({ on, onClick }: { on: boolean; onClick: () => void }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={on}
            onClick={onClick}
            className={cn(
                'relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors',
                on ? 'bg-primary' : 'bg-muted',
            )}
        >
            <span
                className={cn(
                    'inline-block h-4 w-4 transform rounded-full bg-background shadow transition-transform',
                    on ? 'translate-x-4' : 'translate-x-0.5',
                )}
            />
        </button>
    );
}

function Banner({
    tone,
    icon: Icon,
    children,
}: {
    tone: 'ok' | 'warn' | 'lock';
    icon: typeof Info;
    children: React.ReactNode;
}) {
    const cls =
        tone === 'ok'
            ? 'border-status-success/30 bg-status-success-bg text-status-success'
            : tone === 'warn'
              ? 'border-status-warning/30 bg-status-warning-bg text-status-warning'
              : 'border-status-critical/30 bg-status-critical-bg text-status-critical';
    return (
        <div
            className={cn(
                'flex items-start gap-2 rounded-xl border px-3.5 py-2.5 text-[12.5px] font-medium',
                cls,
            )}
        >
            <Icon className="mt-0.5 h-4 w-4 shrink-0" />
            <span className="leading-snug">{children}</span>
        </div>
    );
}

export function NoteDetailDialog({
    note,
    open,
    onOpenChange,
    currentUser,
    onFlag,
    onReview,
}: {
    note: ShiftNote | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    currentUser: CurrentUser;
    onFlag: (note: ShiftNote) => void;
    onReview: (note: ShiftNote) => void;
}) {
    const [editing, setEditing] = useState(false);
    const [saving, setSaving] = useState(false);
    const [draft, setDraft] = useState({
        type: 'shift_note',
        body: '',
        is_flagged: false,
        flagged_reason: '',
        is_private: false,
    });

    useEffect(() => {
        if (!note) return;
        setDraft({
            type: note.type,
            body: note.body,
            is_flagged: note.is_flagged,
            flagged_reason: note.flagged_reason ?? '',
            is_private: note.is_private,
        });
        setEditing(false);
    }, [note?.id]); // eslint-disable-line react-hooks/exhaustive-deps

    if (!note) return null;

    const meta = typeMeta(note.type);
    const author = note.user?.name ?? 'Unknown';
    const isManager = currentUser.is_manager;
    const created = note.created_at ? new Date(note.created_at) : new Date();
    const windowClose = new Date(created.getTime() + 7 * DAY_MS);
    const ageDays = Math.floor((Date.now() - created.getTime()) / DAY_MS);
    const windowOpen = ageDays < 7;
    const daysLeft = note.lock.days_left ?? Math.max(0, 7 - ageDays);

    const save = () => {
        setSaving(true);
        router.put(
            `/operations/shift-notes/${note.id}`,
            {
                type: draft.type,
                body: draft.body,
                is_flagged: draft.is_flagged,
                flagged_reason: draft.is_flagged
                    ? draft.flagged_reason || 'Flagged for review'
                    : null,
                is_private: draft.is_private,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setEditing(false);
                    toast.success('Shift note updated');
                },
                onError: () =>
                    toast.error(
                        'Could not save the note. Please review and retry.',
                    ),
                onFinish: () => setSaving(false),
            },
        );
    };

    // Permission banner — server lock is the source of truth; copy is computed
    // client-side for friendliness.
    let banner: {
        tone: 'ok' | 'warn' | 'lock';
        icon: typeof Info;
        text: string;
    };
    if (isManager) {
        banner = windowOpen
            ? {
                  tone: 'ok',
                  icon: ShieldCheck,
                  text: `You can edit as manager. ${author} can also edit until ${fmtDayShort(windowClose)}.`,
              }
            : {
                  tone: 'warn',
                  icon: ShieldCheck,
                  text: `${author}'s edit window closed ${fmtDayShort(windowClose)} — manager edit only. You can still make changes.`,
              };
    } else if (note.can_edit) {
        banner = {
            tone: 'ok',
            icon: PenLine,
            text: `You can edit this note for ${daysLeft} more ${daysLeft === 1 ? 'day' : 'days'} (until ${fmtDayShort(windowClose)}).`,
        };
    } else if (note.lock.reason === 'not_owner') {
        banner = {
            tone: 'lock',
            icon: Lock,
            text: `Only ${author} (the author) or a manager can edit this note.`,
        };
    } else {
        banner = {
            tone: 'lock',
            icon: Lock,
            text: `Your edit window closed on ${fmtDayShort(windowClose)}. Ask a manager to make changes.`,
        };
    }

    const editLabel =
        isManager && !windowOpen ? 'Edit as manager' : 'Edit note';

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[90vh] flex-col gap-0 overflow-hidden border-t-4 p-0 sm:max-w-[640px]">
                <DialogDescription className="sr-only">
                    Shift note detail and editor.
                </DialogDescription>
                {/* coloured top edge by type */}
                <div
                    className="absolute inset-x-0 top-0 h-1"
                    style={{ backgroundColor: meta.color }}
                    aria-hidden="true"
                />

                {/* Header */}
                <div className="flex items-start gap-3 border-b border-border px-5 py-4">
                    <HueAvatar name={author} size={44} />
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <DialogTitle className="text-base font-bold">
                                {author}
                            </DialogTitle>
                            {!editing ? <TypeBadge type={note.type} /> : null}
                            {note.is_private && !editing ? (
                                <span className="inline-flex items-center gap-1 rounded-md bg-muted px-1.5 py-0.5 text-[11px] font-semibold text-muted-foreground">
                                    <Lock className="h-3 w-3" />
                                    Private
                                </span>
                            ) : null}
                            {note.is_flagged && !editing ? (
                                <span className="inline-flex items-center gap-1 rounded-md bg-status-critical-bg px-1.5 py-0.5 text-[11px] font-semibold text-status-critical">
                                    <Flag className="h-3 w-3" />
                                    Flagged
                                </span>
                            ) : null}
                        </div>
                        <div className="mt-1 text-[11.5px] text-muted-foreground">
                            {shiftRole(note.shift)} ·{' '}
                            {fmtDateLong(note.created_at)},{' '}
                            {fmtClock(note.created_at)}
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={() => onOpenChange(false)}
                        aria-label="Close"
                        className="rounded-md p-1 text-muted-foreground hover:bg-accent hover:text-foreground"
                    >
                        <X className="h-4.5 w-4.5" />
                    </button>
                </div>

                {/* Body */}
                <div className="flex-1 space-y-4 overflow-y-auto px-5 py-4">
                    {/* chips */}
                    <div className="flex flex-wrap items-center gap-2">
                        {note.client ? (
                            <span className="inline-flex items-center gap-1.5 rounded-full bg-accent px-2.5 py-1 text-[11px] font-semibold text-primary">
                                <User className="h-3 w-3" />
                                {clientName(note.client)}
                            </span>
                        ) : null}
                        {note.shift ? (
                            <span className="inline-flex items-center gap-1.5 rounded-full border border-border bg-background px-2.5 py-1 text-[11px] font-medium">
                                <Clock className="h-3 w-3" />
                                {fmtShiftChip(note.shift)}
                            </span>
                        ) : null}
                        {note.site ? (
                            <span className="inline-flex items-center gap-1.5 rounded-full border border-border bg-background px-2.5 py-1 text-[11px] font-medium">
                                <Home className="h-3 w-3" />
                                {note.site.name}
                            </span>
                        ) : null}
                    </div>

                    {!editing ? (
                        <>
                            <p className="text-[14.5px] leading-relaxed whitespace-pre-wrap">
                                {note.body}
                            </p>
                            {note.edited_at ? (
                                <p className="flex items-center gap-1.5 text-[12px] text-muted-foreground italic">
                                    <PenLine className="h-3.5 w-3.5" />
                                    Edited by {note.editor?.name ??
                                        'Unknown'} ·{' '}
                                    {fmtDayShort(new Date(note.edited_at))}
                                </p>
                            ) : null}

                            <Banner tone={banner.tone} icon={banner.icon}>
                                {banner.text}
                            </Banner>

                            <div className="divide-y divide-border rounded-xl border border-border">
                                <MetaRow k="Status">
                                    {note.reviewed_at ? (
                                        <span className="inline-flex items-center rounded-full bg-status-success-bg px-2 py-0.5 text-[11px] font-semibold text-status-success">
                                            Reviewed by{' '}
                                            {note.reviewer?.name ?? 'Unknown'}
                                        </span>
                                    ) : (
                                        <span className="inline-flex items-center rounded-full bg-muted px-2 py-0.5 text-[11px] font-semibold text-muted-foreground">
                                            Awaiting review
                                        </span>
                                    )}
                                </MetaRow>
                                <MetaRow k="Logged">
                                    {fmtDateLong(note.created_at)},{' '}
                                    {fmtClock(note.created_at)}
                                </MetaRow>
                                <MetaRow k="Edit window">
                                    {windowOpen
                                        ? `Open until ${fmtDayShort(windowClose)}`
                                        : `Closed ${fmtDayShort(windowClose)} · manager only`}
                                </MetaRow>
                            </div>
                        </>
                    ) : (
                        <>
                            <div className="space-y-1.5">
                                <label className="text-[13px] font-semibold">
                                    Note type
                                </label>
                                <select
                                    className="h-10 w-full rounded-lg border border-input bg-background px-3 text-sm focus:border-ring focus:ring-2 focus:ring-ring/30 focus:outline-none"
                                    value={draft.type}
                                    onChange={(e) =>
                                        setDraft((d) => ({
                                            ...d,
                                            type: e.target.value,
                                        }))
                                    }
                                >
                                    {NOTE_TYPES.map((t) => (
                                        <option key={t} value={t}>
                                            {TYPE_META[t].label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="space-y-1.5">
                                <label className="text-[13px] font-semibold">
                                    Note
                                </label>
                                <textarea
                                    className="min-h-[160px] w-full rounded-lg border border-input bg-background px-3 py-2 text-sm leading-relaxed focus:border-ring focus:ring-2 focus:ring-ring/30 focus:outline-none"
                                    value={draft.body}
                                    onChange={(e) =>
                                        setDraft((d) => ({
                                            ...d,
                                            body: e.target.value,
                                        }))
                                    }
                                />
                            </div>
                            <ToggleRow
                                icon={
                                    <Flag className="h-3.5 w-3.5 text-status-critical" />
                                }
                                label="Flag for manager review"
                                on={draft.is_flagged}
                                onToggle={() =>
                                    setDraft((d) => ({
                                        ...d,
                                        is_flagged: !d.is_flagged,
                                    }))
                                }
                            />
                            {draft.is_flagged ? (
                                <div className="space-y-1.5">
                                    <label className="text-[13px] font-semibold">
                                        Reason for flag
                                    </label>
                                    <input
                                        className="h-10 w-full rounded-lg border border-input bg-background px-3 text-sm focus:border-ring focus:ring-2 focus:ring-ring/30 focus:outline-none"
                                        value={draft.flagged_reason}
                                        onChange={(e) =>
                                            setDraft((d) => ({
                                                ...d,
                                                flagged_reason: e.target.value,
                                            }))
                                        }
                                        placeholder="e.g. Needs sign-off before end of day"
                                    />
                                </div>
                            ) : null}
                            <ToggleRow
                                icon={<Lock className="h-3.5 w-3.5" />}
                                label="Private note"
                                on={draft.is_private}
                                onToggle={() =>
                                    setDraft((d) => ({
                                        ...d,
                                        is_private: !d.is_private,
                                    }))
                                }
                            />
                            <Banner tone="warn" icon={Info}>
                                Edits are logged in the audit trail with your
                                name and the time.
                                {isManager && !windowOpen
                                    ? ' You are editing as a manager after the author window closed.'
                                    : ''}
                            </Banner>
                        </>
                    )}
                </div>

                {/* Footer */}
                <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-5 py-3.5">
                    {!editing ? (
                        <>
                            <div className="flex items-center gap-2">
                                {!note.reviewed_at ? (
                                    <button
                                        type="button"
                                        onClick={() => onReview(note)}
                                        className="inline-flex items-center gap-1.5 rounded-lg border border-border bg-background px-3 py-2 text-xs font-semibold transition-colors hover:bg-accent"
                                    >
                                        <Check className="h-3.5 w-3.5" />
                                        Mark reviewed
                                    </button>
                                ) : null}
                                <button
                                    type="button"
                                    onClick={() => onFlag(note)}
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-border bg-background px-3 py-2 text-xs font-semibold transition-colors hover:bg-accent"
                                >
                                    <Flag className="h-3.5 w-3.5" />
                                    {note.is_flagged ? 'Unflag' : 'Flag'}
                                </button>
                            </div>
                            <div className="flex items-center gap-2">
                                <button
                                    type="button"
                                    onClick={() => onOpenChange(false)}
                                    className="rounded-lg border border-border bg-background px-3 py-2 text-xs font-semibold transition-colors hover:bg-accent"
                                >
                                    Close
                                </button>
                                {note.can_edit ? (
                                    <button
                                        type="button"
                                        onClick={() => setEditing(true)}
                                        className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary/90"
                                    >
                                        <PenLine className="h-3.5 w-3.5" />
                                        {editLabel}
                                    </button>
                                ) : (
                                    <button
                                        type="button"
                                        disabled
                                        title={banner.text}
                                        className="inline-flex cursor-not-allowed items-center gap-1.5 rounded-lg bg-muted px-3 py-2 text-xs font-semibold text-muted-foreground"
                                    >
                                        <Lock className="h-3.5 w-3.5" />
                                        Editing locked
                                    </button>
                                )}
                            </div>
                        </>
                    ) : (
                        <>
                            <div className="text-[12px] text-muted-foreground">
                                {isManager && !windowOpen
                                    ? 'Manager override'
                                    : `Editing as ${currentUser.name}`}
                            </div>
                            <div className="flex items-center gap-2">
                                <button
                                    type="button"
                                    onClick={() => setEditing(false)}
                                    className="rounded-lg border border-border bg-background px-3 py-2 text-xs font-semibold transition-colors hover:bg-accent"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    onClick={save}
                                    disabled={saving || !draft.body.trim()}
                                    className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3.5 py-2 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary/90 disabled:opacity-60"
                                >
                                    <Check className="h-3.5 w-3.5" />
                                    {saving ? 'Saving…' : 'Save changes'}
                                </button>
                            </div>
                        </>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}

function MetaRow({ k, children }: { k: string; children: React.ReactNode }) {
    return (
        <div className="flex items-center justify-between gap-3 px-3.5 py-2.5 text-[13px]">
            <span className="text-muted-foreground">{k}</span>
            <span className="text-right font-medium">{children}</span>
        </div>
    );
}

function ToggleRow({
    icon,
    label,
    on,
    onToggle,
}: {
    icon: React.ReactNode;
    label: string;
    on: boolean;
    onToggle: () => void;
}) {
    return (
        <div className="flex items-center justify-between gap-3 rounded-xl border border-border bg-card px-3.5 py-2.5">
            <span className="inline-flex items-center gap-2 text-[13px] font-medium">
                {icon}
                {label}
            </span>
            <Switch on={on} onClick={onToggle} />
        </div>
    );
}
