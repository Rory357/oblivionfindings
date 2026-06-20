/* Committee-meeting detail dialog for the Worker Participation register.
 * Mirrors safeguarding/concern-dialog.tsx 1:1: built on WizardShell, the rail
 * "steps" are the dialog sections (Overview / Attendees / Agenda & actions /
 * Minutes), each action is an inline pane that replaces the section body and
 * owns its own Cancel + Submit row (footerEnd suppressed while a pane is open),
 * and every mutation redirect()->back()s so Inertia re-renders `detail` in place
 * — on success we just setAction(null) to return to the section view. NZ English;
 * semantic design tokens only; en-NZ dates via shared fmtDate/fmtDateTime. */
import { Button } from '@/components/ui/button';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { ReviewCard, ReviewRow, WizardShell } from '@/components/wizard/shell';
import { Field, SelectInput, StepHead } from '@/components/wizard/primitives';
import {
    MEETING_STATUS,
    WP_BASE,
    fmtDate,
    fmtDateTime,
    type ActionItem,
    type MeetingDetail,
    type WpCan,
    type WpDetailAction,
} from '@/components/worker-participation/shared';
import type { Tone } from '@/pages/health-safety/components/register-row-kit';
import { router, useForm } from '@inertiajs/react';
import {
    CalendarClock,
    CheckCircle2,
    ClipboardCheck,
    ClipboardList,
    Download,
    FileText,
    ListTodo,
    MapPin,
    Pencil,
    Plus,
    Trash2,
    Upload,
    UserCheck,
    UserPlus,
    Users,
    X,
    XCircle,
} from 'lucide-react';
import { useState, type ComponentType, type FormEvent, type ReactNode } from 'react';

/* ------------------------------------------------------------------ */
/*  Tokens (local dot helper; tone maps come from shared)              */
/* ------------------------------------------------------------------ */

const DOT: Record<Tone, string> = {
    neutral: 'bg-muted-foreground',
    success: 'bg-status-success',
    warning: 'bg-status-warning',
    critical: 'bg-status-critical',
};

type Staff = { id: number; name: string };
type SectionKey = 'overview' | 'attendees' | 'agenda' | 'minutes';

function titleCase(s: string | null | undefined): string {
    return (s ?? '').replace(/[_-]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

const ACTION_STATUS: Record<string, { tone: Tone; label: string }> = {
    open: { tone: 'warning', label: 'Open' },
    in_progress: { tone: 'neutral', label: 'In progress' },
    done: { tone: 'success', label: 'Done' },
};

/** ISO string → value the <input type="datetime-local"> expects. */
const toLocalInput = (iso: string | null | undefined): string => {
    if (!iso) return '';
    const dt = new Date(iso);
    if (Number.isNaN(dt.getTime())) return '';
    return new Date(dt.getTime() - dt.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
};

/* ------------------------------------------------------------------ */
/*  Dialog                                                             */
/* ------------------------------------------------------------------ */

export function MeetingDetailDialog({
    detail,
    open,
    onClose,
    staff,
    can,
    initialAction,
}: {
    detail: MeetingDetail;
    open: boolean;
    onClose: () => void;
    staff: Staff[];
    can: WpCan;
    initialAction: WpDetailAction | null;
}) {
    const d = detail;
    // Dialog is keyed by meeting id in the list, so it mounts fresh on each open —
    // the right-click menu uses initialAction to land straight on a specific pane.
    const isMeetingAction = (a: WpDetailAction | null): a is 'edit' | 'attendees' | 'complete' | 'minutes' =>
        a === 'edit' || a === 'attendees' || a === 'complete' || a === 'minutes';
    const [section, setSection] = useState<SectionKey>(
        initialAction === 'attendees' ? 'attendees' : initialAction === 'complete' || initialAction === 'edit' ? 'agenda' : initialAction === 'minutes' ? 'minutes' : 'overview',
    );
    const [action, setAction] = useState<'edit' | 'attendees' | 'complete' | 'minutes' | null>(isMeetingAction(initialAction) ? initialAction : null);

    const attendees = d.attendee_users ?? [];
    const agenda = d.agenda_items ?? [];
    const actions = d.action_items ?? [];
    const tone = MEETING_STATUS[d.status] ?? 'neutral';
    const scheduled = d.status === 'scheduled';
    const cancelled = d.status === 'cancelled';
    const completed = d.status === 'completed';

    const SECTIONS: { key: SectionKey; label: string; blurb: string; icon: ComponentType<{ className?: string }> }[] = [
        { key: 'overview', label: 'Overview', blurb: 'Committee & schedule', icon: FileText },
        { key: 'attendees', label: 'Attendees', blurb: `${attendees.length} invited`, icon: Users },
        { key: 'agenda', label: 'Agenda & actions', blurb: `${agenda.length} item${agenda.length === 1 ? '' : 's'} · ${actions.length} action${actions.length === 1 ? '' : 's'}`, icon: ClipboardList },
        { key: 'minutes', label: 'Minutes', blurb: d.minutes_document_name ? 'Filed' : d.minutes ? 'Recorded' : 'None yet', icon: ClipboardCheck },
    ];
    const stepIndex = Math.max(0, SECTIONS.findIndex((s) => s.key === section));

    const footerStart = (
        <div className="flex items-center gap-2 text-xs">
            <span className="inline-flex items-center gap-1.5 rounded-full bg-muted px-2 py-0.5 font-medium">
                <span className={`h-1.5 w-1.5 rounded-full ${DOT[tone]}`} />
                {titleCase(d.status)}
            </span>
            <span className="inline-flex items-center gap-1 text-muted-foreground">
                <Users className="h-3 w-3" /> {attendees.length} {attendees.length === 1 ? 'attendee' : 'attendees'}
            </span>
        </div>
    );

    const cancelMeeting = () => router.put(`${WP_BASE}/meetings/${d.id}/cancel`, {}, { preserveScroll: true });

    // Gated Options bar — only when the viewer can manage and the meeting isn't
    // cancelled. Suppressed while an action pane owns the body (it renders its own row).
    const footerEnd = action || !can.manage || cancelled ? null : (
        <div className="flex flex-wrap items-center justify-end gap-2">
            <OptionBtn icon={Pencil} label="Edit" onClick={() => setAction('edit')} />
            {scheduled ? <OptionBtn icon={UserPlus} label="Add attendees" onClick={() => setAction('attendees')} /> : null}
            {scheduled ? <OptionBtn icon={ClipboardCheck} label="Complete meeting" onClick={() => setAction('complete')} /> : null}
            {d.minutes_document_path ? null : <OptionBtn icon={Upload} label="Upload minutes" onClick={() => setAction('minutes')} />}
            {scheduled ? <OptionBtn icon={XCircle} label="Cancel meeting" tone="critical" onClick={cancelMeeting} /> : null}
        </div>
    );

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title={`Committee meeting · ${d.committee?.name ?? 'Meeting'}`}
            description={`${d.committee?.name ?? 'Committee meeting'} — ${fmtDateTime(d.scheduled_at)}`}
            railIcon={Users}
            railTitle={d.committee?.name ?? 'Committee meeting'}
            railSub={`${titleCase(d.status)} · ${fmtDate(d.scheduled_at)}`}
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
            ) : action === 'attendees' ? (
                <AttendeesPane d={d} staff={staff} onDone={() => setAction(null)} />
            ) : action === 'complete' ? (
                <CompletePane d={d} staff={staff} onDone={() => setAction(null)} />
            ) : action === 'minutes' ? (
                <MinutesPane d={d} onDone={() => setAction(null)} />
            ) : (
                <>
                    {section === 'overview' ? <OverviewSection d={d} tone={tone} completed={completed} /> : null}
                    {section === 'attendees' ? <AttendeesSection attendees={attendees} /> : null}
                    {section === 'agenda' ? <AgendaSection agenda={agenda} actions={actions} staff={staff} /> : null}
                    {section === 'minutes' ? <MinutesSection d={d} canManage={can.manage && !cancelled} onUpload={() => setAction('minutes')} /> : null}
                </>
            )}
        </WizardShell>
    );
}

/* ------------------------------------------------------------------ */
/*  Options-bar button + shared pane shell                              */
/* ------------------------------------------------------------------ */

function OptionBtn({ icon: Icon, label, onClick, tone }: { icon: ComponentType<{ className?: string }>; label: string; onClick: () => void; tone?: 'critical' }) {
    return (
        <Button size="sm" variant="outline" onClick={onClick} className={tone === 'critical' ? 'text-status-critical hover:text-status-critical' : undefined}>
            <Icon className="mr-1.5 h-4 w-4" /> {label}
        </Button>
    );
}

function PaneShell({ children, onCancel, onSubmit, cta, processing }: { children: ReactNode; onCancel: () => void; onSubmit: (e: FormEvent) => void; cta: string; processing: boolean }) {
    return (
        <form onSubmit={onSubmit} className="flex flex-col gap-4">
            {children}
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onCancel}>
                    Cancel
                </Button>
                <Button type="submit" disabled={processing}>
                    {cta}
                </Button>
            </div>
        </form>
    );
}

const textareaClass =
    'w-full rounded-lg border border-border bg-background p-2.5 text-sm focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none';

/* ------------------------------------------------------------------ */
/*  Reusable: agenda string[] repeater                                 */
/* ------------------------------------------------------------------ */

function AgendaRepeater({ items, onChange }: { items: string[]; onChange: (v: string[]) => void }) {
    const set = (i: number, v: string) => onChange(items.map((it, idx) => (idx === i ? v : it)));
    const remove = (i: number) => onChange(items.filter((_, idx) => idx !== i));
    const add = () => onChange([...items, '']);
    return (
        <div className="flex flex-col gap-2">
            {items.map((it, i) => (
                <div key={i} className="flex items-center gap-2">
                    <span className="grid h-6 w-6 shrink-0 place-items-center rounded-md bg-muted text-[11px] font-semibold text-muted-foreground">{i + 1}</span>
                    <Input value={it} onChange={(e) => set(i, e.target.value)} placeholder={`Agenda item ${i + 1}`} aria-label={`Agenda item ${i + 1}`} />
                    <Button type="button" variant="ghost" size="sm" onClick={() => remove(i)} aria-label="Remove item" className="text-muted-foreground hover:text-status-critical">
                        <Trash2 className="h-3.5 w-3.5" />
                    </Button>
                </div>
            ))}
            <div>
                <Button type="button" variant="outline" size="sm" onClick={add}>
                    <Plus className="mr-1.5 h-3.5 w-3.5" /> Add agenda item
                </Button>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Reusable: action_items repeater                                    */
/* ------------------------------------------------------------------ */

function ActionRepeater({ items, onChange, staff }: { items: ActionItem[]; onChange: (v: ActionItem[]) => void; staff: Staff[] }) {
    const set = (i: number, patch: Partial<ActionItem>) => onChange(items.map((it, idx) => (idx === i ? { ...it, ...patch } : it)));
    const remove = (i: number) => onChange(items.filter((_, idx) => idx !== i));
    const add = () => onChange([...items, { description: '', assigned_to: null, due_date: null, status: 'open' }]);
    return (
        <div className="flex flex-col gap-3">
            {items.map((it, i) => (
                <div key={i} className="rounded-xl border border-border p-3">
                    <div className="mb-2 flex items-start gap-2">
                        <input
                            value={it.description}
                            onChange={(e) => set(i, { description: e.target.value })}
                            placeholder={`Action ${i + 1} — what needs doing?`}
                            aria-label={`Action ${i + 1} description`}
                            className="flex-1 rounded-lg border border-border bg-background p-2 text-sm focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                        />
                        <Button type="button" variant="ghost" size="sm" onClick={() => remove(i)} aria-label="Remove action" className="text-muted-foreground hover:text-status-critical">
                            <Trash2 className="h-3.5 w-3.5" />
                        </Button>
                    </div>
                    <div className="grid gap-2 sm:grid-cols-3">
                        <SelectInput
                            value={it.assigned_to != null ? String(it.assigned_to) : ''}
                            onChange={(v) => set(i, { assigned_to: v ? Number(v) : null })}
                            placeholder="Assign to"
                            options={staff.map((s) => ({ value: String(s.id), label: s.name }))}
                        />
                        <Input type="date" value={it.due_date ?? ''} onChange={(e) => set(i, { due_date: e.target.value || null })} aria-label={`Action ${i + 1} due date`} />
                        <SelectInput
                            value={it.status ?? 'open'}
                            onChange={(v) => set(i, { status: v as ActionItem['status'] })}
                            placeholder="Status"
                            options={[{ value: 'open', label: 'Open' }, { value: 'in_progress', label: 'In progress' }, { value: 'done', label: 'Done' }]}
                        />
                    </div>
                </div>
            ))}
            <div>
                <Button type="button" variant="outline" size="sm" onClick={add}>
                    <Plus className="mr-1.5 h-3.5 w-3.5" /> Add action item
                </Button>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Reusable: staff multi-select (checkbox list → number[])            */
/* ------------------------------------------------------------------ */

function StaffMultiSelect({ staff, selected, onChange }: { staff: Staff[]; selected: number[]; onChange: (ids: number[]) => void }) {
    const toggle = (id: number) => onChange(selected.includes(id) ? selected.filter((x) => x !== id) : [...selected, id]);
    if (!staff.length) {
        return <p className="text-sm text-muted-foreground">No staff available to select.</p>;
    }
    return (
        <div className="flex max-h-64 flex-col gap-1 overflow-y-auto rounded-lg border border-border p-1.5">
            {staff.map((s) => {
                const active = selected.includes(s.id);
                return (
                    // eslint-disable-next-line no-restricted-syntax -- styled checkbox row (toggle surface), all tokens semantic
                    <button
                        key={s.id}
                        type="button"
                        aria-pressed={active}
                        onClick={() => toggle(s.id)}
                        className={`flex items-center gap-2.5 rounded-md px-2.5 py-2 text-left text-sm transition-colors ${active ? 'bg-primary/10 text-foreground' : 'hover:bg-muted'}`}
                    >
                        <span className={`grid h-5 w-5 shrink-0 place-items-center rounded border ${active ? 'border-primary bg-primary text-primary-foreground' : 'border-border bg-background'}`}>
                            {active ? <CheckCircle2 className="h-3.5 w-3.5" /> : null}
                        </span>
                        {s.name}
                    </button>
                );
            })}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Action panes                                                       */
/* ------------------------------------------------------------------ */

function EditPane({ d, onDone }: { d: MeetingDetail; onDone: () => void }) {
    const form = useForm<{ scheduled_at: string; location: string; agenda_items: string[] }>({
        scheduled_at: toLocalInput(d.scheduled_at),
        location: d.location ?? '',
        agenda_items: d.agenda_items ?? [],
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!form.data.scheduled_at) {
            form.setError('scheduled_at', 'Set the meeting date and time.');
            return;
        }
        form.transform((data) => ({ ...data, agenda_items: data.agenda_items.map((a) => a.trim()).filter(Boolean) }));
        form.put(`${WP_BASE}/meetings/${d.id}`, {
            preserveScroll: true,
            onSuccess: (page) => {
                const flash = page.props.flash as { error?: string } | undefined;
                if (!flash?.error) onDone();
            },
        });
    };
    return (
        <>
            <StepHead icon={CalendarClock} title="Edit meeting" blurb="Adjust the schedule, location and agenda for this committee meeting." />
            <PaneShell onCancel={onDone} onSubmit={submit} cta="Save changes" processing={form.processing}>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Date & time" required error={form.errors.scheduled_at}>
                        <Input type="datetime-local" value={form.data.scheduled_at} onChange={(e) => form.setData('scheduled_at', e.target.value)} />
                    </Field>
                    <Field label="Location" hint="Optional" error={form.errors.location}>
                        <Input value={form.data.location} onChange={(e) => form.setData('location', e.target.value)} placeholder="e.g. Site office — meeting room" />
                    </Field>
                </div>
                <Field label="Agenda items" hint="Optional">
                    <AgendaRepeater items={form.data.agenda_items} onChange={(v) => form.setData('agenda_items', v)} />
                </Field>
            </PaneShell>
        </>
    );
}

function AttendeesPane({ d, staff, onDone }: { d: MeetingDetail; staff: Staff[]; onDone: () => void }) {
    const already = new Set((d.attendee_users ?? []).map((a) => a.id));
    const selectable = staff.filter((s) => !already.has(s.id));
    const form = useForm<{ user_ids: number[] }>({ user_ids: [] });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!form.data.user_ids.length) {
            form.setError('user_ids', 'Choose at least one person to invite.');
            return;
        }
        form.post(`${WP_BASE}/meetings/${d.id}/attendees`, {
            preserveScroll: true,
            onSuccess: (page) => {
                const flash = page.props.flash as { error?: string } | undefined;
                if (!flash?.error) onDone();
            },
        });
    };
    return (
        <>
            <StepHead icon={UserPlus} title="Add attendees" blurb="Invite kaimahi to this meeting. People already on the list aren't shown." />
            <PaneShell onCancel={onDone} onSubmit={submit} cta={`Add ${form.data.user_ids.length || ''} attendee${form.data.user_ids.length === 1 ? '' : 's'}`.trim()} processing={form.processing}>
                <Field label="Invite" required error={form.errors.user_ids}>
                    {selectable.length ? (
                        <StaffMultiSelect staff={selectable} selected={form.data.user_ids} onChange={(ids) => form.setData('user_ids', ids)} />
                    ) : (
                        <p className="text-sm text-muted-foreground">Everyone is already invited.</p>
                    )}
                </Field>
            </PaneShell>
        </>
    );
}

function CompletePane({ d, staff, onDone }: { d: MeetingDetail; staff: Staff[]; onDone: () => void }) {
    const invited = d.attendee_users ?? [];
    const form = useForm<{ minutes: string; action_items: ActionItem[]; actual_attendee_ids: number[] }>({
        minutes: d.minutes ?? '',
        action_items: d.action_items ?? [],
        actual_attendee_ids: invited.map((a) => a.id),
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            action_items: data.action_items
                .map((a) => ({ ...a, description: a.description.trim() }))
                .filter((a) => a.description),
        }));
        form.put(`${WP_BASE}/meetings/${d.id}/complete`, {
            preserveScroll: true,
            onSuccess: (page) => {
                const flash = page.props.flash as { error?: string } | undefined;
                if (!flash?.error) onDone();
            },
        });
    };
    return (
        <>
            <StepHead icon={ClipboardCheck} title="Complete meeting" blurb="Capture the minutes, record who attended, and log any action items with owners and due dates." />
            <PaneShell onCancel={onDone} onSubmit={submit} cta="Complete meeting" processing={form.processing}>
                <Field label="Minutes" hint="Optional — a summary; you can also upload a signed copy" error={form.errors.minutes}>
                    <textarea className={textareaClass} rows={5} value={form.data.minutes} onChange={(e) => form.setData('minutes', e.target.value)} placeholder="Key discussion points, decisions and outcomes…" />
                </Field>
                <Field label="Who attended" hint="Defaults to everyone invited">
                    {invited.length ? (
                        <StaffMultiSelect staff={invited.map((a) => ({ id: a.id, name: a.name }))} selected={form.data.actual_attendee_ids} onChange={(ids) => form.setData('actual_attendee_ids', ids)} />
                    ) : (
                        <p className="text-sm text-muted-foreground">No attendees were invited to this meeting.</p>
                    )}
                </Field>
                <Field label="Action items" hint="Optional">
                    <ActionRepeater items={form.data.action_items} onChange={(v) => form.setData('action_items', v)} staff={staff} />
                </Field>
            </PaneShell>
        </>
    );
}

function MinutesPane({ d, onDone }: { d: MeetingDetail; onDone: () => void }) {
    const { data, setData, post, processing, errors } = useForm<{ document: File | null }>({ document: null });
    const submit = () => {
        if (!data.document) return;
        post(`${WP_BASE}/meetings/${d.id}/minutes`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: (page) => {
                const flash = page.props.flash as { error?: string } | undefined;
                setData('document', null);
                if (!flash?.error) onDone();
            },
        });
    };
    return (
        <>
            <StepHead icon={Upload} title="Upload minutes" blurb="Attach the signed minutes document for this meeting (PDF or Word)." />
            <div className="flex flex-col gap-4">
                {data.document ? (
                    <StagedFileCard file={data.document} onRemove={() => setData('document', null)}>
                        <Button onClick={submit} disabled={processing} className="w-full">
                            <Upload className="mr-1.5 h-4 w-4" /> Upload minutes
                        </Button>
                    </StagedFileCard>
                ) : (
                    <FileDropzone multiple={false} accept=".pdf,.doc,.docx" hint="PDF or Word — up to 20 MB" onFiles={(f) => setData('document', f[0])} />
                )}
                {errors.document ? <p className="text-xs text-status-critical">{errors.document}</p> : null}
                <div className="flex justify-end">
                    <Button type="button" variant="outline" onClick={onDone}>
                        Cancel
                    </Button>
                </div>
            </div>
        </>
    );
}

/* ------------------------------------------------------------------ */
/*  Sections                                                           */
/* ------------------------------------------------------------------ */

function OverviewSection({ d, tone, completed }: { d: MeetingDetail; tone: Tone; completed: boolean }) {
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <ReviewCard icon={Users} title="Committee" span>
                <ReviewRow label="Committee" value={d.committee?.name} />
                <ReviewRow label="Status" value={<span className="inline-flex items-center gap-1.5"><span className={`h-1.5 w-1.5 rounded-full ${DOT[tone]}`} />{titleCase(d.status)}</span>} />
            </ReviewCard>

            <ReviewCard icon={CalendarClock} title="Schedule">
                <ReviewRow label="Scheduled" value={fmtDateTime(d.scheduled_at)} />
                {completed ? <ReviewRow label="Ended" value={d.ended_at ? fmtDateTime(d.ended_at) : undefined} /> : null}
            </ReviewCard>

            <ReviewCard icon={MapPin} title="Location">
                <ReviewRow label="Location" value={d.location} />
                <ReviewRow label="Attendees" value={`${(d.attendee_users ?? []).length} invited`} />
            </ReviewCard>

            {d.actions_due_count > 0 ? (
                <ReviewCard icon={ListTodo} title="Outstanding actions" span>
                    <p className="text-sm text-foreground">
                        {d.actions_due_count} action {d.actions_due_count === 1 ? 'item is' : 'items are'} due — see <span className="font-medium">Agenda &amp; actions</span>.
                    </p>
                </ReviewCard>
            ) : null}
        </div>
    );
}

function AttendeesSection({ attendees }: { attendees: NonNullable<MeetingDetail['attendee_users']> }) {
    if (!attendees.length) {
        return <EmptyState icon={Users} text="No attendees invited yet." />;
    }
    return (
        <div className="flex flex-col gap-2">
            {attendees.map((a) => {
                const attended = !!a.pivot?.attended;
                const declined = a.pivot?.response === 'declined';
                const badgeTone: Tone = attended ? 'success' : declined ? 'critical' : 'neutral';
                const badgeLabel = attended ? 'Attended' : declined ? 'Declined' : 'Invited';
                const badgeIcon = attended ? UserCheck : declined ? X : Users;
                const BadgeIcon = badgeIcon;
                return (
                    <div key={a.id} className="flex items-center justify-between gap-3 rounded-lg border border-border p-3">
                        <span className="text-sm font-medium text-foreground">{a.name}</span>
                        <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[11px] font-medium ${chipClass(badgeTone)}`}>
                            <BadgeIcon className="h-3 w-3" /> {badgeLabel}
                        </span>
                    </div>
                );
            })}
        </div>
    );
}

function AgendaSection({ agenda, actions, staff }: { agenda: string[]; actions: ActionItem[]; staff: Staff[] }) {
    const nameOf = (id: number | null | undefined) => (id != null ? staff.find((s) => s.id === id)?.name ?? `User #${id}` : null);
    return (
        <div className="flex flex-col gap-5">
            <div>
                <p className="mb-2 flex items-center gap-1.5 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                    <ClipboardList className="h-3 w-3" /> Agenda
                </p>
                {agenda.length ? (
                    <ol className="flex flex-col gap-1.5">
                        {agenda.map((item, i) => (
                            <li key={i} className="flex items-start gap-2.5 rounded-lg border border-border p-2.5 text-sm">
                                <span className="grid h-5 w-5 shrink-0 place-items-center rounded-md bg-muted text-[11px] font-semibold text-muted-foreground">{i + 1}</span>
                                <span className="text-foreground">{item}</span>
                            </li>
                        ))}
                    </ol>
                ) : (
                    <EmptyState icon={ClipboardList} text="No agenda items recorded." />
                )}
            </div>

            <div>
                <p className="mb-2 flex items-center gap-1.5 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                    <ListTodo className="h-3 w-3" /> Action items
                </p>
                {actions.length ? (
                    <div className="flex flex-col gap-2">
                        {actions.map((a, i) => {
                            const st = ACTION_STATUS[a.status ?? 'open'] ?? ACTION_STATUS.open;
                            return (
                                <div key={i} className="flex items-start gap-3 rounded-lg border border-border p-3">
                                    <ListTodo className={`mt-0.5 h-4 w-4 shrink-0 ${st.tone === 'success' ? 'text-status-success' : st.tone === 'warning' ? 'text-status-warning' : 'text-muted-foreground'}`} />
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm text-foreground">{a.description}</p>
                                        <p className="text-xs text-muted-foreground">
                                            {nameOf(a.assigned_to) ?? 'Unassigned'}
                                            {a.due_date ? ` · due ${fmtDate(a.due_date)}` : ''}
                                        </p>
                                    </div>
                                    <span className={`shrink-0 rounded-full px-2.5 py-0.5 text-[11px] font-medium ${chipClass(st.tone)}`}>{st.label}</span>
                                </div>
                            );
                        })}
                    </div>
                ) : (
                    <EmptyState icon={ListTodo} text="No action items recorded." />
                )}
            </div>
        </div>
    );
}

function MinutesSection({ d, canManage, onUpload }: { d: MeetingDetail; canManage: boolean; onUpload: () => void }) {
    const hasDoc = !!d.minutes_document_path;
    return (
        <div className="flex flex-col gap-4">
            {d.minutes ? (
                <ReviewCard icon={ClipboardCheck} title="Minutes" span>
                    <p className="text-sm whitespace-pre-wrap text-foreground">{d.minutes}</p>
                </ReviewCard>
            ) : null}

            {hasDoc ? (
                <div className="flex items-center gap-3 rounded-lg border border-border p-3">
                    <span className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                        <FileText className="h-5 w-5" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium text-foreground">{d.minutes_document_name ?? 'Minutes document'}</p>
                        <p className="text-xs text-muted-foreground">Filed minutes</p>
                    </div>
                    <a href={`${WP_BASE}/meetings/${d.id}/minutes/download`} className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-primary transition-colors hover:bg-muted">
                        <Download className="h-3.5 w-3.5" /> Download
                    </a>
                </div>
            ) : null}

            {!d.minutes && !hasDoc ? (
                <EmptyState icon={ClipboardCheck} text="No minutes recorded or filed yet." />
            ) : null}

            {canManage && !hasDoc ? (
                <div>
                    <Button size="sm" variant="outline" onClick={onUpload}>
                        <Upload className="mr-1.5 h-4 w-4" /> Upload minutes document
                    </Button>
                </div>
            ) : null}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Shared helpers                                                     */
/* ------------------------------------------------------------------ */

function chipClass(tone: Tone): string {
    switch (tone) {
        case 'success':
            return 'bg-status-success-bg text-status-success';
        case 'warning':
            return 'bg-status-warning-bg text-status-warning';
        case 'critical':
            return 'bg-status-critical-bg text-status-critical';
        default:
            return 'bg-muted text-muted-foreground';
    }
}

function EmptyState({ icon: Icon, text }: { icon: ComponentType<{ className?: string }>; text: string }) {
    return (
        <div className="rounded-xl border border-dashed border-border py-12 text-center">
            <Icon className="mx-auto mb-2 h-8 w-8 text-muted-foreground/40" />
            <p className="text-sm text-muted-foreground">{text}</p>
        </div>
    );
}
