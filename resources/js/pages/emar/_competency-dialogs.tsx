/* eslint-disable no-restricted-syntax -- wizard tri-state grids, observed-round rows, outcome
   panels and the read-only detail grid are custom-layout bordered surfaces inside the wizard
   shell (not Card/Button); all colours are semantic tokens. */
import { MedsWizardDialog, SummaryRow } from '@/components/meds/wizard-shell';
import { Field, SelectInput, Segmented, StepHead } from '@/components/wizard/primitives';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { router } from '@inertiajs/react';
import { Award, CheckCircle2, ClipboardCheck, Eye, GraduationCap, Pencil, Plus, RotateCcw, ShieldCheck, Trash2, User } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

export const COMPETENCY_AREAS = [
    { key: 'medication_knowledge', label: 'Medication knowledge', core: true },
    { key: 'five_rights', label: 'Five rights', core: true },
    { key: 'safety_checks', label: 'Safety checks', core: true },
    { key: 'documentation', label: 'Documentation', core: true },
    { key: 'controlled_drugs', label: 'Controlled drugs', core: false },
    { key: 'prn_assessment', label: 'PRN assessment', core: false },
    { key: 'insulin_competent', label: 'Insulin administration', core: false },
    { key: 'inhaler_competent', label: 'Inhaler technique', core: false },
    { key: 'topical_competent', label: 'Topical application', core: false },
    { key: 'covert_admin_knowledge', label: 'Covert administration', core: false },
    { key: 'error_reporting', label: 'Error reporting', core: true },
    { key: 'allergy_awareness', label: 'Allergy awareness', core: true },
];
export const CORE_KEYS = COMPETENCY_AREAS.filter((a) => a.core).map((a) => a.key);
const MED_TYPES = ['oral', 'topical', 'inhaler', 'insulin', 'controlled', 'prn', 'covert', 'other'];
const OUTCOMES = [{ value: 'safe', label: 'Safe' }, { value: 'prompted', label: 'Prompted' }, { value: 'intervened', label: 'Intervened' }];
const TYPES = [{ value: 'initial', label: 'Initial' }, { value: 'annual', label: 'Annual' }, { value: 'remedial', label: 'Remedial' }, { value: 'return_to_work', label: 'Return to work' }];

export type ObservedRound = { resident?: string; med_type?: string; cd?: boolean; outcome?: string };
export type AssessmentRow = {
    id: number;
    user_id: number;
    user_name: string;
    user_role: string | null;
    assessor_name: string | null;
    assessment_type: string | null;
    status: string;
    assessment_date: string | null;
    expiry_date: string | null;
    not_seen_areas: string[];
    observed_rounds: ObservedRound[];
    restricted: boolean;
    restriction_notes: string | null;
    total_score: number | null;
    pass_threshold: number | null;
    strengths: string | null;
    areas_for_improvement: string | null;
    action_plan: string | null;
    assessor_comments: string | null;
    can_administer_unsupervised: boolean;
    can_witness_controlled: boolean;
    is_expired: boolean;
    is_passed: boolean;
};
export type StaffOpt = { id: number; name: string; role?: string | null };

type TriState = 'yes' | 'no' | 'not_seen';
const areaVal = (a: AssessmentRow, key: string) => (a as unknown as Record<string, boolean>)[key];
const addYear = (d: string) => { const dt = new Date(d); dt.setFullYear(dt.getFullYear() + 1); return dt.toISOString().slice(0, 10); };

export function statusChip(a: AssessmentRow): { label: string; cls: string } {
    if (a.is_expired) return { label: 'Expired', cls: 'bg-status-critical-bg text-status-critical' };
    if (a.is_passed && a.restricted) return { label: 'Supervised', cls: 'bg-status-warning-bg text-status-warning' };
    if (a.is_passed) return { label: 'In date', cls: 'bg-status-success-bg text-status-success' };
    return { label: 'Failed', cls: 'bg-status-critical-bg text-status-critical' };
}

// ── New / Edit / Renew assessment (5-step) ───────────────────────────────────
export function AssessmentWizardDialog({ staff, assessment, mode, defaultUserId, onClose }: { staff: StaffOpt[]; assessment?: AssessmentRow | null; mode: 'new' | 'edit' | 'renew'; defaultUserId?: number | null; onClose: () => void }) {
    const today = new Date().toISOString().slice(0, 10);
    const seedAreas = (): Record<string, TriState> => {
        const o: Record<string, TriState> = {};
        COMPETENCY_AREAS.forEach((ar) => {
            if (assessment) o[ar.key] = assessment.not_seen_areas.includes(ar.key) ? 'not_seen' : areaVal(assessment, ar.key) ? 'yes' : 'no';
            else o[ar.key] = 'not_seen';
        });
        return o;
    };
    const [step, setStep] = useState(0);
    const [busy, setBusy] = useState(false);
    const [userId, setUserId] = useState(assessment ? String(assessment.user_id) : defaultUserId ? String(defaultUserId) : '');
    const [type, setType] = useState(mode === 'renew' ? 'annual' : assessment?.assessment_type ?? 'initial');
    const [assessDate, setAssessDate] = useState(mode === 'edit' && assessment?.assessment_date ? assessment.assessment_date : today);
    const [expiry, setExpiry] = useState(mode === 'edit' && assessment?.expiry_date ? assessment.expiry_date : addYear(today));
    const [incident, setIncident] = useState('');
    const [areas, setAreas] = useState<Record<string, TriState>>(seedAreas);
    const [rounds, setRounds] = useState<ObservedRound[]>(mode === 'edit' && assessment ? assessment.observed_rounds : []);
    const [canUnsup, setCanUnsup] = useState(assessment?.can_administer_unsupervised ?? false);
    const [canWitness, setCanWitness] = useState(assessment?.can_witness_controlled ?? false);
    const [restricted, setRestricted] = useState(mode === 'edit' ? (assessment?.restricted ?? false) : false);
    const [restrictionNotes, setRestrictionNotes] = useState(mode === 'edit' ? (assessment?.restriction_notes ?? '') : '');
    const [strengths, setStrengths] = useState(mode === 'edit' ? (assessment?.strengths ?? '') : '');
    const [improvements, setImprovements] = useState(mode === 'edit' ? (assessment?.areas_for_improvement ?? '') : '');
    const [actionPlan, setActionPlan] = useState(mode === 'edit' ? (assessment?.action_plan ?? '') : '');
    const [assessorDeclared, setAssessorDeclared] = useState(false);
    const [staffDeclared, setStaffDeclared] = useState(false);

    const yesCount = COMPETENCY_AREAS.filter((a) => areas[a.key] === 'yes').length;
    const applicable = COMPETENCY_AREAS.filter((a) => areas[a.key] !== 'not_seen').length;
    const coreFail = CORE_KEYS.some((k) => areas[k] === 'no');
    const eligible = !coreFail && yesCount >= 10;
    const staffName = staff.find((s) => String(s.id) === userId)?.name;
    const staffRole = staff.find((s) => String(s.id) === userId)?.role;

    const submit = () => {
        setBusy(true);
        const payload: Record<string, unknown> = {
            user_id: Number(userId),
            assessment_type: type,
            assessment_date: assessDate,
            expiry_date: expiry,
            not_seen_areas: COMPETENCY_AREAS.filter((a) => areas[a.key] === 'not_seen').map((a) => a.key),
            observed_rounds: rounds.filter((r) => r.resident?.trim()),
            restricted,
            restriction_notes: restrictionNotes || null,
            strengths: strengths || null,
            areas_for_improvement: improvements || null,
            action_plan: actionPlan || null,
            assessor_comments: incident ? `Triggering incident: ${incident}` : (mode === 'edit' ? assessment?.assessor_comments : null),
            can_administer_unsupervised: eligible && canUnsup,
            can_witness_controlled: canWitness,
        };
        COMPETENCY_AREAS.forEach((a) => { payload[a.key] = areas[a.key] === 'yes'; });
        const opts = { preserveScroll: true, onSuccess: () => { toast.success(mode === 'edit' ? 'Assessment updated' : 'Assessment recorded'); onClose(); }, onError: () => toast.error('Please check the assessment details'), onFinish: () => setBusy(false) };
        const data = payload as Parameters<typeof router.post>[1];
        if (mode === 'edit' && assessment) router.put(`/emar/competency/${assessment.id}`, data, opts);
        else router.post('/emar/competency', data, opts);
    };

    const valid = [!!userId && !!type && !!assessDate, true, true, true, assessorDeclared && staffDeclared];
    const titleMode = mode === 'edit' ? 'Edit assessment' : mode === 'renew' ? 'Renew assessment' : 'New assessment';
    return (
        <MedsWizardDialog open onClose={onClose} title={titleMode} description="Record a medication competency assessment." railIcon={GraduationCap} railTitle={titleMode} railSubtitle={staffName ?? 'Medication competency'} steps={[{ key: 'ctx', label: 'Staff & context', blurb: 'Who & type', icon: User }, { key: 'areas', label: 'Competencies', blurb: '12 areas', icon: ShieldCheck }, { key: 'observed', label: 'Observed rounds', blurb: 'Direct obs', icon: Eye }, { key: 'outcome', label: 'Outcome', blurb: 'Permissions', icon: Award }, { key: 'sign', label: 'Review & sign', blurb: 'Declarations', icon: CheckCircle2 }]} stepIndex={step} onStepClick={(i) => i < step && setStep(i)} footer={<><Button variant="ghost" onClick={step === 0 ? onClose : () => setStep(step - 1)} disabled={busy}>{step === 0 ? 'Cancel' : 'Back'}</Button>{step < 4 ? <Button onClick={() => setStep(step + 1)} disabled={!valid[step]}>Continue</Button> : <Button onClick={submit} disabled={busy || !valid[4]}>{mode === 'edit' ? 'Save changes' : 'Record assessment'}</Button>}</>}>
            {step === 0 && (
                <>
                    <StepHead icon={User} title="Staff & context" blurb="Who is being assessed and why." />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Staff member" required>
                            <SelectInput value={userId} onChange={setUserId} placeholder="Select staff…" options={staff.map((s) => ({ value: String(s.id), label: s.name }))} />
                        </Field>
                        <Field label="Role">
                            <Input value={staffRole ?? ''} readOnly placeholder="—" />
                        </Field>
                        <Field label="Assessment type" required span>
                            <Segmented value={type} onChange={setType} options={TYPES} />
                        </Field>
                        <Field label="Assessment date" required>
                            <Input type="date" value={assessDate} onChange={(e) => { setAssessDate(e.target.value); if (mode !== 'edit') setExpiry(addYear(e.target.value)); }} />
                        </Field>
                        <Field label="Expiry date">
                            <Input type="date" value={expiry} onChange={(e) => setExpiry(e.target.value)} />
                        </Field>
                        {type === 'remedial' && (
                            <Field label="Linked medication error / incident" span>
                                <Input value={incident} onChange={(e) => setIncident(e.target.value)} placeholder="Reference the triggering error / incident" />
                            </Field>
                        )}
                    </div>
                </>
            )}
            {step === 1 && (
                <>
                    <StepHead icon={ShieldCheck} title="Competencies" blurb="Mark each area Yes / No / Not seen. A No on a CORE area must be resolved before unsupervised practice." />
                    <div className="flex flex-col gap-2">
                        {COMPETENCY_AREAS.map((a) => (
                            <div key={a.key} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border px-3 py-2">
                                <span className="text-sm font-medium">{a.label}{a.core && <span className="ml-2 rounded-full bg-accent px-1.5 py-0.5 text-[10px] font-semibold text-primary">CORE</span>}{areas[a.key] === 'no' && a.core && <span className="ml-2 text-[10px] font-semibold text-status-critical">resolve</span>}</span>
                                <Segmented value={areas[a.key]} onChange={(v) => setAreas((o) => ({ ...o, [a.key]: v as TriState }))} options={[{ value: 'yes', label: 'Yes' }, { value: 'no', label: 'No' }, { value: 'not_seen', label: 'Not seen' }]} />
                            </div>
                        ))}
                    </div>
                </>
            )}
            {step === 2 && (
                <>
                    <StepHead icon={Eye} title="Observed rounds" blurb="Log each directly-observed administration (NMC guideline: at least 12 across a range of residents)." />
                    <div className={`mb-3 rounded-lg border px-3 py-2 text-xs ${rounds.length >= 12 ? 'border-status-success/30 bg-status-success-bg/60 text-status-success' : 'border-status-warning/30 bg-status-warning-bg/60 text-status-warning'}`}>{rounds.length} of 12 observed administrations logged.</div>
                    <div className="flex flex-col gap-2">
                        {rounds.map((r, i) => (
                            <div key={i} className="grid grid-cols-1 gap-2 rounded-lg border p-3 sm:grid-cols-[1.4fr_1fr_1fr_auto]">
                                <Input value={r.resident ?? ''} onChange={(e) => setRounds((x) => x.map((row, idx) => idx === i ? { ...row, resident: e.target.value } : row))} placeholder="Resident" />
                                <SelectInput value={r.med_type ?? ''} onChange={(v) => setRounds((x) => x.map((row, idx) => idx === i ? { ...row, med_type: v } : row))} placeholder="Med type…" options={MED_TYPES.map((m) => ({ value: m, label: m }))} />
                                <SelectInput value={r.outcome ?? ''} onChange={(v) => setRounds((x) => x.map((row, idx) => idx === i ? { ...row, outcome: v } : row))} placeholder="Outcome…" options={OUTCOMES} />
                                <Button variant="ghost" size="icon" onClick={() => setRounds((x) => x.filter((_, idx) => idx !== i))} aria-label="Remove"><Trash2 className="h-4 w-4 text-status-critical" /></Button>
                            </div>
                        ))}
                        <div><Button variant="outline" size="sm" onClick={() => setRounds((x) => [...x, { resident: '', med_type: 'oral', outcome: 'safe' }])}><Plus className="h-3.5 w-3.5" />Add observed round</Button></div>
                    </div>
                </>
            )}
            {step === 3 && (
                <>
                    <StepHead icon={Award} title="Outcome & permissions" blurb="Eligibility is computed from the competencies." />
                    <div className={`mb-4 rounded-lg border px-4 py-3 text-sm ${eligible ? 'border-status-success/30 bg-status-success-bg/60 text-status-success' : 'border-status-warning/30 bg-status-warning-bg/60 text-status-warning'}`}>
                        Score {yesCount}/{applicable} · pass threshold {10}. {eligible ? 'Eligible for unsupervised administration.' : coreFail ? 'A core area is marked No — resolve before unsupervised practice.' : 'Below the pass threshold for unsupervised practice.'}
                    </div>
                    <div className="flex flex-col gap-2">
                        <label className={`flex items-center gap-2 text-sm ${!eligible ? 'opacity-50' : ''}`}>
                            <input type="checkbox" disabled={!eligible} checked={eligible && canUnsup} onChange={(e) => setCanUnsup(e.target.checked)} className="h-4 w-4 rounded border-border" />
                            Can administer medication unsupervised
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={canWitness} onChange={(e) => setCanWitness(e.target.checked)} className="h-4 w-4 rounded border-border" />
                            Can witness controlled drugs
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={restricted} onChange={(e) => setRestricted(e.target.checked)} className="h-4 w-4 rounded border-border" />
                            Competent with restrictions
                        </label>
                        {restricted && <Field label="Restriction notes" span><Input value={restrictionNotes} onChange={(e) => setRestrictionNotes(e.target.value)} placeholder="e.g. patches not yet observed" /></Field>}
                    </div>
                </>
            )}
            {step === 4 && (
                <>
                    <StepHead icon={CheckCircle2} title="Review & sign-off" blurb="Development notes and two-party declaration." />
                    <div className="grid grid-cols-1 gap-3">
                        <Field label="Strengths"><Input value={strengths} onChange={(e) => setStrengths(e.target.value)} placeholder="What went well" /></Field>
                        <Field label="Areas for improvement"><Input value={improvements} onChange={(e) => setImprovements(e.target.value)} placeholder="What to work on" /></Field>
                        <Field label="Action plan"><Input value={actionPlan} onChange={(e) => setActionPlan(e.target.value)} placeholder="Agreed next steps" /></Field>
                    </div>
                    <div className="mt-4 rounded-lg border px-4">
                        <SummaryRow label="Staff" value={staffName ?? '—'} />
                        <SummaryRow label="Score" value={`${yesCount}/${applicable}`} />
                        <SummaryRow label="Observed rounds" value={rounds.filter((r) => r.resident?.trim()).length} />
                        <SummaryRow label="Unsupervised" value={eligible && canUnsup ? 'Yes' : 'No'} />
                    </div>
                    <div className="mt-4 flex flex-col gap-2">
                        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={assessorDeclared} onChange={(e) => setAssessorDeclared(e.target.checked)} className="h-4 w-4 rounded border-border" />As assessor, I confirm this assessment is accurate.</label>
                        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={staffDeclared} onChange={(e) => setStaffDeclared(e.target.checked)} className="h-4 w-4 rounded border-border" />The staff member acknowledges the outcome and any restrictions.</label>
                    </div>
                </>
            )}
        </MedsWizardDialog>
    );
}

// ── View detail (read-only) ──────────────────────────────────────────────────
// Footer carries the standard Options action bar (mirrors prn-detail-dialog):
// Renew / reassess · Edit open the wizard in place via the parent; "View staff
// member" is the staff-centric jump to /staff/{id} (not the client care page).
export function ViewAssessmentDialog({ assessment, onClose, onRenew, onEdit, onViewStaff }: { assessment: AssessmentRow; onClose: () => void; onRenew?: () => void; onEdit?: () => void; onViewStaff?: () => void }) {
    const triFor = (key: string): TriState => assessment.not_seen_areas.includes(key) ? 'not_seen' : areaVal(assessment, key) ? 'yes' : 'no';
    const chips: string[] = [];
    if (assessment.can_administer_unsupervised) chips.push('Unsupervised');
    if (assessment.can_witness_controlled) chips.push('CD witness');
    if (assessment.restricted) chips.push('Restricted');
    if (!assessment.can_administer_unsupervised) chips.push('Supervised only');
    const viewStaff = onViewStaff ?? (() => router.visit(`/staff/${assessment.user_id}`));
    return (
        <MedsWizardDialog open onClose={onClose} title="Assessment detail" description={`${assessment.user_name} · ${assessment.assessment_type ?? 'assessment'}`} railIcon={ClipboardCheck} railTitle="Assessment" railSubtitle={assessment.user_name} steps={[{ key: 'detail', label: 'Summary', blurb: 'Read-only', icon: ClipboardCheck }]} stepIndex={0} onStepClick={() => {}} footer={
            <>
                <Button variant="outline" onClick={onClose}>Close</Button>
                <div className="flex items-center gap-2">
                    {onRenew && <Button onClick={onRenew}><RotateCcw className="h-4 w-4" />Renew / reassess</Button>}
                    {onEdit && <Button variant="outline" onClick={onEdit}><Pencil className="h-4 w-4" />Edit</Button>}
                    <Button variant="ghost" onClick={viewStaff}><User className="h-4 w-4" />View staff member</Button>
                </div>
            </>
        }>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <FactTile label="Type" value={assessment.assessment_type ?? '—'} />
                <FactTile label="Score" value={`${assessment.total_score ?? 0}/${assessment.pass_threshold ?? 12}`} />
                <FactTile label="Observed" value={assessment.observed_rounds.length} />
                <FactTile label="Expiry" value={assessment.expiry_date ?? '—'} />
            </div>
            <div className="mt-4 flex flex-wrap gap-1.5">
                {chips.map((c) => <span key={c} className="rounded-full bg-accent px-2 py-0.5 text-[11px] font-semibold text-primary">{c}</span>)}
            </div>
            <div className="mt-4 grid grid-cols-1 gap-1.5 sm:grid-cols-2">
                {COMPETENCY_AREAS.map((a) => {
                    const v = triFor(a.key);
                    return (
                        <div key={a.key} className="flex items-center justify-between rounded-lg border px-3 py-1.5 text-sm">
                            <span>{a.label}</span>
                            <span className={`text-xs font-semibold ${v === 'yes' ? 'text-status-success' : v === 'no' ? 'text-status-critical' : 'text-muted-foreground'}`}>{v === 'yes' ? 'Yes' : v === 'no' ? 'No' : 'Not seen'}</span>
                        </div>
                    );
                })}
            </div>
            {assessment.observed_rounds.length > 0 && (
                <div className="mt-4">
                    <div className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Observed rounds ({assessment.observed_rounds.length})</div>
                    <div className="flex flex-col gap-1.5">
                        {assessment.observed_rounds.map((r, i) => (
                            <div key={i} className="flex items-center justify-between gap-3 rounded-lg border px-3 py-1.5 text-sm">
                                <span className="font-medium">{r.resident || 'Resident'}</span>
                                <span className="flex items-center gap-2 text-xs text-muted-foreground">
                                    {r.med_type && <span className="rounded-full border px-1.5 py-0.5 capitalize">{r.med_type}</span>}
                                    {r.cd && <span className="rounded bg-status-critical-bg px-1 py-0.5 text-[9px] font-bold text-status-critical">CD</span>}
                                    {r.outcome && <span className={`font-semibold capitalize ${r.outcome === 'safe' ? 'text-status-success' : r.outcome === 'intervened' ? 'text-status-critical' : 'text-status-warning'}`}>{r.outcome}</span>}
                                </span>
                            </div>
                        ))}
                    </div>
                </div>
            )}
            {assessment.restriction_notes && <Note label="Restrictions" value={assessment.restriction_notes} />}
            {assessment.strengths && <Note label="Strengths" value={assessment.strengths} />}
            {assessment.areas_for_improvement && <Note label="Areas for improvement" value={assessment.areas_for_improvement} />}
            {assessment.action_plan && <Note label="Action plan" value={assessment.action_plan} />}
            {assessment.assessor_comments && <Note label="Assessor comments" value={assessment.assessor_comments} />}
            {/* TODO(G1): sign-off declarations (assessor + staff acknowledgement) are captured in the
                wizard but not persisted, so we surface the assessor/date audit line only — see docs/COMPETENCY_GAP_ANALYSIS.md. */}
            <div className="mt-4 text-xs text-muted-foreground">Assessed {assessment.assessment_date ?? '—'}{assessment.assessor_name ? ` by ${assessment.assessor_name}` : ''}.</div>
        </MedsWizardDialog>
    );
}

function FactTile({ label, value }: { label: string; value: string | number }) {
    return (
        <div className="rounded-xl border bg-muted/30 px-3 py-2">
            <div className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">{label}</div>
            <div className="text-sm font-bold">{value}</div>
        </div>
    );
}
function Note({ label, value }: { label: string; value: string }) {
    return (
        <div className="mt-3">
            <div className="mb-0.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{label}</div>
            <p className="text-sm">{value}</p>
        </div>
    );
}
