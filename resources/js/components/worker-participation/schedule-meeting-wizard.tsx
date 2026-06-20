/* Schedule-meeting create wizard for the Worker Participation register.
 *
 * Mirrors the Add-Client wizard contract (resources/js/components/clients/
 * add-client-dialog.tsx) but CONSUMES the shared WizardShell chrome rather than
 * re-inlining it. Four steps — Committee → Schedule → Attendees → Review —
 * with per-step validation that mirrors the FormRequest rules, a completeness
 * ring on the review step, and a green-check success pane.
 *
 * Submit chaining: a meeting always belongs to a committee. When the user picks
 * an EXISTING committee we POST straight to /committees/{id}/meetings. When they
 * choose to create a NEW committee we first POST /committees (which flashes
 * `created_committee_id` back on the redirect) and then, in onSuccess, read that
 * id off the fresh page props and POST the meeting against it — a two-request
 * chain. See `deviations` for the limitation this implies.
 *
 * NZ English, semantic design tokens only. */
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    ChipMulti,
    Field,
    InfoCard,
    Ring,
    Segmented,
    SelectInput,
    StepHead,
    SubHead,
    TilePicker,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/wizard/shell';
import {
    MEETING_FREQUENCIES,
    WP_BASE,
    fmtDateTime,
} from '@/components/worker-participation/shared';
import { router } from '@inertiajs/react';
import {
    Building2,
    CalendarClock,
    CalendarPlus,
    Check,
    ChevronLeft,
    ChevronRight,
    Info,
    ListChecks,
    Loader2,
    MapPin,
    Plus,
    Trash2,
    UserCog,
    Users,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';

/* ------------------------------------------------------------------ */
/*  Props + local shapes                                               */
/* ------------------------------------------------------------------ */

type CommitteeOption = {
    id: number;
    name: string;
    site_id: number | null;
    meeting_frequency: string;
    meetings_count: number;
};
type Option = { id: number; name: string };

type Props = {
    open: boolean;
    committees: CommitteeOption[];
    sites: Option[];
    staff: Option[];
    onClose: () => void;
};

type StepKey = 'committee' | 'schedule' | 'attendees' | 'review';

/** Local wizard state — flat so each field maps 1:1 to a controller field. */
type WizardState = {
    /** '' when creating a new committee, otherwise the chosen committee id. */
    committee_id: string;
    mode: 'existing' | 'new';
    // new-committee fields
    new_name: string;
    new_site_id: string;
    new_frequency: string;
    new_established_at: string;
    new_member_ids: number[];
    // meeting fields
    scheduled_at: string;
    location: string;
    agenda_items: string[];
    attendee_ids: number[];
};

const STEPS: WizardStep[] = [
    {
        key: 'committee',
        label: 'Committee',
        blurb: 'Existing or new committee',
        icon: Building2,
    },
    {
        key: 'schedule',
        label: 'Schedule',
        blurb: 'When, where & agenda',
        icon: CalendarClock,
    },
    {
        key: 'attendees',
        label: 'Attendees',
        blurb: 'Who is expected',
        icon: UserCog,
    },
    {
        key: 'review',
        label: 'Review & schedule',
        blurb: 'Confirm and create',
        icon: Check,
    },
];

const FREQUENCY_LABEL = (v: string) =>
    MEETING_FREQUENCIES.find((f) => f.value === v)?.label ?? v;

function initialState(): WizardState {
    return {
        committee_id: '',
        mode: 'existing',
        new_name: '',
        new_site_id: '',
        new_frequency: 'quarterly',
        new_established_at: new Date().toISOString().slice(0, 10),
        new_member_ids: [],
        scheduled_at: '',
        location: '',
        agenda_items: [],
        attendee_ids: [],
    };
}

/* ------------------------------------------------------------------ */
/*  Validation (mirrors Store{Committee,Meeting}Request)               */
/* ------------------------------------------------------------------ */

function validateStep(
    key: StepKey,
    d: WizardState,
): Record<string, string> {
    const e: Record<string, string> = {};
    if (key === 'committee') {
        if (d.mode === 'existing') {
            if (!d.committee_id) e.committee_id = 'Choose a committee';
        } else {
            if (!d.new_name.trim()) e.new_name = 'Committee name is required';
            if (!d.new_site_id) e.new_site_id = 'Choose a site';
            if (!d.new_frequency) e.new_frequency = 'Choose a meeting frequency';
            if (!d.new_established_at)
                e.new_established_at = 'Established date is required';
            if (d.new_member_ids.length < 1)
                e.new_member_ids = 'Add at least one committee member';
        }
    }
    if (key === 'schedule') {
        if (!d.scheduled_at) e.scheduled_at = 'A meeting date & time is required';
    }
    return e;
}

const STEP_FOR_FIELD: Record<string, StepKey> = {
    committee_id: 'committee',
    new_name: 'committee',
    new_site_id: 'committee',
    new_frequency: 'committee',
    new_established_at: 'committee',
    new_member_ids: 'committee',
    name: 'committee',
    site_id: 'committee',
    meeting_frequency: 'committee',
    established_at: 'committee',
    members: 'committee',
    scheduled_at: 'schedule',
    location: 'schedule',
    agenda_items: 'schedule',
    attendees: 'attendees',
    attendee_ids: 'attendees',
};

function stepForField(field: string): StepKey {
    // Server field names are dotted (e.g. members.0) — match on the root key.
    const root = field.split('.')[0];
    return STEP_FOR_FIELD[root] ?? 'committee';
}

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

/** Map a list of staff display names to their ids (for ChipMulti). */
function namesToIds(names: string[], staff: Option[]): number[] {
    return names
        .map((n) => staff.find((s) => s.name === n)?.id)
        .filter((id): id is number => id != null);
}

/** Map a list of ids back to staff display names (for ChipMulti value). */
function idsToNames(ids: number[], staff: Option[]): string[] {
    return ids
        .map((id) => staff.find((s) => s.id === id)?.name)
        .filter((n): n is string => n != null);
}

/* ------------------------------------------------------------------ */
/*  Component                                                          */
/* ------------------------------------------------------------------ */

export function ScheduleMeetingWizard({ open, committees, sites, staff, onClose }: Props) {
    const [data, setData] = useState<WizardState>(initialState);
    const [stepIndex, setStepIndex] = useState(0);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [done, setDone] = useState(false);

    const cur = STEPS[stepIndex];
    const stepKey = cur.key as StepKey;
    const lastIndex = STEPS.length - 1;
    const isReview = stepKey === 'review';

    const set = <K extends keyof WizardState>(k: K, v: WizardState[K]) =>
        setData((prev) => ({ ...prev, [k]: v }));

    const selectedCommittee =
        data.mode === 'existing'
            ? committees.find((c) => String(c.id) === data.committee_id) ?? null
            : null;

    // Default the attendee list to the chosen committee's members the first time
    // the user lands on the Attendees step with an empty selection. For a new
    // committee that is the members picked on step 1.
    const goToStep = (key: StepKey) => {
        const idx = STEPS.findIndex((s) => s.key === key);
        if (idx >= 0) setStepIndex(idx);
    };

    const next = () => {
        const e = validateStep(stepKey, data);
        setErrors(e);
        if (Object.keys(e).length) return;
        setStepIndex((i) => {
            const ni = Math.min(i + 1, lastIndex);
            // Seed attendees from committee members when first reaching Attendees.
            if (STEPS[ni].key === 'attendees' && data.attendee_ids.length === 0) {
                if (data.mode === 'new' && data.new_member_ids.length) {
                    setData((prev) => ({ ...prev, attendee_ids: prev.new_member_ids }));
                }
            }
            return ni;
        });
    };
    const back = () => setStepIndex((i) => Math.max(i - 1, 0));

    const resetAll = () => {
        setData(initialState());
        setErrors({});
        setStepIndex(0);
        setDone(false);
        setProcessing(false);
    };

    /** Completeness % across the fields that matter for a meeting. */
    const pct = useMemo(() => {
        const checks: boolean[] = [
            data.mode === 'existing'
                ? !!data.committee_id
                : !!data.new_name.trim(),
            data.mode === 'existing' || !!data.new_site_id,
            data.mode === 'existing' || data.new_member_ids.length > 0,
            !!data.scheduled_at,
            !!data.location.trim(),
            data.agenda_items.length > 0,
            data.attendee_ids.length > 0,
        ];
        return Math.round((checks.filter(Boolean).length / checks.length) * 100);
    }, [data]);

    /** POST the meeting against a known committee id. */
    const postMeeting = (committeeId: number) => {
        router.post(
            `${WP_BASE}/committees/${committeeId}/meetings`,
            {
                scheduled_at: data.scheduled_at,
                location: data.location || null,
                agenda_items: data.agenda_items
                    .map((a) => a.trim())
                    .filter(Boolean),
                attendees: data.attendee_ids,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: (pg) => {
                    const flash = (pg.props as { flash?: { error?: string } }).flash;
                    if (!flash?.error) setDone(true);
                    setProcessing(false);
                },
                onError: (errs: Record<string, string>) => {
                    setErrors(errs);
                    setProcessing(false);
                    const first = Object.keys(errs)[0];
                    if (first) goToStep(stepForField(first));
                },
            },
        );
    };

    const submit = () => {
        // Re-validate the gating steps; jump to the first that fails.
        const all: Record<string, string> = {};
        for (const s of STEPS)
            Object.assign(all, validateStep(s.key as StepKey, data));
        if (Object.keys(all).length) {
            setErrors(all);
            goToStep(stepForField(Object.keys(all)[0]));
            return;
        }
        setErrors({});
        setProcessing(true);

        if (data.mode === 'existing') {
            postMeeting(Number(data.committee_id));
            return;
        }

        // New committee + its first meeting in ONE atomic request (storeCommittee
        // accepts schedule_meeting) — avoids a fragile two-POST chain across an
        // Inertia redirect that could create the committee but drop the meeting.
        router.post(
            `${WP_BASE}/committees`,
            {
                name: data.new_name,
                site_id: Number(data.new_site_id),
                meeting_frequency: data.new_frequency,
                established_at: data.new_established_at,
                members: data.new_member_ids,
                schedule_meeting: true,
                scheduled_at: data.scheduled_at,
                location: data.location || null,
                agenda_items: data.agenda_items.map((a) => a.trim()).filter(Boolean),
                attendees: data.attendee_ids,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: (pg) => {
                    const flash = (pg.props as { flash?: { error?: string } }).flash;
                    if (!flash?.error) setDone(true);
                    setProcessing(false);
                },
                onError: (errs: Record<string, string>) => {
                    setErrors(errs);
                    setProcessing(false);
                    const first = Object.keys(errs)[0];
                    if (first) goToStep(stepForField(first));
                },
            },
        );
    };

    /* ---- success pane ---- */
    const success = done ? (
        <WizardSuccessPane
            title="Meeting scheduled"
            blurb={
                <>
                    The committee meeting is on the register as{' '}
                    <span className="font-semibold">Scheduled</span>. Add minutes
                    and action items once it has taken place, or upload the signed
                    minutes from the meeting&rsquo;s detail view.
                </>
            }
            actions={
                <>
                    <Button variant="outline" onClick={resetAll}>
                        <Plus className="h-4 w-4" /> Schedule another
                    </Button>
                    <Button onClick={onClose}>Done</Button>
                </>
            }
        />
    ) : undefined;

    /* ---- footer ---- */
    const footerStart = !done ? <Ring pct={pct} size={40} /> : undefined;
    const footerEnd = done ? undefined : (
        <>
            {stepIndex > 0 ? (
                <Button variant="ghost" onClick={back} disabled={processing}>
                    <ChevronLeft className="h-4 w-4" /> Back
                </Button>
            ) : null}
            <Button variant="outline" onClick={onClose} disabled={processing}>
                Cancel
            </Button>
            {isReview ? (
                <Button onClick={submit} disabled={processing}>
                    {processing ? (
                        <>
                            <Loader2 className="h-4 w-4 animate-spin" /> Scheduling…
                        </>
                    ) : (
                        <>
                            <CalendarPlus className="h-4 w-4" /> Schedule meeting
                        </>
                    )}
                </Button>
            ) : (
                <Button onClick={next}>
                    Continue <ChevronRight className="h-4 w-4" />
                </Button>
            )}
        </>
    );

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title="Schedule a committee meeting"
            description="A guided wizard to schedule a health & safety committee meeting."
            railIcon={CalendarPlus}
            railTitle="Schedule meeting"
            railSub="H&S committee · HSWA 2015"
            steps={STEPS}
            stepIndex={stepIndex}
            onStepClick={(i) => {
                setErrors({});
                setStepIndex(i);
            }}
            pct={pct}
            footerStart={footerStart}
            footerEnd={footerEnd}
            success={success}
        >
            <WizardStepPane>
                {stepKey === 'committee' ? (
                    <StepCommittee
                        data={data}
                        set={set}
                        committees={committees}
                        sites={sites}
                        staff={staff}
                        errors={errors}
                    />
                ) : null}
                {stepKey === 'schedule' ? (
                    <StepSchedule data={data} set={set} errors={errors} />
                ) : null}
                {stepKey === 'attendees' ? (
                    <StepAttendees
                        data={data}
                        set={set}
                        staff={staff}
                        committeeMemberIds={
                            data.mode === 'new' ? data.new_member_ids : null
                        }
                        committeeName={
                            selectedCommittee?.name ??
                            (data.mode === 'new' ? data.new_name : null)
                        }
                    />
                ) : null}
                {stepKey === 'review' ? (
                    <StepReview
                        data={data}
                        pct={pct}
                        sites={sites}
                        staff={staff}
                        selectedCommittee={selectedCommittee}
                        goToStep={goToStep}
                    />
                ) : null}
            </WizardStepPane>
        </WizardShell>
    );
}

/* ------------------------------------------------------------------ */
/*  Step 1 — Committee                                                 */
/* ------------------------------------------------------------------ */

function StepCommittee({
    data,
    set,
    committees,
    sites,
    staff,
    errors,
}: {
    data: WizardState;
    set: <K extends keyof WizardState>(k: K, v: WizardState[K]) => void;
    committees: CommitteeOption[];
    sites: Option[];
    staff: Option[];
    errors: Record<string, string>;
}) {
    const siteName = (id: number | null) =>
        id == null ? null : sites.find((s) => s.id === id)?.name ?? null;

    return (
        <div>
            <StepHead
                icon={Building2}
                title="Which committee is meeting?"
                blurb="Pick an existing health & safety committee, or stand up a new one for a site that doesn't have one yet."
            />
            <div className="grid gap-4">
                <Field label="Committee">
                    <Segmented
                        value={data.mode}
                        onChange={(v) => set('mode', v)}
                        options={[
                            { value: 'existing', label: 'Existing committee', icon: Building2 },
                            { value: 'new', label: 'New committee', icon: Plus },
                        ]}
                    />
                </Field>

                {data.mode === 'existing' ? (
                    committees.length ? (
                        <Field
                            label="Choose a committee"
                            required
                            error={errors.committee_id}
                            span
                        >
                            <TilePicker
                                value={data.committee_id}
                                onChange={(v) => set('committee_id', v)}
                                cols={2}
                                options={committees.map((c) => ({
                                    key: String(c.id),
                                    label: c.name,
                                    icon: Users,
                                    description: [
                                        siteName(c.site_id),
                                        FREQUENCY_LABEL(c.meeting_frequency),
                                    ]
                                        .filter(Boolean)
                                        .join(' · '),
                                    meta:
                                        c.meetings_count > 0
                                            ? `${c.meetings_count} meeting${c.meetings_count === 1 ? '' : 's'} on record`
                                            : 'No meetings yet',
                                }))}
                            />
                        </Field>
                    ) : (
                        <InfoCard icon={Info} tone="warn">
                            No committees exist yet. Switch to{' '}
                            <strong>New committee</strong> to stand up the first
                            health &amp; safety committee.
                        </InfoCard>
                    )
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <SubHead icon={Building2}>New committee</SubHead>
                        <Field
                            label="Committee name"
                            required
                            span
                            error={errors.new_name}
                        >
                            <Input
                                value={data.new_name}
                                onChange={(e) => set('new_name', e.target.value)}
                                placeholder="e.g. Hamilton East H&S Committee"
                                aria-invalid={!!errors.new_name}
                            />
                        </Field>
                        <Field label="Site" required error={errors.new_site_id}>
                            <SelectInput
                                value={data.new_site_id}
                                onChange={(v) => set('new_site_id', v)}
                                placeholder="Choose a site"
                                options={sites.map((s) => ({
                                    value: String(s.id),
                                    label: s.name,
                                }))}
                            />
                        </Field>
                        <Field
                            label="Established"
                            required
                            error={errors.new_established_at}
                        >
                            <Input
                                type="date"
                                value={data.new_established_at}
                                onChange={(e) =>
                                    set('new_established_at', e.target.value)
                                }
                                aria-invalid={!!errors.new_established_at}
                            />
                        </Field>
                        <Field
                            label="Meeting frequency"
                            required
                            span
                            error={errors.new_frequency}
                        >
                            <Segmented
                                value={data.new_frequency}
                                onChange={(v) => set('new_frequency', v)}
                                options={MEETING_FREQUENCIES.map((f) => ({
                                    value: f.value,
                                    label: f.label,
                                }))}
                            />
                        </Field>
                        <Field
                            label="Committee members"
                            required
                            span
                            hint="kaimahi who sit on the committee"
                            error={errors.new_member_ids}
                        >
                            <ChipMulti
                                values={idsToNames(data.new_member_ids, staff)}
                                onChange={(names) =>
                                    set('new_member_ids', namesToIds(names, staff))
                                }
                                options={staff.map((s) => s.name)}
                            />
                        </Field>
                        <InfoCard icon={Info}>
                            HSWA 2015 expects a committee to meet at least
                            quarterly. Members chosen here are offered as the
                            default attendees for this meeting.
                        </InfoCard>
                    </div>
                )}
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Step 2 — Schedule                                                  */
/* ------------------------------------------------------------------ */

function StepSchedule({
    data,
    set,
    errors,
}: {
    data: WizardState;
    set: <K extends keyof WizardState>(k: K, v: WizardState[K]) => void;
    errors: Record<string, string>;
}) {
    const addAgenda = () => set('agenda_items', [...data.agenda_items, '']);
    const updAgenda = (i: number, v: string) =>
        set(
            'agenda_items',
            data.agenda_items.map((a, idx) => (idx === i ? v : a)),
        );
    const rmAgenda = (i: number) =>
        set(
            'agenda_items',
            data.agenda_items.filter((_, idx) => idx !== i),
        );

    return (
        <div>
            <StepHead
                icon={CalendarClock}
                title="When and where?"
                blurb="Set the date, time and location, and outline the agenda so attendees know what to expect."
            />
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label="Date & time"
                    required
                    error={errors.scheduled_at}
                >
                    <Input
                        type="datetime-local"
                        value={data.scheduled_at}
                        onChange={(e) => set('scheduled_at', e.target.value)}
                        aria-invalid={!!errors.scheduled_at}
                    />
                </Field>
                <Field label="Location" hint="room, site or video link">
                    <div className="relative">
                        <MapPin className="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={data.location}
                            onChange={(e) => set('location', e.target.value)}
                            placeholder="e.g. Staff room / Teams"
                            className="pl-9"
                        />
                    </div>
                </Field>

                <Field
                    label="Agenda items"
                    span
                    hint="optional — one line per item"
                >
                    <div className="grid gap-2">
                        {data.agenda_items.length === 0 ? (
                            <div className="rounded-lg border border-dashed border-border p-3.5 text-center text-[13px] text-muted-foreground">
                                No agenda items yet. Add the matters this meeting
                                will cover.
                            </div>
                        ) : null}
                        {data.agenda_items.map((item, i) => (
                            <div key={i} className="flex items-center gap-2">
                                <span className="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-muted text-[11px] font-bold text-muted-foreground">
                                    {i + 1}
                                </span>
                                <Input
                                    value={item}
                                    onChange={(e) => updAgenda(i, e.target.value)}
                                    placeholder={`Agenda item ${i + 1}`}
                                />
                                {/* eslint-disable-next-line no-restricted-syntax -- icon-only remove affordance for a repeater row (mirrors the Add-Client condition rows) */}
                                <button
                                    type="button"
                                    aria-label="Remove agenda item"
                                    onClick={() => rmAgenda(i)}
                                    className="shrink-0 text-muted-foreground transition-colors hover:text-status-critical"
                                >
                                    <Trash2 className="h-4 w-4" />
                                </button>
                            </div>
                        ))}
                        <div>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={addAgenda}
                            >
                                <Plus className="h-3.5 w-3.5" /> Add agenda item
                            </Button>
                        </div>
                    </div>
                </Field>

                <InfoCard icon={ListChecks}>
                    Standing items such as incident review, hazard register and
                    open action items are good to include every meeting. You can
                    refine the agenda and capture minutes after the meeting.
                </InfoCard>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Step 3 — Attendees                                                 */
/* ------------------------------------------------------------------ */

function StepAttendees({
    data,
    set,
    staff,
    committeeMemberIds,
    committeeName,
}: {
    data: WizardState;
    set: <K extends keyof WizardState>(k: K, v: WizardState[K]) => void;
    staff: Option[];
    committeeMemberIds: number[] | null;
    committeeName: string | null;
}) {
    const allSelected = data.attendee_ids.length === staff.length && staff.length > 0;
    const toggleAll = () =>
        set('attendee_ids', allSelected ? [] : staff.map((s) => s.id));
    const seedFromMembers =
        committeeMemberIds && committeeMemberIds.length
            ? () => set('attendee_ids', committeeMemberIds)
            : null;

    return (
        <div>
            <StepHead
                icon={UserCog}
                title="Who is expected?"
                blurb={
                    committeeName
                        ? `Select the kaimahi expected at this ${committeeName} meeting.`
                        : 'Select the kaimahi expected at this meeting.'
                }
            />
            <div className="grid gap-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="text-[13px] text-muted-foreground">
                        {data.attendee_ids.length} selected
                    </span>
                    <div className="flex items-center gap-2">
                        {seedFromMembers ? (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={seedFromMembers}
                            >
                                <Users className="h-3.5 w-3.5" /> Use committee
                                members
                            </Button>
                        ) : null}
                        {staff.length ? (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={toggleAll}
                            >
                                {allSelected ? (
                                    <>
                                        <X className="h-3.5 w-3.5" /> Clear all
                                    </>
                                ) : (
                                    <>
                                        <Check className="h-3.5 w-3.5" /> Select
                                        all
                                    </>
                                )}
                            </Button>
                        ) : null}
                    </div>
                </div>

                {staff.length ? (
                    <Field label="Expected attendees">
                        <ChipMulti
                            values={idsToNames(data.attendee_ids, staff)}
                            onChange={(names) =>
                                set('attendee_ids', namesToIds(names, staff))
                            }
                            options={staff.map((s) => s.name)}
                        />
                    </Field>
                ) : (
                    <InfoCard icon={Info} tone="warn">
                        No staff are available to add as attendees.
                    </InfoCard>
                )}

                <InfoCard icon={Info}>
                    Expected attendees are recorded against the meeting now.
                    Actual attendance is confirmed when the meeting is completed.
                </InfoCard>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Step 4 — Review                                                    */
/* ------------------------------------------------------------------ */

function StepReview({
    data,
    pct,
    sites,
    staff,
    selectedCommittee,
    goToStep,
}: {
    data: WizardState;
    pct: number;
    sites: Option[];
    staff: Option[];
    selectedCommittee: CommitteeOption | null;
    goToStep: (key: StepKey) => void;
}) {
    const committeeName =
        data.mode === 'existing'
            ? selectedCommittee?.name ?? '—'
            : data.new_name || '—';
    const siteName =
        data.mode === 'existing'
            ? selectedCommittee?.site_id != null
                ? sites.find((s) => s.id === selectedCommittee.site_id)?.name ?? null
                : null
            : sites.find((s) => String(s.id) === data.new_site_id)?.name ?? null;
    const attendeeNames = idsToNames(data.attendee_ids, staff);
    const memberNames = idsToNames(data.new_member_ids, staff);

    return (
        <div>
            <StepHead
                icon={Check}
                title="Review & schedule"
                blurb="Confirm the details below. You can jump back to any step to make changes."
            />

            {/* eslint-disable-next-line no-restricted-syntax -- bespoke review summary banner pairing the completeness Ring with the meeting headline */}
            <div className="mb-5 flex items-center gap-4 rounded-xl border border-border bg-card/70 p-4">
                <Ring pct={pct} />
                <div>
                    <div className="text-sm font-bold">
                        {committeeName} · meeting
                    </div>
                    <div className="text-[13px] text-muted-foreground">
                        {fmtDateTime(data.scheduled_at)}
                        {data.location ? ` · ${data.location}` : ''}
                    </div>
                </div>
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
                <ReviewCard
                    icon={Building2}
                    title="Committee"
                    onEdit={() => goToStep('committee')}
                >
                    <ReviewRow
                        label="Committee"
                        value={
                            <span className="inline-flex items-center gap-1.5">
                                {committeeName}
                                {data.mode === 'new' ? (
                                    <span className="inline-flex items-center rounded-full bg-primary/10 px-1.5 py-0.5 text-[10px] font-semibold text-primary">
                                        New
                                    </span>
                                ) : null}
                            </span>
                        }
                    />
                    <ReviewRow label="Site" value={siteName} />
                    {data.mode === 'new' ? (
                        <>
                            <ReviewRow
                                label="Frequency"
                                value={FREQUENCY_LABEL(data.new_frequency)}
                            />
                            <ReviewRow
                                label="Members"
                                value={
                                    memberNames.length
                                        ? `${memberNames.length} · ${memberNames.join(', ')}`
                                        : undefined
                                }
                            />
                        </>
                    ) : selectedCommittee ? (
                        <ReviewRow
                            label="Frequency"
                            value={FREQUENCY_LABEL(
                                selectedCommittee.meeting_frequency,
                            )}
                        />
                    ) : null}
                </ReviewCard>

                <ReviewCard
                    icon={CalendarClock}
                    title="Schedule"
                    onEdit={() => goToStep('schedule')}
                >
                    <ReviewRow
                        label="Date & time"
                        value={fmtDateTime(data.scheduled_at)}
                    />
                    <ReviewRow label="Location" value={data.location} />
                    <ReviewRow
                        label="Agenda"
                        value={
                            data.agenda_items.filter((a) => a.trim()).length
                                ? `${data.agenda_items.filter((a) => a.trim()).length} item${data.agenda_items.filter((a) => a.trim()).length === 1 ? '' : 's'}`
                                : undefined
                        }
                    />
                </ReviewCard>

                <ReviewCard
                    icon={UserCog}
                    title="Attendees"
                    onEdit={() => goToStep('attendees')}
                    span
                >
                    <ReviewRow
                        label="Expected"
                        value={
                            attendeeNames.length
                                ? `${attendeeNames.length} · ${attendeeNames.join(', ')}`
                                : undefined
                        }
                    />
                </ReviewCard>
            </div>
        </div>
    );
}
