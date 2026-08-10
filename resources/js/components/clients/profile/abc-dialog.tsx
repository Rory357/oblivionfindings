/* Bespoke Antecedent → Behaviour → Consequence (ABC) wizard for the client
 * profile Behaviour / ABC tab. ONE modal, two modes, both rendered through the
 * shared WizardShell so they match the Add Client UX:
 *   - create  (no entry passed) → log a new ABC record
 *   - manage  (entry passed)    → lazy-fetch the full record, edit or delete
 * Writes go through Inertia (router.post/put/delete → back()), so the page's
 * behaviour_patterns analytics refresh; the tab's lazy-fetched ABC log refreshes
 * on close. Endpoints: BehaviourAbcController. */
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    ChipMulti,
    Field,
    InfoCard,
    Segmented,
    StepHead,
    TilePicker,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    type WizardStep,
} from '@/components/wizard/shell';
import type { FormDataConvertible } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    Check,
    ChevronLeft,
    ChevronRight,
    CircleHelp,
    Gauge,
    Hand,
    LifeBuoy,
    ListOrdered,
    Loader2,
    LogOut,
    MapPin,
    Sparkles,
    Stethoscope,
    Trash2,
    Users,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';

/* ------------------------------------------------------------------ types */

export type AbcEntryRow = {
    id: number;
    occurred_at?: string | null;
    setting?: string | null;
    antecedent?: string | null;
    behaviour?: string | null;
    consequence?: string | null;
    behaviour_tags?: string[];
    behaviour_function?: string | null;
    behaviour_function_label?: string | null;
    intensity?: string | null;
    duration_seconds?: number | null;
    harm_occurred?: boolean;
    escalated?: boolean;
    requires_followup?: boolean;
    followup_completed?: boolean;
    recorder?: { id: number; name: string } | null;
};

type AbcDetail = AbcEntryRow & {
    occurred_at_local?: string | null;
    others_present?: string | null;
    strategies_used?: string | null;
    harm_notes?: string | null;
    followup_notes?: string | null;
    followup_completed_at?: string | null;
    linked_care_plan_id?: number | null;
    linked_care_plan?: { id: number; title: string } | null;
};

const FUNCTIONS: {
    key: string;
    label: string;
    description: string;
    icon: typeof LogOut;
}[] = [
    {
        key: 'escape_avoidance',
        label: 'Escape / avoidance',
        description: 'Getting away from a demand, task or situation',
        icon: LogOut,
    },
    {
        key: 'attention_social',
        label: 'Attention / social',
        description: 'Gaining attention or social interaction',
        icon: Users,
    },
    {
        key: 'tangible_access',
        label: 'Tangible / access',
        description: 'Obtaining an item, activity or outcome',
        icon: Hand,
    },
    {
        key: 'sensory_automatic',
        label: 'Sensory / automatic',
        description: 'Self-stimulation or sensory regulation',
        icon: Sparkles,
    },
    {
        key: 'other',
        label: 'Other / unclear',
        description: 'Function not yet clear',
        icon: CircleHelp,
    },
];

const BEHAVIOUR_TAGS = [
    'Verbal',
    'Physical',
    'Self-injury',
    'Property',
    'Withdrawal',
    'Pacing',
    'Absconding',
    'Refusal',
];

const INTENSITY: { value: string; label: string }[] = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Moderate' },
    { value: 'high', label: 'High' },
];

const str = (v: unknown): string => String(v ?? '').trim();
const opt = (v: unknown): string | undefined => (str(v) ? str(v) : undefined);

function localNow(): string {
    const d = new Date();
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

const RAIL_STEPS: WizardStep[] = [
    {
        key: 'context',
        label: 'Context',
        blurb: 'When, where, who',
        icon: MapPin,
    },
    {
        key: 'abc',
        label: 'A · B · C',
        blurb: 'Antecedent → Consequence',
        icon: ListOrdered,
    },
    {
        key: 'analysis',
        label: 'Analysis',
        blurb: 'Function & intensity',
        icon: Gauge,
    },
    {
        key: 'response',
        label: 'Response',
        blurb: 'Strategies & follow-up',
        icon: LifeBuoy,
    },
    {
        key: '__review',
        label: 'Review & save',
        blurb: 'Confirm and save',
        icon: Check,
    },
];

/* --------------------------------------------------------------- component */

export function AbcEntryDialog({
    open,
    onClose,
    clientId,
    clientLabel,
    preferredName,
    entry,
    carePlanId,
    carePlanTitle,
}: {
    open: boolean;
    onClose: () => void;
    clientId: number;
    clientLabel?: string;
    preferredName?: string;
    /** Present → manage an existing entry; absent → create a new one. */
    entry?: AbcEntryRow | null;
    carePlanId?: number | null;
    carePlanTitle?: string | null;
}) {
    const managing = Boolean(entry);
    const base = `/clients/${clientId}/behaviour/abc`;
    const who = preferredName || 'the person';

    const [stepIndex, setStepIndex] = useState(0);
    const [busy, setBusy] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [loading, setLoading] = useState(false);

    /* form state (shared by create + edit) */
    const [occurredAt, setOccurredAt] = useState(localNow());
    const [setting, setSetting] = useState('');
    const [othersPresent, setOthersPresent] = useState('');
    const [antecedent, setAntecedent] = useState('');
    const [behaviour, setBehaviour] = useState('');
    const [consequence, setConsequence] = useState('');
    const [behaviourFunction, setBehaviourFunction] = useState('');
    const [tags, setTags] = useState<string[]>([]);
    const [intensity, setIntensity] = useState('low');
    const [durationMin, setDurationMin] = useState('');
    const [strategies, setStrategies] = useState('');
    const [harmOccurred, setHarmOccurred] = useState(false);
    const [harmNotes, setHarmNotes] = useState('');
    const [escalated, setEscalated] = useState(false);
    const [requiresFollowup, setRequiresFollowup] = useState(false);
    const [followupNotes, setFollowupNotes] = useState('');
    const [linkedCarePlanId, setLinkedCarePlanId] = useState<number | null>(
        null,
    );
    /* manage-only */
    const [followupCompleted, setFollowupCompleted] = useState(false);
    const [followupAlreadyDone, setFollowupAlreadyDone] = useState(false);

    const resetCreate = useCallback(() => {
        setOccurredAt(localNow());
        setSetting('');
        setOthersPresent('');
        setAntecedent('');
        setBehaviour('');
        setConsequence('');
        setBehaviourFunction('');
        setTags([]);
        setIntensity('low');
        setDurationMin('');
        setStrategies('');
        setHarmOccurred(false);
        setHarmNotes('');
        setEscalated(false);
        setRequiresFollowup(false);
        setFollowupNotes('');
        setLinkedCarePlanId(null);
        setFollowupCompleted(false);
        setFollowupAlreadyDone(false);
    }, []);

    const hydrate = useCallback((d: AbcDetail) => {
        setOccurredAt(d.occurred_at_local || localNow());
        setSetting(d.setting ?? '');
        setOthersPresent(d.others_present ?? '');
        setAntecedent(d.antecedent ?? '');
        setBehaviour(d.behaviour ?? '');
        setConsequence(d.consequence ?? '');
        setBehaviourFunction(d.behaviour_function ?? '');
        setTags(Array.isArray(d.behaviour_tags) ? d.behaviour_tags : []);
        setIntensity(d.intensity ?? 'low');
        setDurationMin(
            d.duration_seconds
                ? String(Math.round(d.duration_seconds / 60))
                : '',
        );
        setStrategies(d.strategies_used ?? '');
        setHarmOccurred(Boolean(d.harm_occurred));
        setHarmNotes(d.harm_notes ?? '');
        setEscalated(Boolean(d.escalated));
        setRequiresFollowup(Boolean(d.requires_followup));
        setFollowupNotes(d.followup_notes ?? '');
        setLinkedCarePlanId(d.linked_care_plan_id ?? null);
        setFollowupCompleted(false);
        setFollowupAlreadyDone(Boolean(d.followup_completed));
    }, []);

    const refetch = useCallback(async () => {
        if (!entry) return;
        try {
            const res = await fetch(`${base}/${entry.id}`, {
                headers: { Accept: 'application/json' },
            });
            if (!res.ok) throw new Error('load failed');
            hydrate((await res.json()) as AbcDetail);
        } catch {
            toast.error('Could not load the ABC entry.');
        }
    }, [base, entry, hydrate]);

    // Re-seed whenever the dialog (re)opens.
    useEffect(() => {
        if (!open) return;
        setStepIndex(0);
        setBusy(false);
        if (managing) {
            setLoading(true);
            void refetch().finally(() => setLoading(false));
        } else {
            resetCreate();
        }
    }, [open, managing, refetch, resetCreate]);

    /* ---- Inertia mutation helper (refreshes behaviour_patterns analytics) ---- */
    const mutate = useCallback(
        (
            method: 'post' | 'put' | 'delete',
            url: string,
            payload: Record<string, FormDataConvertible> = {},
            o: { okToast?: string; close?: boolean } = {},
        ) => {
            setBusy(true);
            const options = {
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page: { props: Record<string, unknown> }) => {
                    setBusy(false);
                    const flash = (page.props as { flash?: { error?: string } })
                        .flash;
                    if (flash?.error) {
                        toast.error(flash.error);
                        return;
                    }
                    if (o.okToast) toast.success(o.okToast);
                    if (o.close) onClose();
                },
                onError: (errors: Record<string, string>) => {
                    setBusy(false);
                    const first = Object.values(errors ?? {})[0];
                    toast.error(
                        first ? String(first) : 'Something went wrong.',
                    );
                },
            };
            if (method === 'delete') {
                router.delete(url, options);
            } else {
                router[method](url, payload, options);
            }
        },
        [onClose],
    );

    const durationSeconds = durationMin
        ? Math.round(Number(durationMin) * 60)
        : undefined;

    // Omit empty optionals (undefined → dropped by Inertia) so `nullable`
    // enum/number rules don't choke on empty strings.
    const payload = (): Record<string, FormDataConvertible> => ({
        occurred_at: str(occurredAt),
        setting: opt(setting),
        others_present: opt(othersPresent),
        antecedent: str(antecedent),
        behaviour: str(behaviour),
        consequence: str(consequence),
        behaviour_tags: tags,
        behaviour_function: behaviourFunction || undefined,
        intensity,
        duration_seconds: durationSeconds,
        strategies_used: opt(strategies),
        harm_occurred: harmOccurred,
        harm_notes: harmOccurred ? opt(harmNotes) : undefined,
        escalated,
        requires_followup: requiresFollowup,
        followup_notes: requiresFollowup ? opt(followupNotes) : undefined,
        linked_care_plan_id: linkedCarePlanId ?? undefined,
        ...(managing ? { followup_completed: followupCompleted } : {}),
    });

    const abcValid = Boolean(
        str(antecedent) && str(behaviour) && str(consequence),
    );
    const canSubmit = Boolean(str(occurredAt) && abcValid && intensity);

    const filled = [
        occurredAt,
        antecedent,
        behaviour,
        consequence,
        behaviourFunction,
    ].filter((v) => str(v) !== '').length;
    const pct = Math.round((filled / 5) * 100);

    const submit = () => {
        if (!canSubmit) return;
        if (managing && entry) {
            mutate('put', `${base}/${entry.id}`, payload(), {
                okToast: 'ABC entry updated',
                close: true,
            });
        } else {
            mutate('post', base, payload(), {
                okToast: 'ABC entry saved',
                close: true,
            });
        }
    };

    const remove = () => {
        if (!entry) return;
        mutate(
            'delete',
            `${base}/${entry.id}`,
            {},
            { okToast: 'ABC entry removed', close: true },
        );
    };

    /* ----------------------------------------------------------- footer */

    const lastIndex = RAIL_STEPS.length - 1;
    const reviewing = stepIndex === lastIndex;
    const goBack = () => setStepIndex((i) => Math.max(0, i - 1));
    const goNext = () => setStepIndex((i) => Math.min(lastIndex, i + 1));

    const navBack =
        stepIndex > 0 ? (
            <Button
                type="button"
                variant="ghost"
                onClick={goBack}
                disabled={busy}
            >
                <ChevronLeft className="mr-1 h-4 w-4" /> Back
            </Button>
        ) : null;

    const footerStart =
        managing && reviewing ? (
            <div className="flex items-center gap-3">
                {navBack}
                <Button
                    type="button"
                    variant="ghost"
                    onClick={() => setDeleteOpen(true)}
                    disabled={busy}
                    className="text-status-critical hover:text-status-critical"
                    data-test="abc-delete"
                >
                    <Trash2 className="mr-1.5 h-4 w-4" /> Delete entry
                </Button>
            </div>
        ) : (
            navBack
        );

    const footerEnd = (
        <>
            <Button
                type="button"
                variant="outline"
                onClick={onClose}
                disabled={busy}
            >
                {managing ? 'Close' : 'Cancel'}
            </Button>
            {reviewing ? (
                <Button
                    type="button"
                    onClick={submit}
                    disabled={busy || !canSubmit}
                    data-test="abc-submit"
                >
                    {busy ? (
                        <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                    ) : (
                        <Check className="mr-1.5 h-4 w-4" />
                    )}
                    {busy
                        ? 'Saving…'
                        : managing
                          ? 'Save changes'
                          : 'Save ABC entry'}
                </Button>
            ) : (
                <Button
                    type="button"
                    onClick={goNext}
                    disabled={(stepIndex === 1 && !abcValid) || loading}
                    data-test="abc-continue"
                >
                    Continue <ChevronRight className="ml-1 h-4 w-4" />
                </Button>
            )}
        </>
    );

    const stepKey = RAIL_STEPS[stepIndex]?.key;
    const fnLabel = FUNCTIONS.find((f) => f.key === behaviourFunction)?.label;

    return (
        <>
            <WizardShell
                open={open}
                onClose={() => !busy && onClose()}
                title={managing ? 'Manage ABC entry' : 'New ABC entry'}
                description="Record an Antecedent → Behaviour → Consequence observation"
                railIcon={Stethoscope}
                railTitle={managing ? 'ABC entry' : 'New ABC entry'}
                railSub="Behaviour support"
                steps={RAIL_STEPS}
                stepIndex={stepIndex}
                onStepClick={(i) => setStepIndex(i)}
                pct={pct}
                pctLabel="Completeness"
                footerStart={footerStart}
                footerEnd={footerEnd}
            >
                {loading ? (
                    <div className="flex h-40 items-center justify-center text-sm text-muted-foreground">
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />{' '}
                        Loading entry…
                    </div>
                ) : stepKey === 'context' ? (
                    <WizardStepPane key="context">
                        <StepHead
                            icon={MapPin}
                            title="Setting the scene"
                            blurb="When and where the behaviour happened, and who was around."
                        />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Date & time" required>
                                <Input
                                    type="datetime-local"
                                    value={occurredAt}
                                    onChange={(e) =>
                                        setOccurredAt(e.target.value)
                                    }
                                    data-test="abc-occurred-at"
                                />
                            </Field>
                            <Field label="Setting" hint="where / activity">
                                <Input
                                    value={setting}
                                    onChange={(e) => setSetting(e.target.value)}
                                    placeholder="e.g. Dining room at dinner"
                                />
                            </Field>
                            <Field label="Who else was present" span>
                                <Input
                                    value={othersPresent}
                                    onChange={(e) =>
                                        setOthersPresent(e.target.value)
                                    }
                                    placeholder="e.g. Two support workers, peers"
                                />
                            </Field>
                        </div>
                    </WizardStepPane>
                ) : stepKey === 'abc' ? (
                    <WizardStepPane key="abc">
                        <StepHead
                            icon={ListOrdered}
                            title="Antecedent → Behaviour → Consequence"
                            blurb="Factual and specific — describe what you saw, no interpretation."
                        />
                        <div className="grid gap-4">
                            <Field label="A — What happened before" required>
                                <Textarea
                                    rows={2}
                                    value={antecedent}
                                    onChange={(e) =>
                                        setAntecedent(e.target.value)
                                    }
                                    placeholder="The trigger or situation immediately before…"
                                    data-test="abc-antecedent"
                                />
                            </Field>
                            <Field label={`B — What ${who} did`} required>
                                <Textarea
                                    rows={2}
                                    value={behaviour}
                                    onChange={(e) =>
                                        setBehaviour(e.target.value)
                                    }
                                    placeholder="The behaviour itself, observable and specific…"
                                    data-test="abc-behaviour"
                                />
                            </Field>
                            <Field label="C — What happened after" required>
                                <Textarea
                                    rows={2}
                                    value={consequence}
                                    onChange={(e) =>
                                        setConsequence(e.target.value)
                                    }
                                    placeholder="The response and what happened next…"
                                    data-test="abc-consequence"
                                />
                            </Field>
                        </div>
                    </WizardStepPane>
                ) : stepKey === 'analysis' ? (
                    <WizardStepPane key="analysis">
                        <StepHead
                            icon={Gauge}
                            title="Analysis"
                            blurb="The likely function, behaviour type, intensity and duration."
                        />
                        <p className="mb-1.5 text-sm font-medium">
                            Likely function of the behaviour
                        </p>
                        <TilePicker
                            value={behaviourFunction}
                            onChange={setBehaviourFunction}
                            cols={2}
                            options={FUNCTIONS}
                        />
                        <div className="mt-4 grid gap-4">
                            <Field
                                label="Behaviour type"
                                hint="optional · select any"
                            >
                                <ChipMulti
                                    values={tags}
                                    onChange={setTags}
                                    options={BEHAVIOUR_TAGS}
                                />
                            </Field>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Intensity" required>
                                    <Segmented
                                        value={intensity}
                                        onChange={setIntensity}
                                        options={INTENSITY}
                                    />
                                </Field>
                                <Field label="Duration" hint="minutes">
                                    <Input
                                        type="number"
                                        min="0"
                                        value={durationMin}
                                        onChange={(e) =>
                                            setDurationMin(e.target.value)
                                        }
                                        placeholder="e.g. 6"
                                    />
                                </Field>
                            </div>
                        </div>
                    </WizardStepPane>
                ) : stepKey === 'response' ? (
                    <WizardStepPane key="response">
                        <StepHead
                            icon={LifeBuoy}
                            title="Response & follow-up"
                            blurb="What helped, whether anyone was harmed, and any follow-up needed."
                        />
                        <div className="grid gap-4">
                            <Field label="Strategies used / what worked">
                                <Textarea
                                    rows={2}
                                    value={strategies}
                                    onChange={(e) =>
                                        setStrategies(e.target.value)
                                    }
                                    placeholder="De-escalation or support that helped settle the situation…"
                                />
                            </Field>

                            <CheckRow
                                checked={harmOccurred}
                                onChange={setHarmOccurred}
                                label="Harm or injury occurred"
                                desc="Injury to the person, staff, peers or property."
                            />
                            {harmOccurred ? (
                                <Field label="Harm details">
                                    <Textarea
                                        rows={2}
                                        value={harmNotes}
                                        onChange={(e) =>
                                            setHarmNotes(e.target.value)
                                        }
                                        placeholder="What harm occurred and any first aid given…"
                                    />
                                </Field>
                            ) : null}

                            <CheckRow
                                checked={escalated}
                                onChange={setEscalated}
                                label="Escalated to on-call / manager"
                                desc="A manager or on-call was contacted."
                            />

                            <CheckRow
                                checked={requiresFollowup}
                                onChange={setRequiresFollowup}
                                label="Follow-up required"
                                desc="Flags this entry for review or action."
                            />
                            {requiresFollowup ? (
                                <Field label="Follow-up notes">
                                    <Textarea
                                        rows={2}
                                        value={followupNotes}
                                        onChange={(e) =>
                                            setFollowupNotes(e.target.value)
                                        }
                                        placeholder="What needs to happen, and by whom…"
                                    />
                                </Field>
                            ) : null}

                            {carePlanId ? (
                                <CheckRow
                                    checked={linkedCarePlanId === carePlanId}
                                    onChange={(c) =>
                                        setLinkedCarePlanId(
                                            c ? carePlanId : null,
                                        )
                                    }
                                    label="Link to behaviour support plan"
                                    desc={
                                        carePlanTitle ?? 'Active support plan'
                                    }
                                />
                            ) : null}

                            {managing && followupAlreadyDone ? (
                                <InfoCard icon={Check} tone="info">
                                    Follow-up was already marked complete.
                                </InfoCard>
                            ) : managing && requiresFollowup ? (
                                <CheckRow
                                    checked={followupCompleted}
                                    onChange={setFollowupCompleted}
                                    label="Mark follow-up as complete"
                                    desc="Records who closed it out and when."
                                />
                            ) : null}
                        </div>
                    </WizardStepPane>
                ) : (
                    <WizardStepPane key="review">
                        <StepHead
                            icon={Check}
                            title="Review & save"
                            blurb="Check the record, then save."
                        />
                        {clientLabel ? (
                            <div className="mb-4 inline-flex items-center gap-2 rounded-full bg-primary/10 px-3 py-1 text-[13px] font-semibold text-primary">
                                <Stethoscope className="h-3.5 w-3.5" />{' '}
                                {clientLabel}
                            </div>
                        ) : null}
                        {!canSubmit ? (
                            <InfoCard icon={AlertTriangle} tone="warn">
                                Add the date and the full A · B · C before
                                saving.
                            </InfoCard>
                        ) : null}
                        <div className="grid gap-3 sm:grid-cols-2">
                            <ReviewCard
                                icon={MapPin}
                                title="Context"
                                onEdit={() => setStepIndex(0)}
                            >
                                <div className="space-y-1.5 text-sm">
                                    <ReviewRow
                                        label="When"
                                        value={str(occurredAt).replace(
                                            'T',
                                            ' ',
                                        )}
                                    />
                                    <ReviewRow
                                        label="Setting"
                                        value={opt(setting)}
                                    />
                                    <ReviewRow
                                        label="Present"
                                        value={opt(othersPresent)}
                                    />
                                </div>
                            </ReviewCard>
                            <ReviewCard
                                icon={Gauge}
                                title="Analysis"
                                onEdit={() => setStepIndex(2)}
                            >
                                <div className="space-y-1.5 text-sm">
                                    <ReviewRow
                                        label="Function"
                                        value={fnLabel}
                                    />
                                    <ReviewRow
                                        label="Intensity"
                                        value={intensity}
                                    />
                                    <ReviewRow
                                        label="Duration"
                                        value={
                                            durationMin
                                                ? `${durationMin} min`
                                                : undefined
                                        }
                                    />
                                    <ReviewRow
                                        label="Type"
                                        value={
                                            tags.length
                                                ? tags.join(', ')
                                                : undefined
                                        }
                                    />
                                </div>
                            </ReviewCard>
                            <ReviewCard
                                icon={ListOrdered}
                                title="A · B · C"
                                span
                                onEdit={() => setStepIndex(1)}
                            >
                                <div className="space-y-1.5 text-sm">
                                    <ReviewRow
                                        label="A"
                                        value={opt(antecedent)}
                                    />
                                    <ReviewRow
                                        label="B"
                                        value={opt(behaviour)}
                                    />
                                    <ReviewRow
                                        label="C"
                                        value={opt(consequence)}
                                    />
                                </div>
                            </ReviewCard>
                            <ReviewCard
                                icon={LifeBuoy}
                                title="Response & follow-up"
                                span
                                onEdit={() => setStepIndex(3)}
                            >
                                <div className="space-y-1.5 text-sm">
                                    <ReviewRow
                                        label="What worked"
                                        value={opt(strategies)}
                                    />
                                    <ReviewRow
                                        label="Harm"
                                        value={
                                            harmOccurred
                                                ? (opt(harmNotes) ?? 'Yes')
                                                : 'None'
                                        }
                                    />
                                    <ReviewRow
                                        label="Escalated"
                                        value={escalated ? 'Yes' : 'No'}
                                    />
                                    <ReviewRow
                                        label="Follow-up"
                                        value={
                                            requiresFollowup
                                                ? (opt(followupNotes) ??
                                                  'Required')
                                                : 'None'
                                        }
                                    />
                                </div>
                            </ReviewCard>
                        </div>
                    </WizardStepPane>
                )}
            </WizardShell>
            <ConfirmDialog
                open={deleteOpen}
                onClose={() => setDeleteOpen(false)}
                onConfirm={remove}
                title="Remove ABC entry?"
                description="This permanently removes the ABC entry. This action cannot be undone."
                confirmText="Remove entry"
            />
        </>
    );
}

/* A labelled checkbox row matching the wizard field rhythm. */
function CheckRow({
    checked,
    onChange,
    label,
    desc,
}: {
    checked: boolean;
    onChange: (v: boolean) => void;
    label: string;
    desc?: string;
}) {
    return (
        // eslint-disable-next-line no-restricted-syntax -- compact toggle row inside the wizard body.
        <label className="flex cursor-pointer items-start gap-3 rounded-lg border border-border bg-card/50 p-3">
            <Checkbox
                checked={checked}
                onCheckedChange={(c) => onChange(c === true)}
                className="mt-0.5"
            />
            <span className="min-w-0">
                <span className="block text-sm font-medium">{label}</span>
                {desc ? (
                    <span className="mt-0.5 block text-xs text-muted-foreground">
                        {desc}
                    </span>
                ) : null}
            </span>
        </label>
    );
}
