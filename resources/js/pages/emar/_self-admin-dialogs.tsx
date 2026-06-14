/* eslint-disable no-restricted-syntax -- wizard score/capability tiles, chip-multis, summary
   panes and the read-only detail grid are custom-layout bordered surfaces inside the wizard shell
   (not Card/Button); all colours are semantic tokens. */
import { MedsWizardDialog, SummaryRow } from '@/components/meds/wizard-shell';
import { Field, SelectInput, Segmented, StepHead } from '@/components/wizard/primitives';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { router } from '@inertiajs/react';
import { CheckCircle2, ClipboardCheck, FileSignature, Gauge, Package, Pill, ShieldCheck, User } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

export type MedItem = { id: number; name: string; dosage: string | null; controlled: boolean; scope: string | null };
export type MedScopeEntry = { med_id: number; med_name: string; scope: string };
export type SelfAdminRow = {
    id: number;
    client_id: number;
    client_name: string;
    nhi: string | null;
    site_name: string | null;
    status: string;
    outcome: string;
    outcome_label: string;
    wishes_to_self_administer: boolean;
    people_involved: string[];
    cognitive_capacity: number | null;
    physical_dexterity: number | null;
    vision_ability: number | null;
    swallowing_ability: number | null;
    understanding_score: number | null;
    total_score: number;
    can_identify_medications: boolean;
    can_read_labels: boolean;
    can_open_packaging: boolean;
    can_manage_timing: boolean;
    can_store_safely: boolean;
    willing_to_self_admin: boolean;
    risk_factors: string | null;
    support_needed: string | null;
    support_adjustments: string[];
    safe_storage_notes: string | null;
    storage_location: string | null;
    assessor_notes: string | null;
    assessor_name: string | null;
    assessment_date: string | null;
    reassessment_date: string | null;
    reassessment_interval_months: number | null;
    reassessment_trigger: string | null;
    reassessment_due: boolean;
    med_scope: MedScopeEntry[];
    ordering_responsibility: string | null;
    agreement_responsibilities: string | null;
    agreement_signed_at: string | null;
    agreement_signed_by_name: string | null;
    client_medications: MedItem[];
};
export type ClientOpt = { id: number; first_name: string; last_name: string };

const SCORES = [
    { key: 'cognitive_capacity', label: 'Cognitive capacity', help: 'Understands what each medicine is for.' },
    { key: 'physical_dexterity', label: 'Physical dexterity', help: 'Can handle packaging and devices.' },
    { key: 'vision_ability', label: 'Vision', help: 'Can read labels and dosing.' },
    { key: 'swallowing_ability', label: 'Swallowing', help: 'Can take the formulation safely.' },
    { key: 'understanding_score', label: 'Understands the regimen', help: 'Knows when and how much to take.' },
] as const;
const CAPABILITIES = [
    { key: 'can_identify_medications', label: 'Identify medicines' },
    { key: 'can_read_labels', label: 'Read labels' },
    { key: 'can_open_packaging', label: 'Open packaging' },
    { key: 'can_manage_timing', label: 'Manage timing' },
    { key: 'can_store_safely', label: 'Store safely' },
    { key: 'willing_to_self_admin', label: 'Willing & engaged' },
] as const;
const PEOPLE = ['Person', 'Family / whānau', 'GP', 'Pharmacist', 'Registered nurse', 'Key worker'];
const SUPPORTS = ['Large-print labels', 'Easy-open caps', 'Dosette / blister', 'Reminder chart', 'Alarm', 'Liquid form', 'Inhaler aid', 'Colour-code'];
const STORAGE = [{ value: 'lockable_drawer', label: 'Lockable drawer in room' }, { value: 'own_room', label: 'Own room (low risk)' }, { value: 'staff_cabinet', label: 'Staff cabinet' }, { value: 'cd_cabinet', label: 'CD cabinet' }];
const TRIGGERS = ['Hospital admission', 'Medication error', 'Condition change', 'New medication', 'Decline in function', 'Person request'].map((t) => ({ value: t, label: t }));
const INTERVALS = [{ value: '3', label: '3 months' }, { value: '6', label: '6 months' }, { value: '12', label: '12 months' }];
const ORDERING = [{ value: 'self', label: 'Self' }, { value: 'service', label: 'Service' }, { value: 'pharmacy', label: 'Pharmacy' }];
const SCOPE_OPTS = [{ value: 'self_managed', label: 'Self-managed' }, { value: 'prompted', label: 'Prompted' }, { value: 'staff_given', label: 'Staff-given' }];

export function categoryMeta(o: string): { num: number; label: string; cls: string } {
    return ({
        independent: { num: 1, label: 'Cat 1 · Independent', cls: 'bg-status-success-bg text-status-success' },
        prompted: { num: 2, label: 'Cat 2 · Prompted', cls: 'bg-status-info-bg text-status-info' },
        supervised: { num: 3, label: 'Cat 3 · Supervised', cls: 'bg-status-warning-bg text-status-warning' },
        administered: { num: 4, label: 'Cat 4 · Staff-administered', cls: 'bg-status-critical-bg text-status-critical' },
    } as Record<string, { num: number; label: string; cls: string }>)[o] ?? { num: 0, label: 'Not assessed', cls: 'bg-muted text-muted-foreground' };
}
export function scopeMeta(s: string | null): { label: string; cls: string } {
    return s === 'self_managed' ? { label: 'Self-managed', cls: 'bg-status-success-bg text-status-success' } : s === 'prompted' ? { label: 'Prompted', cls: 'bg-status-warning-bg text-status-warning' } : { label: 'Staff-given', cls: 'bg-status-critical-bg text-status-critical' };
}
const computeOutcome = (wishes: boolean, willing: boolean, total: number) => (!wishes || !willing ? 'administered' : total >= 21 ? 'independent' : total >= 16 ? 'prompted' : total >= 11 ? 'supervised' : 'administered');

function ChipMulti({ options, value, onChange }: { options: string[]; value: string[]; onChange: (v: string[]) => void }) {
    const toggle = (o: string) => onChange(value.includes(o) ? value.filter((x) => x !== o) : [...value, o]);
    return (
        <div className="flex flex-wrap gap-2">
            {options.map((o) => (
                <button key={o} type="button" onClick={() => toggle(o)} className={`rounded-full border px-3 py-1 text-xs font-medium transition ${value.includes(o) ? 'border-primary bg-primary/10 text-primary' : 'border-border text-muted-foreground hover:bg-muted'}`}>{o}</button>
            ))}
        </div>
    );
}

// ── New / Reassess assessment (5-step) ───────────────────────────────────────
export function AssessmentWizardDialog({ clients, assessment, mode, onClose }: { clients: ClientOpt[]; assessment?: SelfAdminRow | null; mode: 'new' | 'reassess'; onClose: () => void }) {
    const [step, setStep] = useState(0);
    const [busy, setBusy] = useState(false);
    const [clientId, setClientId] = useState(assessment ? String(assessment.client_id) : '');
    const [wishes, setWishes] = useState(assessment ? assessment.wishes_to_self_administer : true);
    const [people, setPeople] = useState<string[]>(assessment?.people_involved ?? []);
    const [scores, setScores] = useState<Record<string, number>>(() => {
        const o: Record<string, number> = {};
        SCORES.forEach((s) => { o[s.key] = (assessment?.[s.key] as number | null) ?? 3; });
        return o;
    });
    const [caps, setCaps] = useState<Record<string, boolean>>(() => {
        const o: Record<string, boolean> = {};
        CAPABILITIES.forEach((c) => { o[c.key] = assessment ? !!assessment[c.key] : true; });
        return o;
    });
    const [supports, setSupports] = useState<string[]>(assessment?.support_adjustments ?? []);
    const [storage, setStorage] = useState(assessment?.storage_location ?? '');
    const [storageNotes, setStorageNotes] = useState(assessment?.safe_storage_notes ?? '');
    const [interval, setInterval] = useState(assessment?.reassessment_interval_months ? String(assessment.reassessment_interval_months) : '6');
    const [trigger, setTrigger] = useState(assessment?.reassessment_trigger ?? '');
    const [risks, setRisks] = useState(assessment?.risk_factors ?? '');
    const [confirmed, setConfirmed] = useState(false);

    const total = SCORES.reduce((s, k) => s + (scores[k.key] ?? 0), 0);
    const outcome = computeOutcome(wishes, caps.willing_to_self_admin, total);
    const cat = categoryMeta(outcome);
    const clientName = clients.find((c) => String(c.id) === clientId);

    const submit = () => {
        setBusy(true);
        const payload: Record<string, unknown> = {
            client_id: Number(clientId),
            wishes_to_self_administer: wishes,
            people_involved: people,
            ...Object.fromEntries(SCORES.map((s) => [s.key, scores[s.key]])),
            ...Object.fromEntries(CAPABILITIES.map((c) => [c.key, caps[c.key]])),
            support_adjustments: supports,
            storage_location: storage || null,
            safe_storage_notes: storageNotes || null,
            reassessment_interval_months: Number(interval),
            reassessment_trigger: trigger || null,
            risk_factors: risks || null,
            supersedes_id: mode === 'reassess' && assessment ? assessment.id : null,
        };
        router.post('/emar/self-admin', payload as Parameters<typeof router.post>[1], { preserveScroll: true, onSuccess: () => { toast.success(mode === 'reassess' ? 'Reassessment saved' : 'Assessment saved'); onClose(); }, onError: () => toast.error('Please check the assessment'), onFinish: () => setBusy(false) });
    };
    const valid = [!!clientId, true, true, true, confirmed];
    return (
        <MedsWizardDialog open onClose={onClose} title={mode === 'reassess' ? 'Reassessment' : 'New assessment'} description="Assess capacity, consent and support for self-administration." railIcon={ClipboardCheck} railTitle={mode === 'reassess' ? 'Reassessment' : 'New assessment'} railSubtitle={clientName ? `${clientName.first_name} ${clientName.last_name} · ${cat.label}` : cat.label} steps={[{ key: 'consent', label: 'Person & consent', blurb: 'Wishes', icon: User }, { key: 'capacity', label: 'Capacity scores', blurb: '/25', icon: Gauge }, { key: 'capability', label: 'Capability', blurb: '6 checks', icon: ShieldCheck }, { key: 'support', label: 'Support & storage', blurb: 'Adjustments', icon: Package }, { key: 'review', label: 'Review & sign', blurb: 'Confirm', icon: CheckCircle2 }]} stepIndex={step} onStepClick={(i) => i < step && setStep(i)} footer={<><Button variant="ghost" onClick={step === 0 ? onClose : () => setStep(step - 1)} disabled={busy}>{step === 0 ? 'Cancel' : 'Back'}</Button>{step < 4 ? <Button onClick={() => setStep(step + 1)} disabled={!valid[step]}>Continue</Button> : <Button onClick={submit} disabled={busy || !confirmed}>Sign &amp; save assessment</Button>}</>}>
            {step === 0 && (
                <>
                    <StepHead icon={User} title="Person & consent" blurb="Independence first — staff step in only where the risk assessment says so." />
                    <Field label="Client" required span>
                        <SelectInput value={clientId} onChange={setClientId} placeholder="Select client…" options={clients.map((c) => ({ value: String(c.id), label: `${c.first_name} ${c.last_name}` }))} />
                    </Field>
                    <div className="mt-4 grid grid-cols-2 gap-3">
                        <button type="button" onClick={() => setWishes(true)} className={`rounded-xl border-2 p-4 text-left ${wishes ? 'border-primary bg-primary/5' : 'border-border'}`}><div className="font-semibold">Wishes to self-administer</div><div className="text-xs text-muted-foreground">The person wants to manage their own medicines.</div></button>
                        <button type="button" onClick={() => setWishes(false)} className={`rounded-xl border-2 p-4 text-left ${!wishes ? 'border-primary bg-primary/5' : 'border-border'}`}><div className="font-semibold">Does not wish to</div><div className="text-xs text-muted-foreground">Prefers staff to administer — Category 4.</div></button>
                    </div>
                    <div className="mt-4"><div className="mb-1.5 text-sm font-medium">Who was involved?</div><ChipMulti options={PEOPLE} value={people} onChange={setPeople} /></div>
                </>
            )}
            {step === 1 && (
                <>
                    <StepHead icon={Gauge} title="Capacity scores" blurb="Rate each 1 (low) to 5 (high)." />
                    <div className="flex flex-col gap-3">
                        {SCORES.map((s) => (
                            <div key={s.key} className="rounded-lg border p-3">
                                <div className="mb-1.5 flex items-center justify-between"><span className="text-sm font-medium">{s.label}</span><span className="text-xs text-muted-foreground">{s.help}</span></div>
                                <Segmented value={String(scores[s.key])} onChange={(v) => setScores((o) => ({ ...o, [s.key]: Number(v) }))} options={[1, 2, 3, 4, 5].map((n) => ({ value: String(n), label: String(n) }))} />
                            </div>
                        ))}
                    </div>
                    <div className={`mt-4 rounded-lg border px-4 py-3 text-sm ${cat.cls}`}>Total {total}/25 · {cat.label}</div>
                </>
            )}
            {step === 2 && (
                <>
                    <StepHead icon={ShieldCheck} title="Capability checks" blurb="Tap each capability the person demonstrates." />
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        {CAPABILITIES.map((c) => (
                            <button key={c.key} type="button" onClick={() => setCaps((o) => ({ ...o, [c.key]: !o[c.key] }))} className={`rounded-xl border-2 p-3 text-left text-sm ${caps[c.key] ? 'border-status-success bg-status-success-bg/50' : 'border-border'}`}>
                                {caps[c.key] ? <CheckCircle2 className="mb-1 h-4 w-4 text-status-success" /> : <span className="mb-1 block h-4 w-4 rounded-full border" />}
                                {c.label}
                            </button>
                        ))}
                    </div>
                    {!caps.willing_to_self_admin && <div className="mt-3 rounded-lg border border-status-critical/30 bg-status-critical-bg/60 px-3 py-2 text-xs text-status-critical">Not willing/engaged — the person will be Category 4 (staff-administered) regardless of score.</div>}
                </>
            )}
            {step === 3 && (
                <>
                    <StepHead icon={Package} title="Support & storage" blurb="Adjustments, storage and the reassessment cadence." />
                    <div className="mb-3"><div className="mb-1.5 text-sm font-medium">Support adjustments</div><ChipMulti options={SUPPORTS} value={supports} onChange={setSupports} /></div>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Storage location"><SelectInput value={storage} onChange={setStorage} placeholder="Where stored…" options={STORAGE} /></Field>
                        <Field label="Storage notes"><Input value={storageNotes} onChange={(e) => setStorageNotes(e.target.value)} placeholder="Optional" /></Field>
                        <Field label="Reassess interval"><Segmented value={interval} onChange={setInterval} options={INTERVALS} /></Field>
                        <Field label="Early-review trigger"><SelectInput value={trigger} onChange={setTrigger} placeholder="Optional…" options={TRIGGERS} /></Field>
                        <Field label="Risk factors" span><Input value={risks} onChange={(e) => setRisks(e.target.value)} placeholder="Anything that raises risk" /></Field>
                    </div>
                </>
            )}
            {step === 4 && (
                <>
                    <StepHead icon={CheckCircle2} title="Review & sign" blurb="Confirm the assessment outcome." />
                    <div className="rounded-lg border px-4">
                        <SummaryRow label="Client" value={clientName ? `${clientName.first_name} ${clientName.last_name}` : '—'} />
                        <SummaryRow label="Wishes to self-administer" value={wishes ? 'Yes' : 'No'} />
                        <SummaryRow label="Capacity total" value={`${total}/25`} />
                        <SummaryRow label="Capability" value={`${CAPABILITIES.filter((c) => caps[c.key]).length}/6`} />
                        <SummaryRow label="Computed category" value={cat.label} />
                        <SummaryRow label="Reassess" value={`${interval} months${trigger ? ` · ${trigger}` : ''}`} />
                    </div>
                    <label className="mt-4 flex items-center gap-2 text-sm"><input type="checkbox" checked={confirmed} onChange={(e) => setConfirmed(e.target.checked)} className="h-4 w-4 rounded border-border" />I confirm this assessment was completed with the person.</label>
                </>
            )}
        </MedsWizardDialog>
    );
}

// ── Sign agreement (1-step) ──────────────────────────────────────────────────
export function SignAgreementDialog({ assessment, onClose }: { assessment: SelfAdminRow; onClose: () => void }) {
    const [ordering, setOrdering] = useState(assessment.ordering_responsibility ?? 'service');
    const [responsibilities, setResponsibilities] = useState(assessment.agreement_responsibilities ?? '');
    const [confirmed, setConfirmed] = useState(false);
    const [busy, setBusy] = useState(false);
    const submit = () => {
        setBusy(true);
        router.put(`/emar/self-admin/${assessment.id}`, { sign_agreement: true, ordering_responsibility: ordering, agreement_responsibilities: responsibilities || null } as Parameters<typeof router.put>[1], { preserveScroll: true, onSuccess: () => { toast.success('Agreement signed'); onClose(); }, onError: () => toast.error('Could not sign agreement'), onFinish: () => setBusy(false) });
    };
    return (
        <MedsWizardDialog open onClose={onClose} title="Self-administration agreement" description={`Record the signed agreement for ${assessment.client_name}.`} railIcon={FileSignature} railTitle="Sign agreement" railSubtitle={assessment.client_name} steps={[{ key: 'sign', label: 'Agreement', blurb: 'Responsibilities', icon: FileSignature }]} stepIndex={0} onStepClick={() => {}} footer={<><Button variant="ghost" onClick={onClose} disabled={busy}>Cancel</Button><Button onClick={submit} disabled={busy || !confirmed}>Sign agreement</Button></>}>
            <StepHead icon={FileSignature} title="Self-administration agreement" blurb="Confirm who orders the medicines and the agreed responsibilities." />
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="Ordering responsibility"><SelectInput value={ordering} onChange={setOrdering} placeholder="Who orders…" options={ORDERING} /></Field>
            </div>
            <Field label="Agreed responsibilities" span><Input value={responsibilities} onChange={(e) => setResponsibilities(e.target.value)} placeholder="What the person and service each agree to" /></Field>
            <label className="mt-3 flex items-center gap-2 text-sm"><input type="checkbox" checked={confirmed} onChange={(e) => setConfirmed(e.target.checked)} className="h-4 w-4 rounded border-border" />The person has read and signed the agreement.</label>
        </MedsWizardDialog>
    );
}

// ── Per-medication scope (1-step) ────────────────────────────────────────────
export function MedScopeDialog({ assessment, onClose }: { assessment: SelfAdminRow; onClose: () => void }) {
    const [scopes, setScopes] = useState<Record<number, string>>(() => {
        const o: Record<number, string> = {};
        assessment.client_medications.forEach((m) => { o[m.id] = m.scope ?? 'staff_given'; });
        return o;
    });
    const [busy, setBusy] = useState(false);
    const submit = () => {
        setBusy(true);
        const med_scope = assessment.client_medications.map((m) => ({ med_id: m.id, med_name: m.name, scope: scopes[m.id] ?? 'staff_given' }));
        router.put(`/emar/self-admin/${assessment.id}`, { med_scope } as Parameters<typeof router.put>[1], { preserveScroll: true, onSuccess: () => { toast.success('Scope saved'); onClose(); }, onError: () => toast.error('Could not save scope'), onFinish: () => setBusy(false) });
    };
    return (
        <MedsWizardDialog open onClose={onClose} title="Per-medication scope" description={`Set the self-administration scope for each of ${assessment.client_name}'s medicines.`} railIcon={Pill} railTitle="Medication scope" railSubtitle={assessment.client_name} steps={[{ key: 'scope', label: 'Scope', blurb: 'Per medicine', icon: Pill }]} stepIndex={0} onStepClick={() => {}} footer={<><Button variant="ghost" onClick={onClose} disabled={busy}>Cancel</Button><Button onClick={submit} disabled={busy}>Save scope</Button></>}>
            <StepHead icon={Pill} title="Per-medication scope" blurb="Self-administration is not all-or-nothing — set each medicine individually." />
            {assessment.client_medications.length === 0 ? <div className="rounded-lg border border-dashed px-4 py-8 text-center text-sm text-muted-foreground">No active medications for this client.</div> : (
                <div className="flex flex-col gap-2">
                    {assessment.client_medications.map((m) => (
                        <div key={m.id} className="flex items-center justify-between gap-3 rounded-lg border px-3 py-2">
                            <div><span className="text-sm font-medium">{m.name}</span>{m.controlled && <span className="ml-2 rounded-full bg-status-critical-bg px-1.5 py-0.5 text-[10px] font-semibold text-status-critical">CD</span>}{m.dosage && <div className="text-xs text-muted-foreground">{m.dosage}</div>}</div>
                            <div className="w-40"><SelectInput value={scopes[m.id] ?? 'staff_given'} onChange={(v) => setScopes((o) => ({ ...o, [m.id]: v }))} placeholder="Scope…" options={SCOPE_OPTS} /></div>
                        </div>
                    ))}
                </div>
            )}
        </MedsWizardDialog>
    );
}

// ── View detail (read-only) ──────────────────────────────────────────────────
export function ViewSelfAdminDialog({ assessment, onClose }: { assessment: SelfAdminRow; onClose: () => void }) {
    const cat = categoryMeta(assessment.outcome);
    return (
        <MedsWizardDialog open onClose={onClose} title="Assessment detail" description={`${assessment.client_name} · ${cat.label}`} railIcon={ClipboardCheck} railTitle="Assessment" railSubtitle={assessment.client_name} steps={[{ key: 'detail', label: 'Summary', blurb: 'Read-only', icon: ClipboardCheck }]} stepIndex={0} onStepClick={() => {}} footer={<Button onClick={onClose}>Close</Button>}>
            <div className="rounded-lg border px-4">
                <SummaryRow label="Category" value={cat.label} />
                <SummaryRow label="Wishes to self-administer" value={assessment.wishes_to_self_administer ? 'Yes' : 'No'} />
                <SummaryRow label="Capacity" value={`${assessment.total_score}/25`} />
                <SummaryRow label="Storage" value={STORAGE.find((s) => s.value === assessment.storage_location)?.label ?? '—'} />
                <SummaryRow label="Reassessment" value={assessment.reassessment_date ?? '—'} />
                <SummaryRow label="Assessor" value={assessment.assessor_name ?? '—'} />
            </div>
            {assessment.people_involved.length > 0 && <Detail label="Involved" value={assessment.people_involved.join(', ')} />}
            {assessment.support_adjustments.length > 0 && <Detail label="Support adjustments" value={assessment.support_adjustments.join(', ')} />}
            {assessment.risk_factors && <Detail label="Risk factors" value={assessment.risk_factors} />}
            {assessment.reassessment_trigger && <Detail label="Early-review trigger" value={assessment.reassessment_trigger} />}
            {assessment.agreement_signed_at && <div className="mt-4 rounded-lg border border-status-success/30 bg-status-success-bg/40 px-3 py-2 text-sm text-status-success">Agreement signed{assessment.agreement_signed_by_name ? ` by ${assessment.agreement_signed_by_name}` : ''}{assessment.ordering_responsibility ? ` · ordering: ${assessment.ordering_responsibility}` : ''}.</div>}
        </MedsWizardDialog>
    );
}

function Detail({ label, value }: { label: string; value: string }) {
    return <div className="mt-3"><div className="mb-0.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{label}</div><p className="text-sm">{value}</p></div>;
}
