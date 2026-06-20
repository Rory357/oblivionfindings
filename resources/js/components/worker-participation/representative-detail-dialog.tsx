/* Representative detail dialog for the Worker Participation register.
 *
 * Mirrors safeguarding/concern-dialog.tsx 1:1 in shape: built on WizardShell,
 * the wizard "steps" are reused as a left rail of dialog SECTIONS, the status
 * pill + work-group chip live in footerStart, the gated Options bar lives in
 * footerEnd (suppressed while an action pane owns the body), and each ACTION is
 * an inline pane (StepHead + fields) that REPLACES the section body and renders
 * its own Cancel + Submit row. initialAction opens straight onto a pane.
 *
 * NZ English, semantic design tokens only, en-NZ dates via shared fmtDate /
 * fmtDateTime. All endpoints redirect()->back(), so a mutation just needs
 * { preserveScroll, onSuccess: () => setAction(null) } — Inertia re-renders the
 * detail prop while ?representative= is in the URL. */
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ReviewCard, ReviewRow, WizardShell } from '@/components/wizard/shell';
import { Field, InfoCard, Segmented, StepHead } from '@/components/wizard/primitives';
import {
    REP_STATUS,
    fmtDate,
    fmtDateTime,
    type RepDetail,
    type WpCan,
    type WpDetailAction,
} from '@/components/worker-participation/shared';
import { titleCase, type Tone } from '@/pages/health-safety/components/register-row-kit';
import { router, useForm } from '@inertiajs/react';
import {
    CalendarClock,
    CheckCircle2,
    Clock,
    GraduationCap,
    History,
    MapPin,
    Pencil,
    ShieldCheck,
    Slash,
    UserRound,
    Users,
} from 'lucide-react';
import { useState, type ComponentType, type FormEvent } from 'react';

const WP_BASE = '/health-safety/worker-participation';

/* ------------------------------------------------------------------ */
/*  Tokens — keep local DOT helper, reuse shared REP_STATUS tone map    */
/* ------------------------------------------------------------------ */

const DOT: Record<Tone, string> = {
    success: 'bg-status-success',
    warning: 'bg-status-warning',
    critical: 'bg-status-critical',
    neutral: 'bg-muted-foreground',
};

const STATUS_LABEL: Record<string, string> = { active: 'Active', inactive: 'Inactive', resigned: 'Resigned' };

function initials(name: string | null | undefined): string {
    if (!name) return 'WP';
    const parts = name.split(/\s+/).filter(Boolean);
    const text = parts.length > 1 ? `${parts[0][0]}${parts[1][0]}` : name.slice(0, 2);
    return text.toUpperCase();
}

type SectionKey = 'overview' | 'history';

/* ------------------------------------------------------------------ */
/*  Dialog                                                             */
/* ------------------------------------------------------------------ */

export function RepresentativeDetailDialog({
    detail,
    open,
    onClose,
    staff,
    sites,
    can,
    initialAction,
}: {
    detail: RepDetail;
    open: boolean;
    onClose: () => void;
    staff: { id: number; name: string }[];
    sites: { id: number; name: string }[];
    can: WpCan;
    initialAction: WpDetailAction | null;
}) {
    // The dialog is keyed by representative id in the list, so it mounts fresh on
    // each open — the right-click menu uses initialAction to land on a pane.
    const [section, setSection] = useState<SectionKey>('overview');
    const [action, setAction] = useState<WpDetailAction | null>(
        initialAction === 'edit' || initialAction === 'training' ? initialAction : null,
    );
    // staff/sites are passed by the index for parity with the other WP dialogs;
    // representative edits never reassign the person or site, so they're unused here.
    void staff;
    void sites;

    const d = detail;
    const name = d.user?.name ?? 'Representative';
    const active = d.status === 'active';
    const statusTone: Tone = REP_STATUS[d.status] ?? 'neutral';
    const belowMin = (d.training_days_completed ?? 0) < 2;

    const SECTIONS: { key: SectionKey; label: string; blurb: string; icon: ComponentType<{ className?: string }> }[] = [
        { key: 'overview', label: 'Overview', blurb: 'Mandate & training', icon: UserRound },
        { key: 'history', label: 'History', blurb: 'Record & training', icon: History },
    ];
    const stepIndex = SECTIONS.findIndex((s) => s.key === section);

    const footerStart = (
        <div className="flex items-center gap-2 text-xs">
            <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 font-medium">
                <span className={`h-1.5 w-1.5 rounded-full ${DOT[statusTone]}`} />
                {STATUS_LABEL[d.status] ?? titleCase(d.status)}
            </span>
            {d.work_group ? (
                <span className="inline-flex items-center gap-1 text-muted-foreground">
                    <Users className="h-3 w-3" /> {d.work_group}
                </span>
            ) : (
                <span className="text-muted-foreground">All kaimahi</span>
            )}
        </div>
    );

    // Gated Options bar — hidden when the viewer can't manage; suppressed while an
    // action pane owns the body. Stand-down / activate are DIRECT buttons (no pane).
    const footerEnd = action ? null : can.manage ? (
        <div className="flex flex-wrap items-center justify-end gap-2">
            <OptionBtn icon={Pencil} label="Edit details" onClick={() => setAction('edit')} />
            <OptionBtn icon={GraduationCap} label="Record training days" onClick={() => setAction('training')} />
            {active ? (
                <Button
                    size="sm"
                    variant="outline"
                    className="text-status-critical hover:text-status-critical"
                    onClick={() => router.put(`${WP_BASE}/representatives/${d.id}`, { status: 'inactive' }, { preserveScroll: true })}
                >
                    <Slash className="mr-1.5 h-4 w-4" /> Mark stood-down
                </Button>
            ) : (
                <Button
                    size="sm"
                    onClick={() => router.put(`${WP_BASE}/representatives/${d.id}`, { status: 'active' }, { preserveScroll: true })}
                >
                    <CheckCircle2 className="mr-1.5 h-4 w-4" /> Mark active
                </Button>
            )}
        </div>
    ) : null;

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title={`Representative · ${name}`}
            description={`H&S representative — ${d.site?.name ?? 'site'}`}
            railIcon={ShieldCheck}
            railTitle={name}
            railSub={`H&S rep · ${d.site?.name ?? '—'}`}
            steps={SECTIONS}
            stepIndex={stepIndex}
            onStepClick={(i) => {
                setAction(null); // leaving an open action pane back to its section
                setSection(SECTIONS[i].key);
            }}
            footerStart={footerStart}
            footerEnd={footerEnd}
        >
            {action === 'edit' ? (
                <EditPane d={d} onDone={() => setAction(null)} />
            ) : action === 'training' ? (
                <TrainingPane d={d} onDone={() => setAction(null)} />
            ) : (
                <>
                    {section === 'overview' ? (
                        <OverviewSection
                            d={d}
                            name={name}
                            statusTone={statusTone}
                            belowMin={belowMin}
                            onTraining={can.manage ? () => setAction('training') : undefined}
                        />
                    ) : null}
                    {section === 'history' ? <HistorySection d={d} /> : null}
                </>
            )}
        </WizardShell>
    );
}

/* ------------------------------------------------------------------ */
/*  Options bar button + action panes                                  */
/* ------------------------------------------------------------------ */

function OptionBtn({ icon: Icon, label, onClick }: { icon: ComponentType<{ className?: string }>; label: string; onClick: () => void }) {
    return (
        <Button size="sm" variant="outline" onClick={onClick}>
            <Icon className="mr-1.5 h-4 w-4" /> {label}
        </Button>
    );
}

const TEXTAREA_CLASS =
    'w-full rounded-lg border border-border bg-background p-2.5 text-sm focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none';

const STATUS_OPTS: { value: 'active' | 'inactive' | 'resigned'; label: string }[] = [
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
    { value: 'resigned', label: 'Resigned' },
];

/** Edit details — work group, training days, term expiry, status, notes. */
function EditPane({ d, onDone }: { d: RepDetail; onDone: () => void }) {
    const form = useForm<{
        work_group: string;
        training_days_completed: string;
        term_expires_at: string;
        status: 'active' | 'inactive' | 'resigned';
        notes: string;
    }>({
        work_group: d.work_group ?? '',
        training_days_completed: String(d.training_days_completed ?? 0),
        term_expires_at: d.term_expires_at ? d.term_expires_at.slice(0, 10) : '',
        status: (['active', 'inactive', 'resigned'].includes(d.status) ? d.status : 'active') as 'active' | 'inactive' | 'resigned',
        notes: d.notes ?? '',
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        // training_days_completed is integer (not nullable) server-side — a cleared
        // field sends '' which becomes null and 422s, so guard before submitting.
        if (form.data.training_days_completed.trim() === '') {
            form.setError('training_days_completed', 'Enter the number of paid training days completed.');
            return;
        }
        form.put(`${WP_BASE}/representatives/${d.id}`, {
            preserveScroll: true,
            onSuccess: (page) => {
                // back()->with('error') fires onSuccess, not onError — guard before closing.
                const flash = page.props.flash as { error?: string } | undefined;
                if (!flash?.error) onDone();
            },
        });
    };
    return (
        <>
            <StepHead icon={Pencil} title="Edit representative" blurb="Update the rep's mandate, training record and standing. The person and site can't be reassigned here." />
            <form onSubmit={submit} className="flex flex-col gap-4">
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Work group" hint="Optional" error={form.errors.work_group}>
                        <Input
                            value={form.data.work_group}
                            onChange={(e) => form.setData('work_group', e.target.value)}
                            placeholder="e.g. Night support · Wellington"
                        />
                    </Field>
                    <Field label="Training days completed" error={form.errors.training_days_completed}>
                        <Input
                            type="number"
                            min={0}
                            step={1}
                            value={form.data.training_days_completed}
                            onChange={(e) => form.setData('training_days_completed', e.target.value)}
                        />
                    </Field>
                </div>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Term expires" hint="Optional" error={form.errors.term_expires_at}>
                        <Input type="date" value={form.data.term_expires_at} onChange={(e) => form.setData('term_expires_at', e.target.value)} />
                    </Field>
                    <Field label="Status" error={form.errors.status}>
                        <Segmented value={form.data.status} onChange={(v) => form.setData('status', v)} options={STATUS_OPTS} />
                    </Field>
                </div>
                <Field label="Notes" hint="Optional" error={form.errors.notes}>
                    <textarea
                        className={TEXTAREA_CLASS}
                        rows={4}
                        value={form.data.notes}
                        onChange={(e) => form.setData('notes', e.target.value)}
                        placeholder="Anything relevant to this rep's mandate or support needs."
                    />
                </Field>
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onDone}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        Save changes
                    </Button>
                </div>
            </form>
        </>
    );
}

/** Record training days — single number input + HSWA / NZQA info. */
function TrainingPane({ d, onDone }: { d: RepDetail; onDone: () => void }) {
    const form = useForm<{ training_days_completed: string; initial_training_completed_at: string }>({
        training_days_completed: String(d.training_days_completed ?? 0),
        initial_training_completed_at: d.initial_training_completed_at ? d.initial_training_completed_at.slice(0, 10) : '',
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        // 'required' rule is integer (not nullable) — a cleared field sends '' which
        // becomes null server-side and 422s, so guard before submitting.
        if (form.data.training_days_completed.trim() === '') {
            form.setError('training_days_completed', 'Enter the number of paid training days completed.');
            return;
        }
        form.put(`${WP_BASE}/representatives/${d.id}`, {
            preserveScroll: true,
            onSuccess: (page) => {
                // back()->with('error') fires onSuccess, not onError — guard before closing.
                const flash = page.props.flash as { error?: string } | undefined;
                if (!flash?.error) onDone();
            },
        });
    };
    return (
        <>
            <StepHead icon={GraduationCap} title="Record training days" blurb="Update the cumulative paid H&S training days this rep has completed." />
            <form onSubmit={submit} className="flex flex-col gap-4">
                <Field label="Training days completed" required error={form.errors.training_days_completed}>
                    <Input
                        type="number"
                        min={0}
                        step={1}
                        value={form.data.training_days_completed}
                        onChange={(e) => form.setData('training_days_completed', e.target.value)}
                    />
                </Field>
                <Field label="Initial training completed" hint="NZQA US 29315 — optional" error={form.errors.initial_training_completed_at}>
                    <Input
                        type="date"
                        max={new Date().toISOString().slice(0, 10)}
                        value={form.data.initial_training_completed_at}
                        onChange={(e) => form.setData('initial_training_completed_at', e.target.value)}
                    />
                </Field>
                <InfoCard icon={GraduationCap} tone="info">
                    Under HSWA 2015 an elected H&amp;S representative is entitled to <b>two days&apos; paid training each year</b>. Approved
                    training maps to <b>NZQA Unit Standard 29315</b> (HSR training). Recording the completion date adds a tracked
                    credential to the rep&apos;s HR record.
                </InfoCard>
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onDone}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        Save training
                    </Button>
                </div>
            </form>
        </>
    );
}

/* ------------------------------------------------------------------ */
/*  Sections                                                           */
/* ------------------------------------------------------------------ */

function OverviewSection({
    d,
    name,
    statusTone,
    belowMin,
    onTraining,
}: {
    d: RepDetail;
    name: string;
    statusTone: Tone;
    belowMin: boolean;
    onTraining?: () => void;
}) {
    const trainingDays = d.training_days_completed ?? 0;
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            {/* Person header */}
            {/* eslint-disable-next-line no-restricted-syntax -- bespoke avatar + name + status-pill banner, not a plain Card body */}
            <div className="flex items-center gap-3 rounded-xl border border-border bg-card/70 p-4 sm:col-span-2">
                <span className="grid h-12 w-12 shrink-0 place-items-center rounded-full bg-primary text-base font-semibold text-primary-foreground">
                    {initials(name)}
                </span>
                <div className="min-w-0">
                    <p className="truncate text-base font-bold text-foreground">{name}</p>
                    <p className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted-foreground">
                        <span className="inline-flex items-center gap-1">
                            <MapPin className="h-3 w-3" /> {d.site?.name ?? '—'}
                        </span>
                        <span aria-hidden>·</span>
                        <span className="inline-flex items-center gap-1">
                            <Users className="h-3 w-3" /> {d.work_group ?? 'All kaimahi'}
                        </span>
                    </p>
                </div>
                <span className={`ml-auto inline-flex shrink-0 items-center gap-1.5 rounded-full bg-muted px-2.5 py-1 text-[11px] font-semibold`}>
                    <span className={`h-1.5 w-1.5 rounded-full ${DOT[statusTone]}`} />
                    {STATUS_LABEL[d.status] ?? titleCase(d.status)}
                </span>
            </div>

            {belowMin ? (
                <InfoCard icon={GraduationCap} tone="warn">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <span>
                            <span className="font-semibold">Below the training minimum.</span> This rep has {trainingDays}{' '}
                            {trainingDays === 1 ? 'day' : 'days'} — HSWA 2015 entitles them to two paid days a year.
                        </span>
                        {onTraining ? (
                            <Button size="sm" onClick={onTraining}>
                                <GraduationCap className="mr-1.5 h-4 w-4" /> Record training
                            </Button>
                        ) : null}
                    </div>
                </InfoCard>
            ) : null}

            <ReviewCard icon={ShieldCheck} title="Mandate">
                <ReviewRow label="Election method" value={d.election_method ? titleCase(d.election_method) : undefined} />
                <ReviewRow label="Elected / appointed" value={d.elected_at ? fmtDate(d.elected_at) : undefined} />
                <ReviewRow
                    label="Term ends"
                    value={
                        d.term_expires_at ? (
                            <span className="inline-flex items-center gap-1">
                                <CalendarClock className="h-3 w-3 text-muted-foreground" /> {fmtDate(d.term_expires_at)}
                            </span>
                        ) : (
                            'No fixed term'
                        )
                    }
                />
                <ReviewRow label="Status" value={STATUS_LABEL[d.status] ?? titleCase(d.status)} />
            </ReviewCard>

            <ReviewCard icon={GraduationCap} title="Training">
                <ReviewRow
                    label="Days completed"
                    value={
                        <span className={belowMin ? 'text-status-warning' : 'text-status-success'}>
                            {trainingDays} {trainingDays === 1 ? 'day' : 'days'}
                        </span>
                    }
                />
                <ReviewRow label="HSWA minimum" value="2 days / year" />
                <ReviewRow label="Standard met" value={belowMin ? 'Below minimum' : 'Minimum met'} />
                <ReviewRow
                    label="First trained"
                    value={d.initial_training_completed_at ? fmtDate(d.initial_training_completed_at) : undefined}
                />
            </ReviewCard>

            <ReviewCard icon={UserRound} title="Notes" span>
                <p className="text-sm whitespace-pre-wrap text-foreground">{d.notes || '—'}</p>
            </ReviewCard>
        </div>
    );
}

function HistorySection({ d }: { d: RepDetail }) {
    type TLEvent = { at: string; label: string; tone: Tone; icon: ComponentType<{ className?: string }> };
    const events: TLEvent[] = [];
    if (d.created_at) {
        events.push({
            at: d.created_at,
            label: `Added to the register${d.creator?.name ? ` by ${d.creator.name}` : ''}`,
            tone: 'neutral',
            icon: UserRound,
        });
    }
    if (d.elected_at) {
        const verb = d.election_method ? titleCase(d.election_method) : 'Mandated';
        events.push({ at: d.elected_at, label: `${verb} as H&S representative`, tone: 'success', icon: ShieldCheck });
    }
    if (d.initial_training_completed_at) {
        events.push({ at: d.initial_training_completed_at, label: 'Initial HSWA training completed', tone: 'success', icon: GraduationCap });
    }
    if (d.term_expires_at) {
        const ended = new Date(d.term_expires_at).getTime() < Date.now();
        events.push({
            at: d.term_expires_at,
            label: ended ? 'Term ended' : 'Term ends',
            tone: ended ? 'critical' : 'warning',
            icon: CalendarClock,
        });
    }
    events.sort((a, b) => new Date(a.at).getTime() - new Date(b.at).getTime());

    return (
        <div className="flex flex-col gap-5">
            {events.length ? (
                <ol className="relative ml-2 border-l border-border">
                    {events.map((e, i) => {
                        const Icon = e.icon;
                        return (
                            <li key={i} className="mb-5 ml-5">
                                <span className={`absolute -left-[7px] flex h-3.5 w-3.5 items-center justify-center rounded-full ${DOT[e.tone]}`} />
                                <div className="flex items-center gap-2">
                                    <Icon className="h-4 w-4 text-muted-foreground" />
                                    <span className="text-sm font-medium text-foreground">{e.label}</span>
                                </div>
                                <p className="mt-0.5 text-xs text-muted-foreground">{fmtDate(e.at)}</p>
                            </li>
                        );
                    })}
                </ol>
            ) : (
                <p className="text-sm text-muted-foreground">No history recorded yet.</p>
            )}

            <ReviewCard icon={Clock} title="Record details" span>
                <ReviewRow label="Added to register" value={d.created_at ? fmtDateTime(d.created_at) : undefined} />
                <ReviewRow label="Added by" value={d.creator?.name} />
                <ReviewRow
                    label="Initial training completed"
                    value={d.initial_training_completed_at ? fmtDate(d.initial_training_completed_at) : undefined}
                />
            </ReviewCard>
        </div>
    );
}
