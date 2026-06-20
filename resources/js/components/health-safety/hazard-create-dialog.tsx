/* HazardCreateDialog — the "Log hazard" create wizard, on WizardShell.
 * Five steps: Site & type (recommended quick-add chips + Other) → Risk rating
 * (clickable matrix + live result) → Detail (description / location / photos /
 * immediate action / witnesses) → Assign & due → Review. POSTs to
 * /sites/{site}/hazards with the photo File[] (forceFormData) and refreshes in
 * place. Reused by the global register and the per-site surfaces. NZ-only. */
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { ReviewCard, ReviewRow, WizardShell, WizardStepPane, WizardSuccessPane, type WizardStep } from '@/components/wizard/shell';
import { Field, InfoCard, SelectInput, StepHead } from '@/components/wizard/primitives';
import {
    HAZARD_LABELS,
    HazardRiskMatrix,
    LIKELIHOOD_LABELS,
    LIKELIHOOD_ORDER,
    RISK,
    SEV,
    SEVERITY_ORDER,
    SUGGESTED_DUE_DAYS,
    requiresOfficer,
    riskOf,
    siteTypeLabel,
    type HazardRisk,
    type HazardSeverity,
} from '@/components/health-safety/hazard-kit';
import { useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { AlertTriangle, Camera, CheckCircle2, FileText, Gauge, Home, UserPlus } from 'lucide-react';

type SiteOpt = { id: number; name: string; type: string };
type Chip = { key: string; label: string; hint: string };

const STEPS: WizardStep[] = [
    { key: 'site', label: 'Site & type', blurb: 'Where & what', icon: Home },
    { key: 'risk', label: 'Risk rating', blurb: 'Severity × likelihood', icon: Gauge },
    { key: 'detail', label: 'Detail', blurb: 'Description & action', icon: FileText },
    { key: 'assign', label: 'Assign & due', blurb: 'Owner & date', icon: UserPlus },
    { key: 'review', label: 'Review', blurb: 'Confirm & log', icon: CheckCircle2 },
];

type CreateForm = {
    hazard_type: string;
    custom_hazard_type: string;
    severity: string;
    likelihood: string;
    description: string;
    location: string;
    witnesses: string;
    immediate_action_taken: string;
    immediate_action_applied: boolean;
    assigned_to_user_id: string;
    due_date: string;
    photos: File[];
};

function addDays(n: number): string {
    const d = new Date();
    d.setDate(d.getDate() + n);
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
}

export function HazardCreateDialog({
    open,
    onClose,
    sites,
    fixedSite = null,
    recommendedBySiteType,
    staff,
    severityOptions,
    likelihoodOptions,
    prefillType = null,
    onSuccess,
}: {
    open: boolean;
    onClose: () => void;
    sites: SiteOpt[];
    fixedSite?: SiteOpt | null;
    recommendedBySiteType: Record<string, Chip[]>;
    staff: Array<{ id: number; name: string }>;
    severityOptions: string[];
    likelihoodOptions: string[];
    prefillType?: string | null;
    onSuccess?: () => void;
}) {
    const [step, setStep] = useState(0);
    const [siteId, setSiteId] = useState<number | null>(fixedSite?.id ?? null);
    const [done, setDone] = useState(false);

    const form = useForm<CreateForm>({
        hazard_type: prefillType ?? '',
        custom_hazard_type: '',
        severity: '',
        likelihood: '',
        description: '',
        location: '',
        witnesses: '',
        immediate_action_taken: '',
        immediate_action_applied: false,
        assigned_to_user_id: '',
        due_date: '',
        photos: [],
    });

    const site = fixedSite ?? sites.find((s) => s.id === siteId) ?? null;
    const chips: Chip[] = site ? recommendedBySiteType[site.type] ?? [] : [];
    const risk = riskOf(form.data.severity, form.data.likelihood);

    const setRisk = (severity: HazardSeverity, likelihood: string) => {
        form.setData((data) => {
            const rating = riskOf(severity, likelihood);
            return {
                ...data,
                severity,
                likelihood,
                due_date: data.due_date || (rating ? addDays(SUGGESTED_DUE_DAYS[rating]) : ''),
            };
        });
    };

    const canAdvance = useMemo(() => {
        if (step === 0) return !!site && !!form.data.hazard_type && (form.data.hazard_type !== 'other' || form.data.custom_hazard_type.trim().length > 0);
        if (step === 1) return !!form.data.severity && !!form.data.likelihood;
        if (step === 2) return form.data.description.trim().length > 0;
        return true;
    }, [step, site, form.data]);

    const blockMsg = step === 0 ? 'Choose a site and a hazard type' : step === 1 ? 'Select a severity and likelihood' : step === 2 ? 'Add a description' : '';

    const submit = () => {
        if (!site) return;
        form.transform((data) => ({ ...data, immediate_action_applied: data.immediate_action_applied ? 1 : 0 }));
        form.post(`/sites/${site.id}/hazards`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: (page) => {
                const flash = (page.props as { flash?: { error?: string } }).flash;
                if (flash?.error) return;
                setDone(true);
            },
        });
    };

    const reset = () => {
        form.reset();
        setStep(0);
        if (!fixedSite) setSiteId(null);
        setDone(false);
    };

    const close = () => {
        reset();
        onClose();
    };

    if (done) {
        return (
            <WizardShell
                open={open}
                onClose={close}
                title="Hazard logged"
                description="The hazard has been added to the register."
                railIcon={CheckCircle2}
                railTitle="Logged"
                railSub="Hazard created"
                steps={STEPS}
                stepIndex={STEPS.length - 1}
                onStepClick={() => undefined}
                pct={100}
                success={
                    <WizardSuccessPane
                        title="Hazard logged"
                        blurb={`The hazard at ${site?.name ?? 'the site'} has been created with status Open and added to the register.`}
                        actions={
                            <>
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        reset();
                                    }}
                                >
                                    Log another
                                </Button>
                                <Button
                                    onClick={() => {
                                        onSuccess?.();
                                        close();
                                    }}
                                >
                                    Done
                                </Button>
                            </>
                        }
                    />
                }
            />
        );
    }

    const footerEnd = (
        <div className="flex items-center gap-2">
            {step > 0 ? (
                <Button variant="outline" onClick={() => setStep((s) => s - 1)}>
                    Back
                </Button>
            ) : null}
            {step < STEPS.length - 1 ? (
                <Button disabled={!canAdvance} title={!canAdvance ? blockMsg : undefined} onClick={() => setStep((s) => s + 1)}>
                    Continue
                </Button>
            ) : (
                <Button disabled={form.processing} onClick={submit}>
                    Log hazard
                </Button>
            )}
        </div>
    );

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="Log hazard"
            description="Record a new physical or environmental hazard at a home or facility."
            railIcon={AlertTriangle}
            railTitle="Log hazard"
            railSub={risk ? `${RISK[risk].label} risk` : 'New hazard'}
            steps={STEPS}
            stepIndex={step}
            onStepClick={(i) => i < step && setStep(i)}
            pct={null}
            footerStart={!canAdvance && blockMsg ? <span className="text-xs text-muted-foreground">{blockMsg}</span> : null}
            footerEnd={footerEnd}
        >
            <WizardStepPane>
                {step === 0 ? (
                    <div className="flex flex-col gap-4">
                        <StepHead icon={Home} title="Where is the hazard?" blurb="Hazards are recorded against a home or facility." />
                        {fixedSite ? (
                            <InfoCard icon={Home} tone="info">
                                Logging against <span className="font-semibold">{fixedSite.name}</span> · {siteTypeLabel(fixedSite.type)}.
                            </InfoCard>
                        ) : (
                            <Field label="Site" required>
                                <SelectInput
                                    value={siteId ? String(siteId) : ''}
                                    onChange={(v) => {
                                        setSiteId(Number(v));
                                        form.setData('hazard_type', '');
                                    }}
                                    placeholder="Select a site"
                                    options={sites.map((s) => ({ value: String(s.id), label: `${s.name} — ${siteTypeLabel(s.type)}` }))}
                                />
                            </Field>
                        )}

                        {site ? (
                            <div>
                                <p className="mb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">Common hazards for a {siteTypeLabel(site.type).toLowerCase()} — tap to quick-add</p>
                                <div className="grid gap-2 sm:grid-cols-2">
                                    {chips.map((c) => {
                                        const on = form.data.hazard_type === c.key;
                                        return (
                                            // eslint-disable-next-line no-restricted-syntax -- recommended-hazard quick-add tile, not a shadcn Button
                                            <button
                                                key={c.key}
                                                type="button"
                                                aria-pressed={on}
                                                onClick={() => form.setData('hazard_type', c.key)}
                                                className={`flex items-start gap-2 rounded-lg border p-2.5 text-left transition-colors ${on ? 'border-primary/50 bg-primary/5' : 'border-border hover:bg-muted'}`}
                                            >
                                                <span className={`mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full ${on ? 'bg-primary text-primary-foreground' : 'border border-muted-foreground/40'}`}>
                                                    {on ? <CheckCircle2 className="h-3 w-3" /> : null}
                                                </span>
                                                <span className="min-w-0">
                                                    <span className="block text-sm font-medium text-foreground">{c.label}</span>
                                                    <span className="block text-xs text-muted-foreground">{c.hint}</span>
                                                </span>
                                            </button>
                                        );
                                    })}
                                    {/* Other */}
                                    {(() => {
                                        const on = form.data.hazard_type === 'other';
                                        return (
                                            // eslint-disable-next-line no-restricted-syntax -- recommended-hazard quick-add tile, not a shadcn Button
                                            <button
                                                type="button"
                                                aria-pressed={on}
                                                onClick={() => form.setData('hazard_type', 'other')}
                                                className={`flex items-start gap-2 rounded-lg border p-2.5 text-left transition-colors ${on ? 'border-primary/50 bg-primary/5' : 'border-border hover:bg-muted'}`}
                                            >
                                                <span className={`mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full ${on ? 'bg-primary text-primary-foreground' : 'border border-muted-foreground/40'}`}>
                                                    {on ? <CheckCircle2 className="h-3 w-3" /> : null}
                                                </span>
                                                <span className="min-w-0">
                                                    <span className="block text-sm font-medium text-foreground">Other / not listed</span>
                                                    <span className="block text-xs text-muted-foreground">Type your own hazard type.</span>
                                                </span>
                                            </button>
                                        );
                                    })()}
                                </div>
                                {form.data.hazard_type === 'other' ? (
                                    <div className="mt-3">
                                        <Field label="Describe the hazard type" required>
                                            <Input value={form.data.custom_hazard_type} onChange={(e) => form.setData('custom_hazard_type', e.target.value)} placeholder="e.g. Window restrictor missing on first floor" />
                                        </Field>
                                    </div>
                                ) : null}
                            </div>
                        ) : null}
                    </div>
                ) : null}

                {step === 1 ? (
                    <div className="flex flex-col gap-4">
                        <StepHead icon={Gauge} title="Rate the risk" blurb="Severity × likelihood gives the risk rating from the WorkSafe matrix." />
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="Severity" required>
                                <SelectInput value={form.data.severity} onChange={(v) => setRisk(v as HazardSeverity, form.data.likelihood)} placeholder="How bad if it happens?" options={severityOptions.map((s) => ({ value: s, label: SEV[s]?.label ?? s }))} />
                            </Field>
                            <Field label="Likelihood" required>
                                <SelectInput value={form.data.likelihood} onChange={(v) => setRisk(form.data.severity as HazardSeverity, v)} placeholder="How likely?" options={likelihoodOptions.map((l) => ({ value: l, label: LIKELIHOOD_LABELS[l] ?? l }))} />
                            </Field>
                        </div>
                        <div>
                            <p className="mb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">Risk matrix — tap a cell to set both</p>
                            <HazardRiskMatrix severity={form.data.severity} likelihood={form.data.likelihood} onPick={setRisk} />
                        </div>
                        {risk ? (
                            <div className="rounded-xl border border-border bg-muted/40 p-3">
                                <p className="text-lg font-bold">
                                    <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-sm font-semibold ${riskBg(risk)}`}>{RISK[risk].label} risk</span>
                                </p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Suggested resolution within {SUGGESTED_DUE_DAYS[risk]} day{SUGGESTED_DUE_DAYS[risk] === 1 ? '' : 's'}
                                    {requiresOfficer(risk) ? ' · H&S officer assignment required' : ''}
                                </p>
                            </div>
                        ) : null}
                    </div>
                ) : null}

                {step === 2 ? (
                    <div className="flex flex-col gap-4">
                        <StepHead icon={FileText} title="Describe the hazard" blurb="What is the hazard, where is it, and what was done straight away?" />
                        <Field label="Description" required>
                            <Textarea rows={3} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} placeholder="Describe the hazard, where it is, and who is exposed." />
                        </Field>
                        <Field label="Location">
                            <Input value={form.data.location} onChange={(e) => form.setData('location', e.target.value)} placeholder="e.g. Main bathroom, rear corridor, garden path" />
                        </Field>
                        <Field label="Photos">
                            <FileDropzone onFiles={(f) => form.setData('photos', [...form.data.photos, ...f])} accept="image/*" title="Add photos of the hazard" hint="JPG, PNG — helps the assigned owner" />
                            {form.data.photos.length ? (
                                <div className="mt-2 grid gap-2">
                                    {form.data.photos.map((f, i) => (
                                        <StagedFileCard key={i} file={f} onRemove={() => form.setData('photos', form.data.photos.filter((_, idx) => idx !== i))} />
                                    ))}
                                </div>
                            ) : null}
                        </Field>
                        <Field label="Immediate action taken">
                            <Textarea rows={2} value={form.data.immediate_action_taken} onChange={(e) => form.setData('immediate_action_taken', e.target.value)} placeholder="What did you do right away to make it safe?" />
                        </Field>
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={form.data.immediate_action_applied} onChange={(e) => form.setData('immediate_action_applied', e.target.checked)} className="h-4 w-4 rounded border-border" />
                            Immediate action has been applied and the area is safe
                        </label>
                        <Field label="Witnesses">
                            <Textarea rows={2} value={form.data.witnesses} onChange={(e) => form.setData('witnesses', e.target.value)} placeholder="Names and contact details of any witnesses (optional)." />
                        </Field>
                    </div>
                ) : null}

                {step === 3 ? (
                    <div className="flex flex-col gap-4">
                        <StepHead
                            icon={UserPlus}
                            title="Assign an owner"
                            blurb={risk && requiresOfficer(risk) ? `This is a ${RISK[risk].label.toLowerCase()}-risk hazard — an H&S officer must own it.` : 'Optional, but assigning an owner speeds resolution.'}
                        />
                        <Field label="Owner">
                            <SelectInput value={form.data.assigned_to_user_id} onChange={(v) => form.setData('assigned_to_user_id', v)} placeholder="Select a staff member" options={staff.map((s) => ({ value: String(s.id), label: s.name }))} />
                        </Field>
                        <Field label="Resolution due date">
                            <Input type="date" value={form.data.due_date} onChange={(e) => form.setData('due_date', e.target.value)} />
                        </Field>
                        {risk ? (
                            <InfoCard icon={Camera} tone="info">
                                Suggested due date is pre-filled from the {RISK[risk].label.toLowerCase()} risk rating ({SUGGESTED_DUE_DAYS[risk]} day{SUGGESTED_DUE_DAYS[risk] === 1 ? '' : 's'}). Adjust if needed.
                            </InfoCard>
                        ) : null}
                    </div>
                ) : null}

                {step === 4 ? (
                    <div className="flex flex-col gap-3">
                        <StepHead icon={CheckCircle2} title="Review & log" blurb="Confirm the details. The hazard is created with status Open." />
                        <div className="grid gap-3 sm:grid-cols-2">
                            <ReviewCard icon={Home} title="Site & type" onEdit={() => setStep(0)}>
                                <ReviewRow label="Site" value={site ? `${site.name} · ${siteTypeLabel(site.type)}` : '—'} />
                                <ReviewRow label="Type" value={form.data.hazard_type === 'other' ? form.data.custom_hazard_type : HAZARD_LABELS[form.data.hazard_type] ?? form.data.hazard_type} />
                            </ReviewCard>
                            <ReviewCard icon={Gauge} title="Risk" onEdit={() => setStep(1)}>
                                <ReviewRow label="Severity" value={SEV[form.data.severity]?.label} />
                                <ReviewRow label="Likelihood" value={LIKELIHOOD_LABELS[form.data.likelihood]} />
                                <ReviewRow label="Risk rating" value={risk ? RISK[risk].label : '—'} />
                            </ReviewCard>
                            <ReviewCard icon={FileText} title="Detail" span onEdit={() => setStep(2)}>
                                <ReviewRow label="Description" value={form.data.description} />
                                <ReviewRow label="Location" value={form.data.location} />
                                <ReviewRow label="Photos" value={form.data.photos.length ? `${form.data.photos.length} attached` : '—'} />
                                <ReviewRow label="Witnesses" value={form.data.witnesses} />
                                <ReviewRow label="Immediate action" value={form.data.immediate_action_taken} />
                            </ReviewCard>
                            <ReviewCard icon={UserPlus} title="Owner & due" span onEdit={() => setStep(3)}>
                                <ReviewRow label="Owner" value={staff.find((s) => String(s.id) === form.data.assigned_to_user_id)?.name ?? 'Unassigned'} />
                                <ReviewRow label="Due" value={form.data.due_date || '—'} />
                            </ReviewCard>
                        </div>
                    </div>
                ) : null}
            </WizardStepPane>
        </WizardShell>
    );
}

export default HazardCreateDialog;

function riskBg(rating: HazardRisk): string {
    const tone = RISK[rating].tone;
    return tone === 'critical' ? 'bg-status-critical-bg text-status-critical' : tone === 'warning' ? 'bg-status-warning-bg text-status-warning' : 'bg-status-success-bg text-status-success';
}
