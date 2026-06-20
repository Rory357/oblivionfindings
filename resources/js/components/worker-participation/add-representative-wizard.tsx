/* Worker Participation — Add Representative wizard.
 *
 * The REFERENCE create wizard for the register (the Schedule Meeting + New
 * Consultation wizards mirror this one). Consumes the shared WizardShell chrome
 * (stepper rail + scroll-contained body + custom footer) rather than re-inlining
 * the Add-client modal; every colour is a semantic design token. en-NZ dates via
 * the shared fmtDate. Posts to WorkerParticipationController@storeRepresentative.
 *
 * STEPS: 1 Who · 2 Election · 3 Review → Create (or Save & add another). */
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/wizard/shell';
import {
    Field,
    InfoCard,
    Ring,
    SelectInput,
    StepHead,
    TilePicker,
} from '@/components/wizard/primitives';
import {
    ELECTION_METHODS,
    WP_BASE,
    fmtDate,
} from '@/components/worker-participation/shared';
import { useForm } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    GraduationCap,
    Loader2,
    MapPin,
    Plus,
    UserCheck,
    Users,
    Vote,
} from 'lucide-react';
import { useState } from 'react';

/* ------------------------------------------------------------------ */
/*  Props + form shape                                                  */
/* ------------------------------------------------------------------ */

type Props = {
    open: boolean;
    sites: { id: number; name: string }[];
    staff: { id: number; name: string }[];
    onClose: () => void;
};

type RepForm = {
    user_id: string;
    site_id: string;
    work_group: string;
    election_method: string;
    elected_at: string;
    term_expires_at: string;
    training_days_completed: number;
    initial_training_completed_at: string;
    notes: string;
};

const EMPTY: RepForm = {
    user_id: '',
    site_id: '',
    work_group: '',
    election_method: 'elected',
    elected_at: '',
    term_expires_at: '',
    training_days_completed: 0,
    initial_training_completed_at: '',
    notes: '',
};

const STEPS: WizardStep[] = [
    { key: 'who', label: 'Who', blurb: 'Person & site', icon: Users },
    { key: 'election', label: 'Election', blurb: 'Method & term', icon: Vote },
    { key: 'review', label: 'Review', blurb: 'Confirm & create', icon: UserCheck },
];

/* Map each server-validated field back to the step that owns it, so an
 * onError response jumps straight to the first failing field. */
const STEP_OF: Record<string, number> = {
    user_id: 0,
    site_id: 0,
    work_group: 0,
    election_method: 1,
    elected_at: 1,
    term_expires_at: 1,
    training_days_completed: 1,
    initial_training_completed_at: 1,
    notes: 1,
};

/* Mirror StoreRepresentativeRequest gating rules, keyed by step index. */
function validateStep(step: number, data: RepForm): Record<string, string> {
    const e: Record<string, string> = {};
    if (step === 0) {
        if (!data.user_id) e.user_id = 'Choose the staff member who is the representative.';
        if (!data.site_id) e.site_id = 'Select the site this representative covers.';
    }
    if (step === 1) {
        if (!data.elected_at) e.elected_at = 'Record the date elected / appointed.';
        if (data.term_expires_at && data.elected_at && data.term_expires_at <= data.elected_at)
            e.term_expires_at = 'Term expiry must be after the elected date.';
        if (!Number.isInteger(Number(data.training_days_completed)))
            e.training_days_completed = 'Enter whole training days.';
    }
    return e;
}

/* The shared textarea recipe (matches the detail-dialog panes). */
function Textarea(props: React.TextareaHTMLAttributes<HTMLTextAreaElement>) {
    return (
        <textarea
            className="w-full rounded-lg border border-border bg-background p-2.5 text-sm focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
            rows={3}
            {...props}
        />
    );
}

/* ------------------------------------------------------------------ */
/*  Wizard                                                              */
/* ------------------------------------------------------------------ */

export function AddRepresentativeWizard({ open, sites, staff, onClose }: Props) {
    const form = useForm<RepForm>({ ...EMPTY });
    const { data, setData, processing, errors } = form;

    const [stepIndex, setStepIndex] = useState(0);
    const [localErrors, setLocalErrors] = useState<Record<string, string>>({});
    const [done, setDone] = useState(false);

    const err = (name: keyof RepForm): string | undefined =>
        localErrors[name] ?? (errors as Record<string, string>)[name];

    const staffOpts = staff.map((s) => ({ value: String(s.id), label: s.name }));
    const siteOpts = sites.map((s) => ({ value: String(s.id), label: s.name }));
    const staffName = staff.find((s) => String(s.id) === data.user_id)?.name ?? '';
    const siteName = sites.find((s) => String(s.id) === data.site_id)?.name ?? '';
    const methodLabel =
        ELECTION_METHODS.find((m) => m.key === data.election_method)?.label ?? data.election_method;

    /* Rough completeness for the rail ring (8 meaningful fields). */
    const filled = [
        data.user_id,
        data.site_id,
        data.work_group,
        data.election_method,
        data.elected_at,
        data.term_expires_at,
        data.training_days_completed > 0,
        data.notes,
    ].filter(Boolean).length;
    const pct = Math.round((filled / 8) * 100);

    const goStep = (i: number) => {
        setLocalErrors({});
        setStepIndex(i);
    };
    const next = () => {
        const e = validateStep(stepIndex, data);
        setLocalErrors(e);
        if (Object.keys(e).length) return;
        setStepIndex((i) => Math.min(i + 1, STEPS.length - 1));
    };
    const back = () => setStepIndex((i) => Math.max(i - 1, 0));

    const resetAll = () => {
        form.reset();
        form.clearErrors();
        setData({ ...EMPTY });
        setLocalErrors({});
        setStepIndex(0);
        setDone(false);
    };

    const submit = (addAnother: boolean) => {
        // Re-validate every gating step; jump to the first that fails.
        const all: Record<string, string> = {};
        for (let i = 0; i < STEPS.length; i += 1) Object.assign(all, validateStep(i, data));
        if (Object.keys(all).length) {
            setLocalErrors(all);
            const firstStep = [0, 1].find((i) => Object.keys(validateStep(i, data)).length);
            if (firstStep != null) setStepIndex(firstStep);
            return;
        }
        setLocalErrors({});
        // Server rule is integer (min:0,max:30) — coerce half-days in the posted
        // payload (transform mutates the body at post time, unlike async setData).
        form.transform((payload) => ({
            ...payload,
            training_days_completed: Math.round(Number(payload.training_days_completed) || 0),
        }));
        form.post(`${WP_BASE}/representatives`, {
            preserveScroll: true,
            preserveState: true,
            onError: (errs: Record<string, string>) => {
                // Jump to the step that owns the first failing field.
                const first = Object.keys(errs)[0];
                if (first) setStepIndex(STEP_OF[first] ?? 1);
            },
            onSuccess: (page) => {
                const flash = page.props.flash as { error?: string } | undefined;
                if (flash?.error) return;
                if (addAnother) resetAll();
                else setDone(true);
            },
        });
    };

    /* ---- Success pane ---- */
    if (done) {
        return (
            <WizardShell
                open={open}
                onClose={onClose}
                title="Representative added"
                description="The H&S representative has been added to the register."
                railIcon={Users}
                railTitle="Add representative"
                railSub="Worker participation"
                steps={STEPS}
                stepIndex={STEPS.length - 1}
                onStepClick={() => {}}
                success={
                    <WizardSuccessPane
                        title="Representative added"
                        blurb={
                            <>
                                <span className="font-semibold text-foreground">{staffName || 'The representative'}</span>{' '}
                                is now serving on the worker-participation register. Record their training days and term as
                                they progress through the kaupapa.
                            </>
                        }
                        actions={
                            <>
                                <Button variant="outline" onClick={resetAll}>
                                    <Plus className="mr-1.5 h-4 w-4" /> Add another
                                </Button>
                                <Button onClick={onClose}>Done</Button>
                            </>
                        }
                    />
                }
            />
        );
    }

    /* ---- Footer ---- */
    const isLast = stepIndex === STEPS.length - 1;
    const footerStart =
        stepIndex > 0 ? (
            <Button variant="ghost" size="sm" onClick={back} disabled={processing}>
                <ChevronLeft className="mr-1 h-4 w-4" /> Back
            </Button>
        ) : null;
    const footerEnd = (
        <>
            <Button variant="outline" size="sm" onClick={onClose} disabled={processing}>
                Cancel
            </Button>
            {isLast ? (
                <>
                    <Button variant="outline" size="sm" onClick={() => submit(true)} disabled={processing}>
                        {processing ? <Loader2 className="mr-1.5 h-4 w-4 animate-spin" /> : <Plus className="mr-1.5 h-4 w-4" />}
                        Save &amp; add another
                    </Button>
                    <Button size="sm" onClick={() => submit(false)} disabled={processing}>
                        {processing ? <Loader2 className="mr-1.5 h-4 w-4 animate-spin" /> : <UserCheck className="mr-1.5 h-4 w-4" />}
                        Create representative
                    </Button>
                </>
            ) : (
                <Button size="sm" onClick={next} disabled={processing}>
                    Continue <ChevronRight className="ml-1 h-4 w-4" />
                </Button>
            )}
        </>
    );

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title="Add H&S representative"
            description="Add an elected or appointed health & safety representative to the worker-participation register."
            railIcon={Users}
            railTitle="Add representative"
            railSub="Worker participation"
            steps={STEPS}
            stepIndex={stepIndex}
            onStepClick={goStep}
            pct={pct}
            footerStart={footerStart}
            footerEnd={footerEnd}
        >
            {/* ── Step 1 · Who ── */}
            {stepIndex === 0 ? (
                <WizardStepPane>
                    <StepHead
                        icon={Users}
                        title="Who is the representative?"
                        blurb="Pick the kaimahi and the site or work area they represent under HSWA 2015."
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Staff member" required error={err('user_id')}>
                            <SelectInput
                                value={data.user_id}
                                onChange={(v) => setData('user_id', v)}
                                placeholder="Select a staff member…"
                                options={staffOpts}
                            />
                        </Field>
                        <Field label="Site" required error={err('site_id')}>
                            <SelectInput
                                value={data.site_id}
                                onChange={(v) => setData('site_id', v)}
                                placeholder="Select a site…"
                                options={siteOpts}
                            />
                        </Field>
                        <Field label="Work group" hint="optional" span error={err('work_group')}>
                            <Input
                                value={data.work_group}
                                onChange={(e) => setData('work_group', e.target.value)}
                                placeholder="e.g. Night shift, Community support, Kitchen"
                            />
                        </Field>
                        <InfoCard icon={Users}>
                            A work group is the group of workers the representative speaks for. Leave it blank when the rep
                            covers the whole site.
                        </InfoCard>
                    </div>
                </WizardStepPane>
            ) : null}

            {/* ── Step 2 · Election ── */}
            {stepIndex === 1 ? (
                <WizardStepPane>
                    <StepHead
                        icon={Vote}
                        title="How were they selected?"
                        blurb="Record how the representative was chosen, when their term started, and their paid training."
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Selection method" required span error={err('election_method')}>
                            <TilePicker
                                value={data.election_method}
                                onChange={(v) => setData('election_method', v)}
                                options={ELECTION_METHODS.map((m) => ({
                                    key: m.key,
                                    label: m.label,
                                    description: m.description,
                                }))}
                                cols={3}
                            />
                        </Field>
                        <Field label="Date elected / appointed" required error={err('elected_at')}>
                            <Input
                                type="date"
                                value={data.elected_at}
                                onChange={(e) => setData('elected_at', e.target.value)}
                            />
                        </Field>
                        <Field label="Term expires" hint="optional" error={err('term_expires_at')}>
                            <Input
                                type="date"
                                min={data.elected_at || undefined}
                                value={data.term_expires_at}
                                onChange={(e) => setData('term_expires_at', e.target.value)}
                            />
                        </Field>
                        <InfoCard icon={Vote}>
                            An HSR&rsquo;s term is capped at 3 years (re-electable). Leave the expiry blank if no fixed term
                            has been agreed.
                        </InfoCard>
                        <Field label="Training days completed" hint="paid days" error={err('training_days_completed')}>
                            <Input
                                type="number"
                                min={0}
                                max={30}
                                step={1}
                                value={String(data.training_days_completed)}
                                onChange={(e) =>
                                    setData('training_days_completed', e.target.value === '' ? 0 : Number(e.target.value))
                                }
                            />
                        </Field>
                        <Field label="Initial training completed" hint="NZQA US 29315 — optional" error={err('initial_training_completed_at')}>
                            <Input
                                type="date"
                                max={new Date().toISOString().slice(0, 10)}
                                value={data.initial_training_completed_at}
                                onChange={(e) => setData('initial_training_completed_at', e.target.value)}
                            />
                        </Field>
                        <InfoCard icon={GraduationCap}>
                            HSWA entitles each rep to 2 days&rsquo; paid training per year; NZQA US 29315 must be completed
                            before a rep can issue PINs or direct an unsafe-work cease. Recording the completion date adds a
                            tracked credential to the rep&rsquo;s HR record.
                        </InfoCard>
                        <Field label="Notes" hint="optional" span error={err('notes')}>
                            <Textarea
                                value={data.notes}
                                onChange={(e) => setData('notes', e.target.value)}
                                placeholder="Anything else worth recording about this representative…"
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            ) : null}

            {/* ── Step 3 · Review ── */}
            {stepIndex === 2 ? (
                <WizardStepPane>
                    <StepHead
                        icon={UserCheck}
                        title="Review & create"
                        blurb="Check the details below, then add the representative to the register."
                    />
                    {/* eslint-disable-next-line no-restricted-syntax -- completeness banner: ring + summary on one bespoke surface */}
                    <div className="mb-4 flex items-center gap-4 rounded-xl border border-border bg-card/70 p-4">
                        <Ring pct={pct} />
                        <div className="min-w-0">
                            <div className="flex items-center gap-2 text-sm font-bold">
                                <UserCheck className="h-4 w-4 text-primary" />
                                {staffName || 'New representative'}
                            </div>
                            <p className="mt-0.5 text-[13px] text-muted-foreground">
                                {siteName ? (
                                    <span className="inline-flex items-center gap-1">
                                        <MapPin className="h-3.5 w-3.5" /> {siteName}
                                    </span>
                                ) : (
                                    'Site not set'
                                )}
                                {data.work_group ? ` · ${data.work_group}` : ''}
                            </p>
                        </div>
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard icon={Users} title="Who" onEdit={() => goStep(0)}>
                            <ReviewRow label="Representative" value={staffName} />
                            <ReviewRow label="Site" value={siteName} />
                            <ReviewRow label="Work group" value={data.work_group} />
                        </ReviewCard>
                        <ReviewCard icon={Vote} title="Election" onEdit={() => goStep(1)}>
                            <ReviewRow label="Method" value={methodLabel} />
                            <ReviewRow label="Elected / appointed" value={fmtDate(data.elected_at)} />
                            <ReviewRow
                                label="Term expires"
                                value={data.term_expires_at ? fmtDate(data.term_expires_at) : undefined}
                            />
                            <ReviewRow
                                label="Training days"
                                value={`${data.training_days_completed} ${data.training_days_completed === 1 ? 'day' : 'days'}`}
                            />
                            <ReviewRow
                                label="Initial training (US 29315)"
                                value={data.initial_training_completed_at ? fmtDate(data.initial_training_completed_at) : undefined}
                            />
                        </ReviewCard>
                        {data.notes ? (
                            <ReviewCard icon={UserCheck} title="Notes" onEdit={() => goStep(1)} span>
                                <p className="text-[13px] whitespace-pre-wrap text-muted-foreground">{data.notes}</p>
                            </ReviewCard>
                        ) : null}
                    </div>
                </WizardStepPane>
            ) : null}
        </WizardShell>
    );
}
