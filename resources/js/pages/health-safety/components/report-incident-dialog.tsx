/* Report incident / near-miss — the reference wizard (WS6). 6 steps on the Add-Client
 * WizardShell chrome, incl. the WorkSafe notifiable-event check (HSWA 2015). Mirrors the
 * server-side NotifiableEventClassifier rule (harm ∈ {hospitalisation, death} OR severity =
 * Critical); the server re-enforces it. Posts /incidents with `stay` so the dashboard refreshes
 * in place, then shows the success pane. Tokens only; plain string URL. */
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/wizard/shell';
import {
    ChipMulti,
    Field,
    InfoCard,
    Segmented,
    SelectInput,
    StepHead,
    TilePicker,
} from '@/components/wizard/primitives';
import { router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    CheckCircle2,
    ClipboardCheck,
    FileText,
    ShieldAlert,
    ShieldCheck,
    Siren,
} from 'lucide-react';
import { useState } from 'react';

const STEPS: WizardStep[] = [
    { key: 'type', label: 'Type & people', blurb: 'What kind of event', icon: AlertTriangle },
    { key: 'what', label: 'What happened', blurb: 'Client, site & detail', icon: FileText },
    { key: 'severity', label: 'Severity & harm', blurb: 'Impact assessment', icon: Activity },
    { key: 'actions', label: 'Immediate actions', blurb: 'What was done', icon: ShieldCheck },
    { key: 'worksafe', label: 'WorkSafe check', blurb: 'Notifiable assessment', icon: Siren },
    { key: 'review', label: 'Review & submit', blurb: 'Confirm & file', icon: ClipboardCheck },
];

const TYPE_OPTS = [
    { key: 'near_miss', label: 'Near miss', description: 'No harm — but could have' },
    { key: 'injury', label: 'Injury', description: 'A person was hurt' },
    { key: 'illness', label: 'Work-related illness', description: 'Exposure / condition' },
    { key: 'property', label: 'Property / equipment', description: 'Damage, no injury' },
    { key: 'behaviour', label: 'Behaviour / aggression', description: 'Challenging behaviour' },
    { key: 'security', label: 'Security / intruder', description: 'Unauthorised access' },
];

const WHO_OPTS = ['Staff member', 'Client / resident', 'Visitor', 'Contractor', 'Member of public'];

const SEV_OPTS = [
    { value: 'Minor', label: 'Minor' },
    { value: 'Moderate', label: 'Moderate' },
    { value: 'Serious', label: 'Serious' },
    { value: 'Critical', label: 'Critical' },
];

const HARM_OPTS = [
    { key: 'none', label: 'No harm' },
    { key: 'first_aid', label: 'First aid only' },
    { key: 'medical', label: 'Medical treatment' },
    { key: 'hospitalisation', label: 'Hospitalisation' },
    { key: 'death', label: 'Death' },
];

const ACTION_OPTS = [
    'Made area safe',
    'First aid given',
    'Called 111',
    'Manager notified',
    'Equipment isolated',
    'Client reassured',
];

const SEVERITY_MAP: Record<string, string> = { Minor: 'low', Moderate: 'medium', Serious: 'high', Critical: 'high' };
const HARM_TO_TREATMENT: Record<string, string> = {
    none: 'none',
    first_aid: 'first_aid',
    medical: 'medical_centre',
    hospitalisation: 'hospital',
    death: 'hospital',
};
const ROLE_MAP: Record<string, string> = {
    'Staff member': 'staff',
    'Client / resident': 'client',
    Visitor: 'visitor',
    Contractor: 'contractor',
};

function today(): string {
    return new Date().toISOString().slice(0, 10);
}

export function ReportIncidentDialog({
    open,
    onClose,
    clients,
    sites,
}: {
    open: boolean;
    onClose: () => void;
    clients: Array<{ id: number; name: string }>;
    sites: Array<{ id: number; name: string }>;
}) {
    const [step, setStep] = useState(0);
    const [submitted, setSubmitted] = useState(false);
    const [processing, setProcessing] = useState(false);

    const [type, setType] = useState('');
    const [people, setPeople] = useState<string[]>([]);
    const [clientId, setClientId] = useState('');
    const [site, setSite] = useState('');
    const [date, setDate] = useState(today());
    const [time, setTime] = useState('');
    const [description, setDescription] = useState('');
    const [severity, setSeverity] = useState('');
    const [harm, setHarm] = useState('');
    const [actions, setActions] = useState<string[]>([]);
    const [actionText, setActionText] = useState('');
    const [createCA, setCreateCA] = useState(true);
    const [link, setLink] = useState('');
    const [sitePreserved, setSitePreserved] = useState(false);
    const [notifyWho, setNotifyWho] = useState('');
    const [worksafeRef, setWorksafeRef] = useState('');

    const notifiable = ['hospitalisation', 'death'].includes(harm) || severity === 'Critical';

    const checks = [
        type !== '',
        people.length > 0,
        clientId !== '',
        description.trim() !== '',
        severity !== '',
        harm !== '',
        actions.length > 0 || actionText.trim() !== '',
        !notifiable || (sitePreserved && notifyWho.trim() !== ''),
    ];
    const pct = Math.round((checks.filter(Boolean).length / checks.length) * 100);

    const stepValid = [
        type !== '' && people.length > 0,
        clientId !== '' && description.trim() !== '',
        severity !== '' && harm !== '',
        actions.length > 0 || actionText.trim() !== '',
        !notifiable || (sitePreserved && notifyWho.trim() !== ''),
        true,
    ];

    const reset = () => {
        setType('');
        setPeople([]);
        setClientId('');
        setSite('');
        setDate(today());
        setTime('');
        setDescription('');
        setSeverity('');
        setHarm('');
        setActions([]);
        setActionText('');
        setCreateCA(true);
        setLink('');
        setSitePreserved(false);
        setNotifyWho('');
        setWorksafeRef('');
        setStep(0);
        setSubmitted(false);
    };

    const close = () => {
        reset();
        onClose();
    };

    const submit = () => {
        setProcessing(true);
        const occurred = time ? `${date}T${time}` : date;
        const actionsJoined = [actions.join(', '), actionText.trim()].filter(Boolean).join(' — ');
        const desc = [
            description.trim(),
            site ? `Site: ${site}` : '',
            link.trim() ? `Linked: ${link.trim()}` : '',
            notifiable && notifyWho.trim() ? `Notifying WorkSafe: ${notifyWho.trim()}` : '',
        ]
            .filter(Boolean)
            .join('\n');
        const injuredRole = people.map((p) => ROLE_MAP[p]).find(Boolean) ?? null;
        const injuryClass =
            severity === 'Critical' || ['hospitalisation', 'death'].includes(harm)
                ? 'notifiable'
                : severity === 'Serious'
                  ? 'serious'
                  : severity === 'Moderate'
                    ? 'moderate'
                    : 'minor';

        router.post(
            '/incidents',
            {
                client_id: Number(clientId),
                type,
                severity: SEVERITY_MAP[severity] ?? 'low',
                occurred_at: occurred,
                description: desc,
                requires_followup: createCA,
                immediate_action_taken: actionsJoined,
                injured_person_role: injuredRole,
                medical_treatment_type: HARM_TO_TREATMENT[harm] ?? 'none',
                injury_classification: injuryClass,
                is_notifiable: notifiable,
                site_preserved: sitePreserved,
                worksafe_reference: worksafeRef.trim() || null,
                stay: true,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => setSubmitted(true),
                onFinish: () => setProcessing(false),
            },
        );
    };

    const typeLabel = TYPE_OPTS.find((o) => o.key === type)?.label;
    const harmLabel = HARM_OPTS.find((o) => o.key === harm)?.label;
    const clientName = clients.find((c) => String(c.id) === clientId)?.name;
    const canContinue = stepValid[step];

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="Report incident / near-miss"
            description="Record a health & safety event, with an automatic WorkSafe notifiable-event check."
            railIcon={ShieldAlert}
            railTitle="Report incident"
            railSub="Events register"
            steps={STEPS}
            stepIndex={step}
            onStepClick={(i) => i <= step && setStep(i)}
            pct={pct}
            success={
                submitted ? (
                    <WizardSuccessPane
                        title="Incident recorded"
                        blurb={
                            notifiable
                                ? 'Filed as a WorkSafe-notifiable event — notify WorkSafe as soon as possible and keep the site preserved. Records are kept for at least 5 years.'
                                : 'Filed in the events register for your records (kept ≥ 5 years).'
                        }
                        actions={
                            <>
                                <Button variant="outline" onClick={reset}>
                                    Record another
                                </Button>
                                <Button onClick={close}>Done</Button>
                            </>
                        }
                    />
                ) : undefined
            }
            footerStart={
                step > 0 ? (
                    <Button variant="outline" onClick={() => setStep(step - 1)}>
                        Back
                    </Button>
                ) : (
                    <Button variant="ghost" onClick={close}>
                        Cancel
                    </Button>
                )
            }
            footerEnd={
                step < STEPS.length - 1 ? (
                    <Button onClick={() => canContinue && setStep(step + 1)} disabled={!canContinue}>
                        Continue
                    </Button>
                ) : (
                    <Button onClick={submit} disabled={processing}>
                        Submit report
                    </Button>
                )
            }
        >
            <WizardStepPane>
                {step === 0 ? (
                    <>
                        <StepHead icon={AlertTriangle} title="What kind of event?" blurb="Pick the event type and who was involved." />
                        <div className="space-y-5">
                            <Field label="Event type" required>
                                <TilePicker value={type} onChange={setType} options={TYPE_OPTS} cols={2} />
                            </Field>
                            <Field label="People involved" required>
                                <ChipMulti values={people} onChange={setPeople} options={WHO_OPTS} />
                            </Field>
                        </div>
                    </>
                ) : null}

                {step === 1 ? (
                    <>
                        <StepHead icon={FileText} title="What happened?" blurb="Who it involved, where and when." />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Client / resident" required hint="the incident is filed against this client" span>
                                <SelectInput
                                    value={clientId}
                                    onChange={setClientId}
                                    placeholder="Select a client…"
                                    options={clients.map((c) => ({ value: String(c.id), label: c.name }))}
                                />
                            </Field>
                            <Field label="Site / location">
                                <SelectInput
                                    value={site}
                                    onChange={setSite}
                                    placeholder="Select a site…"
                                    options={sites.map((s) => ({ value: s.name, label: s.name }))}
                                />
                            </Field>
                            <Field label="Date">
                                <Input type="date" value={date} onChange={(e) => setDate(e.target.value)} />
                            </Field>
                            <Field label="Time">
                                <Input type="time" value={time} onChange={(e) => setTime(e.target.value)} />
                            </Field>
                            <Field label="Description" required span>
                                <Textarea
                                    value={description}
                                    onChange={(e) => setDescription(e.target.value)}
                                    placeholder="Describe what happened, factually…"
                                    rows={4}
                                />
                            </Field>
                        </div>
                    </>
                ) : null}

                {step === 2 ? (
                    <>
                        <StepHead icon={Activity} title="Severity & harm" blurb="Assess the impact of the event." />
                        <div className="space-y-5">
                            <Field label="Severity" required>
                                <Segmented value={severity} onChange={setSeverity} options={SEV_OPTS} />
                            </Field>
                            <Field label="Degree of harm" required>
                                <TilePicker value={harm} onChange={setHarm} options={HARM_OPTS} cols={2} />
                            </Field>
                        </div>
                    </>
                ) : null}

                {step === 3 ? (
                    <>
                        <StepHead icon={ShieldCheck} title="Immediate actions" blurb="What was done right away." />
                        <div className="space-y-5">
                            <Field label="Actions taken" required>
                                <ChipMulti values={actions} onChange={setActions} options={ACTION_OPTS} />
                            </Field>
                            <Field label="Other action (optional)">
                                <Textarea
                                    value={actionText}
                                    onChange={(e) => setActionText(e.target.value)}
                                    placeholder="Any other immediate action…"
                                    rows={2}
                                />
                            </Field>
                            <Field label="Create a corrective action" hint="assign follow-up to prevent recurrence">
                                <Segmented
                                    value={createCA ? 'yes' : 'no'}
                                    onChange={(v) => setCreateCA(v === 'yes')}
                                    options={[
                                        { value: 'yes', label: 'Yes' },
                                        { value: 'no', label: 'No' },
                                    ]}
                                />
                            </Field>
                            <Field label="Link to client / staff (optional)">
                                <Input value={link} onChange={(e) => setLink(e.target.value)} placeholder="Search a client or staff member…" />
                            </Field>
                        </div>
                    </>
                ) : null}

                {step === 4 ? (
                    <>
                        <StepHead icon={Siren} title="WorkSafe notifiable check" blurb="Auto-assessed against the HSWA 2015 threshold." />
                        {notifiable ? (
                            <div className="space-y-4">
                                <InfoCard icon={AlertTriangle} tone="crit">
                                    <strong>Meets the HSWA notifiable threshold.</strong> Based on the severity / harm
                                    recorded, this is a <strong>WorkSafe notifiable event</strong>. You must notify
                                    WorkSafe as soon as possible, <strong>preserve the site</strong> until told
                                    otherwise, and keep records for at least 5 years.
                                </InfoCard>
                                <Field label="Site preserved" required hint="scene secured / not disturbed (except to make safe)">
                                    <Segmented
                                        value={sitePreserved ? 'yes' : 'no'}
                                        onChange={(v) => setSitePreserved(v === 'yes')}
                                        options={[
                                            { value: 'yes', label: 'Yes — secured' },
                                            { value: 'no', label: 'Not yet' },
                                        ]}
                                    />
                                </Field>
                                <Field label="Who is notifying WorkSafe?" required>
                                    <Input value={notifyWho} onChange={(e) => setNotifyWho(e.target.value)} placeholder="e.g. Sarah Reid (H&S Advisor)" />
                                </Field>
                                <Field label="WorkSafe reference (if already lodged)">
                                    <Input value={worksafeRef} onChange={(e) => setWorksafeRef(e.target.value)} placeholder="WS-26-XXXX" />
                                </Field>
                            </div>
                        ) : (
                            <div className="rounded-lg border border-status-success/35 bg-status-success-bg p-3.5 text-[13px] leading-relaxed">
                                <div className="flex items-center gap-2 font-bold text-status-success">
                                    <CheckCircle2 className="h-4 w-4" /> Does not meet the notifiable threshold
                                </div>
                                <p className="mt-1 text-foreground">
                                    This event is recorded in the events register for your own records (kept ≥ 5 years).
                                    If severity or harm changes on investigation, the notifiable status is re-assessed
                                    automatically.
                                </p>
                            </div>
                        )}
                    </>
                ) : null}

                {step === 5 ? (
                    <>
                        <StepHead icon={ClipboardCheck} title="Review & submit" blurb="Confirm the details before filing." />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <ReviewCard icon={AlertTriangle} title="Event" onEdit={() => setStep(0)}>
                                <ReviewRow label="Type" value={typeLabel} />
                                <ReviewRow label="People involved" value={people.join(', ')} />
                            </ReviewCard>
                            <ReviewCard icon={FileText} title="What happened" onEdit={() => setStep(1)}>
                                <ReviewRow label="Client" value={clientName} />
                                <ReviewRow label="Site" value={site} />
                                <ReviewRow label="When" value={[date, time].filter(Boolean).join(' ')} />
                                <ReviewRow label="Description" value={description} />
                            </ReviewCard>
                            <ReviewCard icon={Activity} title="Severity & harm" onEdit={() => setStep(2)}>
                                <ReviewRow label="Severity" value={severity} />
                                <ReviewRow label="Harm" value={harmLabel} />
                            </ReviewCard>
                            <ReviewCard icon={ShieldCheck} title="Immediate actions" onEdit={() => setStep(3)}>
                                <ReviewRow label="Actions" value={[actions.join(', '), actionText.trim()].filter(Boolean).join(' — ')} />
                                <ReviewRow label="Corrective action" value={createCA ? 'Will be created' : '—'} />
                            </ReviewCard>
                            <ReviewCard icon={Siren} title="WorkSafe" span onEdit={() => setStep(4)}>
                                <ReviewRow
                                    label="Notifiable"
                                    value={notifiable ? 'Yes — notification required' : 'No — recorded only'}
                                />
                                {notifiable ? <ReviewRow label="Site preserved" value={sitePreserved ? 'Yes' : 'No'} /> : null}
                                {notifiable ? <ReviewRow label="Notifying" value={notifyWho} /> : null}
                            </ReviewCard>
                        </div>
                    </>
                ) : null}
            </WizardStepPane>
        </WizardShell>
    );
}

export default ReportIncidentDialog;
