/* eslint-disable no-restricted-syntax -- The shift-note wizard mirrors the bespoke
 * Add-client modal surface (stepper rail + scroll-contained body + custom footer)
 * and intentionally uses styled native controls. Every colour is a semantic
 * design token, per docs/DESIGN_TOKENS.md. */
/* Add Shift Note wizard — 5-step stepper modal modelled on the Add Client /
 * handover wizard shell. Step 1 links the note to a real client shift (so it is
 * correctly filed against the roster); the rest mirror the design prototype. */
import { startOfWeek } from '@/components/rostering';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import {
    CalendarRange,
    Check,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Clock,
    Flag,
    ListChecks,
    Loader2,
    Lock,
    NotebookPen,
    PenLine,
    ShieldCheck,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

import { StepHead } from '@/components/wizard/primitives';
import { formatDate } from '@/lib/datetime';
import {
    type Catalogue,
    type CatalogueShift,
    NOTE_TYPES,
    type NoteType,
    TYPE_META,
    TypeBadge,
    clientName,
    fmtClock,
} from './shared';

export type WizardInitial = {
    client_id?: number | null;
    shift_id?: number | null;
};

const WZ_STEPS = [
    {
        key: 'basics',
        label: 'Shift & person',
        blurb: 'Who & when',
        icon: CalendarRange,
    },
    {
        key: 'type',
        label: 'Note type',
        blurb: 'Categorise it',
        icon: ListChecks,
    },
    { key: 'details', label: 'Details', blurb: 'What happened', icon: PenLine },
    {
        key: 'flags',
        label: 'Flags & privacy',
        blurb: 'Review & access',
        icon: ShieldCheck,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm & save',
        icon: CheckCircle2,
    },
] as const;

const SELECT_CLASS =
    'h-10 w-full rounded-lg border border-input bg-background px-3 text-sm text-foreground transition-colors focus:border-ring focus:outline-none focus:ring-2 focus:ring-ring/30 disabled:cursor-not-allowed disabled:opacity-60';

type WizForm = {
    client_id: string;
    shift_id: string;
    type: NoteType;
    body: string;
    flagged: boolean;
    flagged_reason: string;
    priv: boolean;
};

function shiftOptionLabel(s: CatalogueShift): string {
    if (!s.starts_at) return s.label;
    return `${formatDate(s.starts_at)} · ${fmtClock(s.starts_at)}–${fmtClock(s.ends_at)}`;
}

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

export function NoteWizard({
    open,
    onOpenChange,
    initial,
    catalogue,
    onCreated,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    initial: WizardInitial | null;
    catalogue: Catalogue;
    onCreated: (weekStart: Date) => void;
}) {
    const [stepIndex, setStepIndex] = useState(0);
    const [saving, setSaving] = useState(false);
    const [done, setDone] = useState(false);
    const [f, setF] = useState<WizForm>({
        client_id: '',
        shift_id: '',
        type: 'shift_note',
        body: '',
        flagged: false,
        flagged_reason: '',
        priv: false,
    });

    useEffect(() => {
        if (!open) return;
        setStepIndex(0);
        setSaving(false);
        setDone(false);
        setF({
            client_id: initial?.client_id ? String(initial.client_id) : '',
            shift_id: initial?.shift_id ? String(initial.shift_id) : '',
            type: 'shift_note',
            body: '',
            flagged: false,
            flagged_reason: '',
            priv: false,
        });
    }, [open, initial?.client_id, initial?.shift_id]);

    const set = <K extends keyof WizForm>(k: K, v: WizForm[K]) =>
        setF((p) => ({ ...p, [k]: v }));

    const cur = WZ_STEPS[stepIndex];
    const client = catalogue.clients.find((c) => String(c.id) === f.client_id);
    const clientShifts = useMemo(
        () =>
            catalogue.shifts
                .filter(
                    (s) => String(s.client_id) === f.client_id && s.starts_at,
                )
                .sort(
                    (a, b) =>
                        new Date(a.starts_at!).getTime() -
                        new Date(b.starts_at!).getTime(),
                ),
        [catalogue.shifts, f.client_id],
    );
    const shift = catalogue.shifts.find((s) => String(s.id) === f.shift_id);

    const pct = useMemo(() => {
        let s = 0;
        if (f.client_id) s += 25;
        if (f.shift_id) s += 15;
        if (f.type) s += 15;
        if (f.body.trim().length > 20) s += 35;
        else if (f.body.trim()) s += 15;
        if (!f.flagged || f.flagged_reason.trim()) s += 10;
        return Math.min(100, s);
    }, [f]);

    const canContinue = () => {
        if (cur.key === 'basics') return !!f.client_id && !!f.shift_id;
        if (cur.key === 'details') return f.body.trim().length > 0;
        if (cur.key === 'flags')
            return !f.flagged || f.flagged_reason.trim().length > 0;
        return true;
    };

    const next = () =>
        setStepIndex((i) => Math.min(i + 1, WZ_STEPS.length - 1));
    const back = () => setStepIndex((i) => Math.max(0, i - 1));

    const submit = () => {
        setSaving(true);
        router.post(
            '/operations/shift-notes',
            {
                shift_id: Number(f.shift_id),
                type: f.type,
                body: f.body,
                is_flagged: f.flagged,
                flagged_reason: f.flagged
                    ? f.flagged_reason || 'Flagged for review'
                    : null,
                is_private: f.priv,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => setDone(true),
                onError: () =>
                    toast.error(
                        'Could not save the note. Please review and retry.',
                    ),
                onFinish: () => setSaving(false),
            },
        );
    };

    const targetWeek = startOfWeek(
        shift?.starts_at ? new Date(shift.starts_at) : new Date(),
    );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex h-[min(800px,92vh)] max-w-[min(96vw,1080px)] flex-col gap-0 overflow-hidden p-0 sm:max-w-[min(96vw,1080px)] md:flex-row [&>button]:hidden">
                <DialogTitle className="sr-only">Add shift note</DialogTitle>
                <DialogDescription className="sr-only">
                    A guided wizard to document a shift.
                </DialogDescription>

                {done ? (
                    <SuccessPane
                        type={f.type}
                        clientLabel={
                            client ? clientName(client) : 'this person'
                        }
                        onClose={() => onOpenChange(false)}
                        onView={() => {
                            onCreated(targetWeek);
                            onOpenChange(false);
                        }}
                    />
                ) : (
                    <>
                        {/* Stepper rail */}
                        <aside className="hidden w-[248px] shrink-0 flex-col border-r border-sidebar-border bg-sidebar p-4 md:flex">
                            <div className="mb-4 flex items-center gap-2.5">
                                <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-primary/15 text-primary">
                                    <NotebookPen className="h-4.5 w-4.5" />
                                </span>
                                <div className="min-w-0">
                                    <div className="text-sm font-bold">
                                        Add shift note
                                    </div>
                                    <div className="truncate text-[11.5px] text-muted-foreground">
                                        New documentation entry
                                    </div>
                                </div>
                            </div>
                            <div className="flex flex-1 flex-col gap-1">
                                {WZ_STEPS.map((s, i) => {
                                    const Icon = s.icon;
                                    const active = i === stepIndex;
                                    const isDone = i < stepIndex;
                                    return (
                                        <button
                                            key={s.key}
                                            type="button"
                                            onClick={() => setStepIndex(i)}
                                            className={cn(
                                                'flex items-start gap-2.5 rounded-lg px-2.5 py-2 text-left transition-colors',
                                                active
                                                    ? 'bg-primary/10'
                                                    : 'hover:bg-accent',
                                            )}
                                        >
                                            <span
                                                className={cn(
                                                    'flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-bold',
                                                    active
                                                        ? 'bg-primary text-primary-foreground'
                                                        : isDone
                                                          ? 'bg-status-success-bg text-status-success'
                                                          : 'bg-muted text-muted-foreground',
                                                )}
                                            >
                                                {isDone ? (
                                                    <Check className="h-3.5 w-3.5" />
                                                ) : (
                                                    <Icon className="h-3.5 w-3.5" />
                                                )}
                                            </span>
                                            <span className="min-w-0">
                                                <span className="block text-[13px] leading-tight font-semibold">
                                                    {s.label}
                                                </span>
                                                <span className="block text-[11px] text-muted-foreground">
                                                    {s.blurb}
                                                </span>
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>
                            <div className="mt-3 rounded-lg border border-border bg-card p-3">
                                <div className="flex items-center justify-between text-[11.5px] font-semibold">
                                    <span>Note completeness</span>
                                    <span className="tabular-nums">{pct}%</span>
                                </div>
                                <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-muted">
                                    <div
                                        className="h-full rounded-full bg-primary transition-all"
                                        style={{ width: `${pct}%` }}
                                    />
                                </div>
                            </div>
                        </aside>

                        {/* Main panel */}
                        <div className="flex min-w-0 flex-1 flex-col">
                            <header className="flex items-center justify-between border-b border-border px-5 py-3">
                                <div className="text-[12.5px] text-muted-foreground">
                                    Step {stepIndex + 1} of {WZ_STEPS.length} ·{' '}
                                    <b className="text-foreground">
                                        {cur.label}
                                    </b>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => onOpenChange(false)}
                                    aria-label="Close"
                                    className="rounded-md p-1 text-muted-foreground hover:bg-accent hover:text-foreground"
                                >
                                    <X className="h-4.5 w-4.5" />
                                </button>
                            </header>
                            <div className="h-[3px] shrink-0 bg-muted">
                                <div
                                    className="h-full bg-primary transition-all"
                                    style={{
                                        width: `${((stepIndex + 1) / WZ_STEPS.length) * 100}%`,
                                    }}
                                />
                            </div>

                            <div className="flex-1 overflow-y-auto px-5 py-5">
                                {cur.key === 'basics' ? (
                                    <div className="space-y-4">
                                        <StepHead
                                            icon={CalendarRange}
                                            title="Which shift is this note for?"
                                            blurb="Link the note to the person and the shift it belongs to. This keeps the audit trail and coverage stats accurate."
                                        />
                                        <div className="space-y-1.5">
                                            <label className="text-[13px] font-semibold">
                                                Person{' '}
                                                <span className="text-status-critical">
                                                    *
                                                </span>
                                            </label>
                                            <select
                                                className={SELECT_CLASS}
                                                value={f.client_id}
                                                onChange={(e) =>
                                                    setF((p) => ({
                                                        ...p,
                                                        client_id:
                                                            e.target.value,
                                                        shift_id: '',
                                                    }))
                                                }
                                            >
                                                <option value="">
                                                    Select a person…
                                                </option>
                                                {catalogue.clients.map((c) => (
                                                    <option
                                                        key={c.id}
                                                        value={c.id}
                                                    >
                                                        {clientName(c)}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <div className="space-y-1.5">
                                            <label className="text-[13px] font-semibold">
                                                Shift{' '}
                                                <span className="text-status-critical">
                                                    *
                                                </span>
                                            </label>
                                            <select
                                                className={SELECT_CLASS}
                                                value={f.shift_id}
                                                disabled={!f.client_id}
                                                onChange={(e) =>
                                                    set(
                                                        'shift_id',
                                                        e.target.value,
                                                    )
                                                }
                                            >
                                                <option value="">
                                                    {f.client_id
                                                        ? clientShifts.length
                                                            ? 'Select a shift…'
                                                            : 'No recent shifts for this person'
                                                        : 'Choose a person first'}
                                                </option>
                                                {clientShifts.map((s) => (
                                                    <option
                                                        key={s.id}
                                                        value={s.id}
                                                    >
                                                        {shiftOptionLabel(s)}
                                                        {s.staff
                                                            ? ` · ${s.staff.name}`
                                                            : ''}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        {shift ? (
                                            <div className="flex flex-wrap items-center gap-2 rounded-xl border border-border bg-muted/30 px-3.5 py-2.5 text-[12px]">
                                                <span className="inline-flex items-center gap-1.5 rounded-full bg-card px-2.5 py-1 font-semibold">
                                                    <Clock className="h-3.5 w-3.5 text-muted-foreground" />
                                                    {shiftOptionLabel(shift)}
                                                </span>
                                            </div>
                                        ) : null}
                                        <p className="text-[12px] text-muted-foreground">
                                            Tip: open this from a “No notes”
                                            coverage gap and the shift is filled
                                            in for you.
                                        </p>
                                    </div>
                                ) : null}

                                {cur.key === 'type' ? (
                                    <div className="space-y-4">
                                        <StepHead
                                            icon={ListChecks}
                                            title="What kind of note is this?"
                                            blurb="The type sets the colour, where it surfaces, and whether it routes to a review queue."
                                        />
                                        <div className="grid gap-2.5 sm:grid-cols-2">
                                            {NOTE_TYPES.map((t) => {
                                                const m = TYPE_META[t];
                                                const Icon = m.icon;
                                                const sel = f.type === t;
                                                return (
                                                    <button
                                                        key={t}
                                                        type="button"
                                                        onClick={() =>
                                                            set('type', t)
                                                        }
                                                        className={cn(
                                                            'flex items-start gap-3 rounded-xl border p-3 text-left transition-colors',
                                                            sel
                                                                ? 'border-primary bg-accent'
                                                                : 'border-border bg-background hover:bg-accent',
                                                        )}
                                                    >
                                                        <span
                                                            className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-white"
                                                            style={{
                                                                backgroundColor:
                                                                    m.color,
                                                            }}
                                                        >
                                                            <Icon className="h-4 w-4" />
                                                        </span>
                                                        <span className="min-w-0">
                                                            <span className="block text-[13px] font-bold">
                                                                {m.label}
                                                            </span>
                                                            <span className="block text-[11.5px] text-muted-foreground">
                                                                {m.desc}
                                                            </span>
                                                        </span>
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </div>
                                ) : null}

                                {cur.key === 'details' ? (
                                    <div className="space-y-4">
                                        <StepHead
                                            icon={PenLine}
                                            title="What happened on the shift?"
                                            blurb="Write a clear, factual account. Note observations, actions taken, and anything the next worker needs to know."
                                        />
                                        <div className="space-y-1.5">
                                            <label className="text-[13px] font-semibold">
                                                Note{' '}
                                                <span className="text-status-critical">
                                                    *
                                                </span>
                                            </label>
                                            <textarea
                                                className="min-h-[200px] w-full rounded-lg border border-input bg-background px-3 py-2 text-sm leading-relaxed focus:border-ring focus:ring-2 focus:ring-ring/30 focus:outline-none"
                                                placeholder="e.g. Aroha had a settled morning. Breakfast and meds taken without issue…"
                                                value={f.body}
                                                onChange={(e) =>
                                                    set('body', e.target.value)
                                                }
                                            />
                                            <p className="text-[12px] text-muted-foreground tabular-nums">
                                                {f.body.trim().length}{' '}
                                                characters · be objective and
                                                specific.
                                            </p>
                                        </div>
                                    </div>
                                ) : null}

                                {cur.key === 'flags' ? (
                                    <div className="space-y-4">
                                        <StepHead
                                            icon={ShieldCheck}
                                            title="Flags & visibility"
                                            blurb="Decide whether this note needs a manager's eyes and who is allowed to see it."
                                        />
                                        <div className="flex items-start justify-between gap-3 rounded-xl border border-border bg-card px-3.5 py-3">
                                            <div>
                                                <div className="inline-flex items-center gap-2 text-[13px] font-semibold">
                                                    <Flag className="h-3.5 w-3.5 text-status-critical" />
                                                    Flag for manager review
                                                </div>
                                                <div className="mt-0.5 text-[12px] text-muted-foreground">
                                                    Surfaces in the review queue
                                                    and on the week's flag
                                                    count.
                                                </div>
                                            </div>
                                            <Switch
                                                on={f.flagged}
                                                onClick={() =>
                                                    set('flagged', !f.flagged)
                                                }
                                            />
                                        </div>
                                        {f.flagged ? (
                                            <div className="space-y-1.5">
                                                <label className="text-[13px] font-semibold">
                                                    Reason for flag{' '}
                                                    <span className="text-status-critical">
                                                        *
                                                    </span>
                                                </label>
                                                <input
                                                    className={SELECT_CLASS}
                                                    placeholder="e.g. Needs sign-off before end of day"
                                                    value={f.flagged_reason}
                                                    onChange={(e) =>
                                                        set(
                                                            'flagged_reason',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </div>
                                        ) : null}
                                        <div className="flex items-start justify-between gap-3 rounded-xl border border-border bg-card px-3.5 py-3">
                                            <div>
                                                <div className="inline-flex items-center gap-2 text-[13px] font-semibold">
                                                    <Lock className="h-3.5 w-3.5" />
                                                    Private note
                                                </div>
                                                <div className="mt-0.5 text-[12px] text-muted-foreground">
                                                    Only visible to managers and
                                                    senior staff — hidden from
                                                    the family portal.
                                                </div>
                                            </div>
                                            <Switch
                                                on={f.priv}
                                                onClick={() =>
                                                    set('priv', !f.priv)
                                                }
                                            />
                                        </div>
                                    </div>
                                ) : null}

                                {cur.key === 'review' ? (
                                    <div className="space-y-4">
                                        <StepHead
                                            icon={CheckCircle2}
                                            title="Review & save"
                                            blurb="Check the details below, then save the note to the record."
                                        />
                                        <div className="rounded-xl border border-border bg-card">
                                            <ReviewLine
                                                k="Person"
                                                v={
                                                    client
                                                        ? clientName(client)
                                                        : '—'
                                                }
                                                onEdit={() => setStepIndex(0)}
                                            />
                                            <ReviewLine
                                                k="Shift"
                                                v={
                                                    shift
                                                        ? shiftOptionLabel(
                                                              shift,
                                                          )
                                                        : '—'
                                                }
                                                onEdit={() => setStepIndex(0)}
                                            />
                                            <ReviewLine
                                                k="Type"
                                                v={<TypeBadge type={f.type} />}
                                                onEdit={() => setStepIndex(1)}
                                            />
                                            <ReviewLine
                                                k="Note"
                                                v={
                                                    f.body ? (
                                                        <span className="line-clamp-3">
                                                            {f.body}
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            No content
                                                        </span>
                                                    )
                                                }
                                                onEdit={() => setStepIndex(2)}
                                            />
                                            <ReviewLine
                                                k="Flags"
                                                v={
                                                    <span className="flex flex-wrap gap-1.5">
                                                        {f.flagged ? (
                                                            <span className="inline-flex items-center gap-1 rounded-md bg-status-critical-bg px-1.5 py-0.5 text-[11px] font-semibold text-status-critical">
                                                                <Flag className="h-3 w-3" />
                                                                Flagged
                                                            </span>
                                                        ) : null}
                                                        {f.priv ? (
                                                            <span className="inline-flex items-center gap-1 rounded-md bg-muted px-1.5 py-0.5 text-[11px] font-semibold text-muted-foreground">
                                                                <Lock className="h-3 w-3" />
                                                                Private
                                                            </span>
                                                        ) : null}
                                                        {!f.flagged &&
                                                        !f.priv ? (
                                                            <span className="text-[13px] text-muted-foreground">
                                                                None
                                                            </span>
                                                        ) : null}
                                                    </span>
                                                }
                                                onEdit={() => setStepIndex(3)}
                                                last
                                            />
                                        </div>
                                    </div>
                                ) : null}
                            </div>

                            {/* Footer */}
                            <footer className="flex items-center justify-between gap-2 border-t border-border bg-muted/30 px-5 py-3.5">
                                <div>
                                    {stepIndex > 0 ? (
                                        <button
                                            type="button"
                                            onClick={back}
                                            className="inline-flex items-center gap-1 rounded-lg px-3 py-2 text-xs font-semibold text-muted-foreground hover:bg-accent hover:text-foreground"
                                        >
                                            <ChevronLeft className="h-4 w-4" />
                                            Back
                                        </button>
                                    ) : null}
                                </div>
                                <div className="flex items-center gap-2">
                                    <button
                                        type="button"
                                        onClick={() => onOpenChange(false)}
                                        className="rounded-lg border border-border bg-background px-3 py-2 text-xs font-semibold transition-colors hover:bg-accent"
                                    >
                                        Cancel
                                    </button>
                                    {cur.key === 'review' ? (
                                        <button
                                            type="button"
                                            onClick={submit}
                                            disabled={saving || pct < 50}
                                            className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3.5 py-2 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary/90 disabled:opacity-60"
                                        >
                                            {saving ? (
                                                <>
                                                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                                    Saving…
                                                </>
                                            ) : (
                                                <>
                                                    <Check className="h-3.5 w-3.5" />
                                                    Save note
                                                </>
                                            )}
                                        </button>
                                    ) : (
                                        <button
                                            type="button"
                                            onClick={next}
                                            disabled={!canContinue()}
                                            className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3.5 py-2 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary/90 disabled:opacity-60"
                                        >
                                            Continue
                                            <ChevronRight className="h-4 w-4" />
                                        </button>
                                    )}
                                </div>
                            </footer>
                        </div>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}

function ReviewLine({
    k,
    v,
    onEdit,
    last,
}: {
    k: string;
    v: React.ReactNode;
    onEdit: () => void;
    last?: boolean;
}) {
    return (
        <div
            className={cn(
                'flex items-start gap-3 px-3.5 py-2.5',
                !last && 'border-b border-border',
            )}
        >
            <span className="w-20 shrink-0 text-[12.5px] font-semibold text-muted-foreground">
                {k}
            </span>
            <span className="min-w-0 flex-1 text-[13px]">{v}</span>
            <button
                type="button"
                onClick={onEdit}
                className="shrink-0 text-[12px] font-semibold text-primary hover:underline"
            >
                Edit
            </button>
        </div>
    );
}

function SuccessPane({
    type,
    clientLabel,
    onClose,
    onView,
}: {
    type: NoteType;
    clientLabel: string;
    onClose: () => void;
    onView: () => void;
}) {
    return (
        <div className="flex flex-1 flex-col items-center justify-center px-8 py-12 text-center">
            <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-status-success-bg text-status-success">
                <CheckCircle2 className="h-9 w-9" />
            </div>
            <h2 className="text-lg font-bold">Shift note saved</h2>
            <p className="mt-1 max-w-sm text-sm text-muted-foreground">
                Your {TYPE_META[type].label.toLowerCase()} for {clientLabel} has
                been added to the week and the audit trail.
            </p>
            <div className="mt-5 flex items-center gap-2">
                <button
                    type="button"
                    onClick={onClose}
                    className="rounded-lg border border-border bg-background px-3.5 py-2 text-xs font-semibold transition-colors hover:bg-accent"
                >
                    Close
                </button>
                <button
                    type="button"
                    onClick={onView}
                    className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3.5 py-2 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary/90"
                >
                    <ListChecks className="h-3.5 w-3.5" />
                    View in week
                </button>
            </div>
        </div>
    );
}
