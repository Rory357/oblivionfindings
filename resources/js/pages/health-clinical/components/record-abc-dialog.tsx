/* eslint-disable no-restricted-syntax -- Record wizard mirrors the Add-Client modal
 * chrome: styled native controls (function tiles, intensity segments, behaviour-tag
 * chips) on semantic design tokens. */
import { Button } from '@/components/ui/button';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
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
import { cn } from '@/lib/utils';
import {
    ClientPicker,
    ClinicalCardRail,
    type ClientResult,
} from '@/pages/health-clinical/components/record-wizard-shared';
import { useForm } from '@inertiajs/react';
import {
    Activity,
    Brain,
    Check,
    ChevronLeft,
    ChevronRight,
    Clock,
    Eye,
    HeartHandshake,
    Loader2,
    MapPin,
    Paperclip,
    Plus,
    ShieldAlert,
    Smile,
    Target,
    Users,
    X,
} from 'lucide-react';
import { useState, type ComponentType } from 'react';

type AbcForm = {
    occurred_at: string;
    setting: string;
    others_present: string;
    antecedent: string;
    behaviour: string;
    consequence: string;
    behaviour_tags: string[];
    behaviour_function: string;
    intensity: string;
    duration_seconds: string;
    strategies_used: string;
    harm_occurred: boolean;
    harm_notes: string;
    escalated: boolean;
    requires_followup: boolean;
    followup_notes: string;
    attachments: File[];
};

const FUNCTIONS: { key: string; label: string; description: string; icon: ComponentType<{ className?: string }> }[] = [
    { key: 'escape_avoidance', label: 'Escape / avoidance', description: 'To get away from a demand or situation', icon: ShieldAlert },
    { key: 'attention_social', label: 'Attention / social', description: 'To gain attention or interaction', icon: Users },
    { key: 'tangible_access', label: 'Tangible / access', description: 'To obtain an item, activity or outcome', icon: Target },
    { key: 'sensory_automatic', label: 'Sensory / automatic', description: 'Self-stimulation or sensory regulation', icon: Smile },
    { key: 'other', label: 'Other / unclear', description: 'Function not yet clear', icon: Eye },
];

const INTENSITY = [
    { key: 'low', label: 'Low', tone: 'text-status-success' },
    { key: 'medium', label: 'Medium', tone: 'text-status-warning' },
    { key: 'high', label: 'High', tone: 'text-status-critical' },
];

const STEPS: readonly WizardStep[] = [
    { key: 'context', label: 'Context', blurb: 'When, where & who', icon: MapPin },
    { key: 'abc', label: 'A · B · C', blurb: 'Antecedent, behaviour, consequence', icon: Brain },
    { key: 'response', label: 'Response & follow-up', blurb: 'Strategies, harm, evidence', icon: HeartHandshake },
    { key: 'review', label: 'Review', blurb: 'Confirm & record', icon: Check },
];

function nowLocal(): string {
    const d = new Date();
    const off = d.getTimezoneOffset();
    return new Date(d.getTime() - off * 60000).toISOString().slice(0, 16);
}

export type RecordAbcDialogProps = {
    open: boolean;
    onClose: () => void;
    /** Profile entry point (§8): locks the client. */
    client?: ClientResult | null;
    onSaved?: () => void;
};

export function RecordAbcDialog(props: RecordAbcDialogProps) {
    return props.open ? <Body {...props} /> : null;
}

function Body({ onClose, client, onSaved }: RecordAbcDialogProps) {
    const [picked, setPicked] = useState<ClientResult | null>(client ?? null);
    const lockedClient = client != null;
    const [done, setDone] = useState(false);
    const [stepIndex, setStepIndex] = useState(0);
    const [tagDraft, setTagDraft] = useState('');

    const form = useForm<AbcForm>({
        occurred_at: nowLocal(),
        setting: '',
        others_present: '',
        antecedent: '',
        behaviour: '',
        consequence: '',
        behaviour_tags: [],
        behaviour_function: '',
        intensity: '',
        duration_seconds: '',
        strategies_used: '',
        harm_occurred: false,
        harm_notes: '',
        escalated: false,
        requires_followup: false,
        followup_notes: '',
        attachments: [],
    });
    const { data, setData, processing } = form;

    const addTag = () => {
        const t = tagDraft.trim();
        if (t && !data.behaviour_tags.includes(t)) setData('behaviour_tags', [...data.behaviour_tags, t]);
        setTagDraft('');
    };
    const addFiles = (files: File[]) => setData('attachments', [...data.attachments, ...files]);

    const stepValid = (i: number): boolean => {
        if (i === 0) return !!picked && !!data.occurred_at;
        if (i === 1) return !!data.antecedent.trim() && !!data.behaviour.trim() && !!data.consequence.trim() && !!data.intensity;
        return true;
    };

    const next = () => stepValid(stepIndex) && setStepIndex((i) => Math.min(i + 1, STEPS.length - 1));
    const back = () => setStepIndex((i) => Math.max(i - 1, 0));

    const submit = () => {
        if (!picked) return;
        form.post(`/clients/${picked.id}/behaviour/abc`, {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setDone(true);
                onSaved?.();
            },
            onError: () => setStepIndex(1),
        });
    };

    if (done) {
        return (
            <WizardShell
                open
                onClose={onClose}
                title="ABC entry recorded"
                description="The ABC behaviour entry was recorded."
                railIcon={Brain}
                railTitle="Record ABC"
                railSub="Behaviour support"
                steps={STEPS}
                stepIndex={STEPS.length - 1}
                onStepClick={() => {}}
                success={
                    <WizardSuccessPane
                        title="ABC entry recorded"
                        blurb="Saved to the client's behaviour chart — it feeds the function-of-behaviour and intensity analytics."
                        actions={
                            <Button type="button" onClick={onClose}>
                                Done
                            </Button>
                        }
                    />
                }
            />
        );
    }

    const isReview = STEPS[stepIndex].key === 'review';

    return (
        <WizardShell
            open
            onClose={onClose}
            title="Record ABC"
            description="A guided wizard to record an antecedent–behaviour–consequence entry."
            railIcon={Brain}
            railTitle="Record ABC"
            railSub="Behaviour support"
            steps={STEPS}
            stepIndex={stepIndex}
            onStepClick={(i) => i <= stepIndex && setStepIndex(i)}
            railExtra={<ClinicalCardRail clientId={picked ? picked.id : null} />}
            footerStart={
                stepIndex > 0 ? (
                    <Button type="button" variant="ghost" onClick={back}>
                        <ChevronLeft className="h-4 w-4" /> Back
                    </Button>
                ) : null
            }
            footerEnd={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    {isReview ? (
                        <Button type="button" onClick={submit} disabled={processing}>
                            {processing ? <Loader2 className="h-4 w-4 animate-spin" /> : <Check className="h-4 w-4" />}
                            Record ABC
                        </Button>
                    ) : (
                        <Button type="button" onClick={next} disabled={!stepValid(stepIndex)}>
                            Continue <ChevronRight className="h-4 w-4" />
                        </Button>
                    )}
                </>
            }
        >
            {STEPS[stepIndex].key === 'context' ? (
                <WizardStepPane>
                    <StepHead icon={MapPin} title="Context" blurb="Who the entry is for, and when and where it happened." />
                    <div className="grid gap-4">
                        <Field label="Client" required>
                            <ClientPicker value={picked} onChange={lockedClient ? () => {} : setPicked} />
                        </Field>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Occurred at" required>
                                <Input type="datetime-local" value={data.occurred_at} onChange={(e) => setData('occurred_at', e.target.value)} />
                            </Field>
                            <Field label="Setting" hint="where / activity">
                                <Input value={data.setting} onChange={(e) => setData('setting', e.target.value)} placeholder="e.g. dining room, morning routine" />
                            </Field>
                            <Field label="Others present" span hint="who else was there">
                                <Input value={data.others_present} onChange={(e) => setData('others_present', e.target.value)} placeholder="e.g. two staff, one peer" />
                            </Field>
                        </div>
                    </div>
                </WizardStepPane>
            ) : null}

            {STEPS[stepIndex].key === 'abc' ? (
                <WizardStepPane>
                    <StepHead icon={Brain} title="Antecedent · Behaviour · Consequence" blurb="What led up to it, what happened, and what followed." />
                    <div className="grid gap-4">
                        <Field label="Antecedent" required hint="what happened just before">
                            <Textarea rows={2} value={data.antecedent} onChange={(e) => setData('antecedent', e.target.value)} placeholder="The trigger or situation before the behaviour." />
                        </Field>
                        <Field label="Behaviour" required hint="observable & measurable">
                            <Textarea rows={2} value={data.behaviour} onChange={(e) => setData('behaviour', e.target.value)} placeholder="Exactly what the person did." />
                        </Field>
                        <Field label="Consequence" required hint="what happened after">
                            <Textarea rows={2} value={data.consequence} onChange={(e) => setData('consequence', e.target.value)} placeholder="The response and outcome." />
                        </Field>

                        <Field label="Behaviour tags" hint="optional — add keywords">
                            <div className="flex flex-col gap-2">
                                <div className="flex gap-2">
                                    <Input
                                        value={tagDraft}
                                        onChange={(e) => setTagDraft(e.target.value)}
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter') {
                                                e.preventDefault();
                                                addTag();
                                            }
                                        }}
                                        placeholder="e.g. vocalisation, property damage…"
                                    />
                                    <Button type="button" variant="outline" onClick={addTag}>
                                        <Plus className="h-4 w-4" /> Add
                                    </Button>
                                </div>
                                {data.behaviour_tags.length ? (
                                    <div className="flex flex-wrap gap-1.5">
                                        {data.behaviour_tags.map((t, i) => (
                                            <span key={i} className="inline-flex items-center gap-1 rounded-full border border-border bg-card px-2.5 py-1 text-[13px]">
                                                {t}
                                                <button type="button" onClick={() => setData('behaviour_tags', data.behaviour_tags.filter((_, idx) => idx !== i))} aria-label={`Remove ${t}`}>
                                                    <X className="h-3 w-3 text-muted-foreground hover:text-status-critical" />
                                                </button>
                                            </span>
                                        ))}
                                    </div>
                                ) : null}
                            </div>
                        </Field>

                        <Field label="Hypothesised function" hint="the 'why' (PBS)">
                            <TilePicker
                                value={data.behaviour_function}
                                onChange={(v) => setData('behaviour_function', v)}
                                cols={3}
                                options={FUNCTIONS.map((f) => ({ key: f.key, label: f.label, description: f.description, icon: f.icon }))}
                            />
                        </Field>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Intensity" required>
                                <div className="inline-flex flex-wrap gap-1 rounded-lg bg-muted p-1">
                                    {INTENSITY.map((s) => {
                                        const active = data.intensity === s.key;
                                        return (
                                            <button
                                                key={s.key}
                                                type="button"
                                                onClick={() => setData('intensity', s.key)}
                                                className={cn(
                                                    'rounded-md px-3.5 py-1.5 text-[13px] font-semibold transition-colors',
                                                    active ? cn('bg-card shadow-sm', s.tone) : 'text-muted-foreground hover:text-foreground',
                                                )}
                                            >
                                                {s.label}
                                            </button>
                                        );
                                    })}
                                </div>
                            </Field>
                            <Field label="Duration" hint="seconds">
                                <Input type="number" inputMode="numeric" value={data.duration_seconds} onChange={(e) => setData('duration_seconds', e.target.value)} placeholder="e.g. 120" />
                            </Field>
                        </div>
                    </div>
                </WizardStepPane>
            ) : null}

            {STEPS[stepIndex].key === 'response' ? (
                <WizardStepPane>
                    <StepHead icon={HeartHandshake} title="Response & follow-up" blurb="Strategies used, any harm, and supporting evidence." />
                    <div className="grid gap-4">
                        <Field label="Strategies used">
                            <Textarea rows={2} value={data.strategies_used} onChange={(e) => setData('strategies_used', e.target.value)} placeholder="De-escalation / support strategies and how the person responded." />
                        </Field>

                        <div className="rounded-lg border border-border bg-muted/30 p-3">
                            <label className="flex items-start gap-3">
                                <Switch checked={data.harm_occurred} onCheckedChange={(v) => setData('harm_occurred', v)} />
                                <span>
                                    <span className="flex items-center gap-1.5 text-sm font-semibold">
                                        <ShieldAlert className="h-3.5 w-3.5 text-status-critical" /> Harm occurred
                                    </span>
                                    <span className="mt-0.5 block text-[13px] text-muted-foreground">To the person, staff, peers, or property.</span>
                                </span>
                            </label>
                            {data.harm_occurred ? (
                                <div className="mt-2.5">
                                    <Textarea rows={2} value={data.harm_notes} onChange={(e) => setData('harm_notes', e.target.value)} placeholder="Describe the harm — attach a body map / photo below." />
                                </div>
                            ) : null}
                        </div>

                        <div className="grid gap-3 sm:grid-cols-2">
                            <label className="flex items-center gap-3 rounded-lg border border-border bg-muted/30 p-3">
                                <Switch checked={data.escalated} onCheckedChange={(v) => setData('escalated', v)} />
                                <span className="text-sm font-semibold">Escalated</span>
                            </label>
                            <label className="flex items-center gap-3 rounded-lg border border-border bg-muted/30 p-3">
                                <Switch checked={data.requires_followup} onCheckedChange={(v) => setData('requires_followup', v)} />
                                <span className="text-sm font-semibold">Requires follow-up</span>
                            </label>
                        </div>
                        {data.requires_followup ? (
                            <Field label="Follow-up notes">
                                <Textarea rows={2} value={data.followup_notes} onChange={(e) => setData('followup_notes', e.target.value)} placeholder="What needs to happen next." />
                            </Field>
                        ) : null}

                        <div>
                            <SubHead icon={Paperclip}>Evidence &amp; attachments</SubHead>
                            <p className="mb-2 text-[13px] text-muted-foreground">
                                A body map / injury photo when harm occurred, property-damage photos, or a scanned paper chart.
                            </p>
                            <FileDropzone onFiles={addFiles} accept="image/*,.pdf,.doc,.docx" hint="Images, PDF or Word — up to 10 MB" />
                            {data.attachments.length ? (
                                <div className="mt-2.5 flex flex-col gap-2">
                                    {data.attachments.map((f, i) => (
                                        <StagedFileCard key={i} file={f} onRemove={() => setData('attachments', data.attachments.filter((_, idx) => idx !== i))} />
                                    ))}
                                </div>
                            ) : null}
                        </div>
                    </div>
                </WizardStepPane>
            ) : null}

            {STEPS[stepIndex].key === 'review' ? (
                <WizardStepPane>
                    <StepHead icon={Check} title="Review & record" blurb="Confirm the ABC entry before recording." />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard icon={MapPin} title="Context" onEdit={() => setStepIndex(0)}>
                            <ReviewRow label="Client" value={picked?.name} />
                            <ReviewRow label="When" value={data.occurred_at ? new Date(data.occurred_at).toLocaleString('en-NZ') : '—'} />
                            <ReviewRow label="Setting" value={data.setting} />
                        </ReviewCard>
                        <ReviewCard icon={Brain} title="Behaviour" onEdit={() => setStepIndex(1)}>
                            <ReviewRow label="Function" value={FUNCTIONS.find((f) => f.key === data.behaviour_function)?.label} />
                            <ReviewRow label="Intensity" value={INTENSITY.find((s) => s.key === data.intensity)?.label} />
                            <ReviewRow label="Duration" value={data.duration_seconds ? `${data.duration_seconds}s` : undefined} />
                            <ReviewRow label="Tags" value={data.behaviour_tags.length ? data.behaviour_tags.join(', ') : undefined} />
                        </ReviewCard>
                        <ReviewCard icon={HeartHandshake} title="A · B · C" span onEdit={() => setStepIndex(1)}>
                            <ReviewRow label="Antecedent" value={data.antecedent} />
                            <ReviewRow label="Behaviour" value={data.behaviour} />
                            <ReviewRow label="Consequence" value={data.consequence} />
                            <ReviewRow label="Harm" value={data.harm_occurred ? data.harm_notes || 'Yes' : 'None'} />
                            <ReviewRow label="Evidence" value={data.attachments.length ? `${data.attachments.length} file${data.attachments.length === 1 ? '' : 's'}` : undefined} />
                        </ReviewCard>
                    </div>
                    {!picked ? <InfoCard icon={Activity} tone="warn">Choose a client on the Context step before recording.</InfoCard> : null}
                </WizardStepPane>
            ) : null}
        </WizardShell>
    );
}

export default RecordAbcDialog;
