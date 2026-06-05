/**
 * New-referral intake — a 4-step guided pop-up (Client → Referrer → Respite
 * need → Funding & review). The client step either links an existing person or
 * captures a new lightweight one (completed later by the onboarding wizard).
 * Posts to the referral store; nothing navigates.
 */
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Check, Inbox, X, Zap } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import type { ClientOption, FundingOption, RespiteHome } from '../types';

const STEPS = ['Client', 'Referrer', 'Respite need', 'Funding & review'];
const REFERRER_TYPES = ['DHB', 'NGO', 'GP', 'NASC', 'Family'];

interface IntakeForm {
    mode: 'new' | 'existing';
    client_id: string;
    first_name: string;
    last_name: string;
    date_of_birth: string;
    nhi_number: string;
    site_id: string;
    referrer_name: string;
    referrer_type: string;
    referrer_contact: string;
    urgency: 'planned' | 'urgent' | 'crisis';
    referral_reason: string;
    preferred_start: string;
    nights: string;
    funding_source: string;
    funding_ref: string;
}

const BLANK: IntakeForm = {
    mode: 'new',
    client_id: '',
    first_name: '',
    last_name: '',
    date_of_birth: '',
    nhi_number: '',
    site_id: '',
    referrer_name: '',
    referrer_type: 'NGO',
    referrer_contact: '',
    urgency: 'planned',
    referral_reason: '',
    preferred_start: '',
    nights: '7',
    funding_source: '',
    funding_ref: '',
};

export function ReferralIntakeModal({
    open,
    onClose,
    clients,
    homes,
    fundingSources,
}: {
    open: boolean;
    onClose: () => void;
    clients: ClientOption[];
    homes: RespiteHome[];
    fundingSources: FundingOption[];
}) {
    const form = useForm<IntakeForm>({ ...BLANK });
    const { data, setData, processing, errors } = form;
    const [step, setStep] = useState(0);

    useEffect(() => {
        if (open) {
            form.reset();
            form.clearErrors();
            setStep(0);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const stepValid = (() => {
        if (step === 0) return data.mode === 'existing' ? data.client_id !== '' : data.first_name.trim().length > 1;
        if (step === 1) return data.referrer_name.trim().length > 1;
        if (step === 2) return data.referral_reason.trim().length > 3;
        return true;
    })();

    const submit = () => {
        form.transform((d) => {
            const triage: string[] = [];
            if (d.preferred_start || d.nights) {
                triage.push(`Preferred: ${d.preferred_start || 'flexible'}${d.nights ? ` · ${d.nights} nights` : ''}`);
            }
            const base = {
                referrer_name: d.referrer_name,
                referrer_type: d.referrer_type,
                referrer_contact: d.referrer_contact || null,
                referral_reason: d.referral_reason,
                urgency: d.urgency,
                risk_level: d.urgency === 'crisis' ? 'high' : d.urgency === 'urgent' ? 'medium' : null,
                funding_source: d.funding_source || null,
                funding_reference: d.funding_ref || null,
            };
            if (d.mode === 'existing') {
                return { ...base, client_id: d.client_id, triage_notes: triage.join('\n') || null };
            }
            return {
                ...base,
                new_client: {
                    first_name: d.first_name,
                    last_name: d.last_name || null,
                    date_of_birth: d.date_of_birth || null,
                    nhi_number: d.nhi_number || null,
                    site_id: d.site_id || null,
                },
                triage_notes: triage.join('\n') || null,
            };
        });
        form.post('/respite/referrals', {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    const next = () => {
        if (!stepValid) return;
        if (step < STEPS.length - 1) setStep(step + 1);
        else submit();
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-xl gap-0 overflow-hidden p-0">
                <div className="border-b border-border p-5">
                    <div className="flex items-center gap-3">
                        <span className="grid h-9 w-9 place-items-center rounded-[11px] bg-primary/10 text-primary">
                            <Inbox className="h-5 w-5" />
                        </span>
                        <div>
                            <DialogTitle className="text-base">New respite referral</DialogTitle>
                            <DialogDescription>Capture an intake — it lands in the triage queue.</DialogDescription>
                        </div>
                    </div>
                    <Stepper step={step} />
                </div>

                <div className="max-h-[60vh] overflow-y-auto p-5">
                    {step === 0 && <ClientStep data={data} setData={setData} clients={clients} homes={homes} err={errors} />}
                    {step === 1 && <ReferrerStep data={data} setData={setData} err={errors} />}
                    {step === 2 && <NeedStep data={data} setData={setData} err={errors} />}
                    {step === 3 && <ReviewStep data={data} setData={setData} fundingSources={fundingSources} />}
                </div>

                <div className="flex items-center justify-between gap-3 border-t border-border bg-muted/40 p-4">
                    <Button type="button" variant="ghost" onClick={() => (step === 0 ? onClose() : setStep(step - 1))}>
                        {step === 0 ? (
                            <>
                                <X className="h-3.5 w-3.5" /> Cancel
                            </>
                        ) : (
                            <>
                                <ArrowLeft className="h-3.5 w-3.5" /> Back
                            </>
                        )}
                    </Button>
                    <div className="flex items-center gap-3">
                        <span className="text-xs text-muted-foreground">
                            Step {step + 1} of {STEPS.length}
                        </span>
                        <Button type="button" onClick={next} disabled={!stepValid || processing}>
                            {step === STEPS.length - 1 ? (
                                <>
                                    <Check className="h-3.5 w-3.5" /> Submit referral
                                </>
                            ) : (
                                <>
                                    Continue <ArrowRight className="h-3.5 w-3.5" />
                                </>
                            )}
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}

/* ---- stepper ------------------------------------------------------------ */

function Stepper({ step }: { step: number }) {
    return (
        <div className="mt-4 flex items-center">
            {STEPS.map((label, i) => {
                const state = i < step ? 'done' : i === step ? 'active' : 'todo';
                return (
                    <div key={label} className="flex flex-1 items-center last:flex-none">
                        <div className="flex items-center gap-2">
                            <span
                                className={cn(
                                    'grid h-6 w-6 place-items-center rounded-full text-[11px] font-bold',
                                    state === 'todo' ? 'bg-muted text-muted-foreground' : 'bg-primary text-primary-foreground',
                                )}
                            >
                                {state === 'done' ? <Check className="h-3 w-3" /> : i + 1}
                            </span>
                            <span className={cn('hidden text-xs font-semibold sm:inline', state === 'todo' && 'text-muted-foreground')}>
                                {label}
                            </span>
                        </div>
                        {i < STEPS.length - 1 ? <div className={cn('mx-2 h-0.5 flex-1 rounded', i < step ? 'bg-primary' : 'bg-border')} /> : null}
                    </div>
                );
            })}
        </div>
    );
}

/* ---- field primitives --------------------------------------------------- */

type SetData = ReturnType<typeof useForm<IntakeForm>>['setData'];

function Field({ label, hint, error, children }: { label: string; hint?: string; error?: string; children: ReactNode }) {
    return (
        <div className="mb-3.5">
            <Label className="mb-1.5 flex items-center gap-2 text-[12.5px]">
                {label}
                {hint ? <span className="font-normal text-muted-foreground">{hint}</span> : null}
            </Label>
            {children}
            {error ? <p className="mt-1 text-[11.5px] text-status-critical">{error}</p> : null}
        </div>
    );
}

function Segmented<T extends string>({
    value,
    onChange,
    options,
}: {
    value: T;
    onChange: (v: T) => void;
    options: { value: T; label: string; tone?: 'warning' | 'critical' }[];
}) {
    return (
        <div className="flex flex-wrap gap-1.5">
            {options.map((o) => {
                const active = value === o.value;
                return (
                    <button
                        key={o.value}
                        type="button"
                        onClick={() => onChange(o.value)}
                        className={cn(
                            'flex-1 rounded-[9px] border px-3 py-2 text-[13px] font-semibold transition-colors',
                            active
                                ? o.tone === 'critical'
                                    ? 'border-transparent bg-status-critical text-white'
                                    : o.tone === 'warning'
                                      ? 'border-transparent bg-status-warning text-white'
                                      : 'border-transparent bg-primary text-primary-foreground'
                                : 'border-border bg-card text-muted-foreground hover:bg-muted',
                        )}
                    >
                        {o.label}
                    </button>
                );
            })}
        </div>
    );
}

function NativeSelect({ value, onChange, children }: { value: string; onChange: (v: string) => void; children: ReactNode }) {
    return (
        <select
            value={value}
            onChange={(e) => onChange(e.target.value)}
            className="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
        >
            {children}
        </select>
    );
}

/* ---- steps -------------------------------------------------------------- */

function ClientStep({
    data,
    setData,
    clients,
    homes,
    err,
}: {
    data: IntakeForm;
    setData: SetData;
    clients: ClientOption[];
    homes: RespiteHome[];
    err: Partial<Record<string, string>>;
}) {
    return (
        <>
            <div className="mb-4 inline-flex rounded-[9px] border border-border bg-muted p-0.5">
                {(['new', 'existing'] as const).map((m) => (
                    <button
                        key={m}
                        type="button"
                        onClick={() => setData('mode', m)}
                        className={cn(
                            'rounded-[7px] px-3 py-1.5 text-[12.5px] font-semibold transition-colors',
                            data.mode === m ? 'bg-card text-primary shadow-sm' : 'text-muted-foreground',
                        )}
                    >
                        {m === 'new' ? 'New person' : 'Existing client'}
                    </button>
                ))}
            </div>

            {data.mode === 'existing' ? (
                <Field label="Client" error={err.client_id}>
                    <NativeSelect value={data.client_id} onChange={(v) => setData('client_id', v)}>
                        <option value="">Choose a client…</option>
                        {clients.map((c) => (
                            <option key={c.id} value={String(c.id)}>
                                {c.first_name} {c.last_name}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
            ) : (
                <>
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="First name" error={err['new_client.first_name']}>
                            <Input value={data.first_name} onChange={(e) => setData('first_name', e.target.value)} placeholder="e.g. Aroha" />
                        </Field>
                        <Field label="Last name">
                            <Input value={data.last_name} onChange={(e) => setData('last_name', e.target.value)} placeholder="e.g. Ngata" />
                        </Field>
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="Date of birth" hint="optional">
                            <Input type="date" value={data.date_of_birth} onChange={(e) => setData('date_of_birth', e.target.value)} />
                        </Field>
                        <Field label="NHI number" hint="optional">
                            <Input value={data.nhi_number} onChange={(e) => setData('nhi_number', e.target.value)} placeholder="ABC1234" />
                        </Field>
                    </div>
                    <Field label="Preferred home" hint="optional">
                        <NativeSelect value={data.site_id} onChange={(v) => setData('site_id', v)}>
                            <option value="">No preference</option>
                            {homes.map((h) => (
                                <option key={h.id} value={String(h.id)}>
                                    {h.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                </>
            )}
        </>
    );
}

function ReferrerStep({ data, setData, err }: { data: IntakeForm; setData: SetData; err: Partial<Record<string, string>> }) {
    return (
        <>
            <Field label="Referrer name / organisation" error={err.referrer_name}>
                <Input value={data.referrer_name} onChange={(e) => setData('referrer_name', e.target.value)} placeholder="e.g. Te Whatu Ora — Waitematā" />
            </Field>
            <Field label="Referrer type">
                <Segmented value={data.referrer_type} onChange={(v) => setData('referrer_type', v)} options={REFERRER_TYPES.map((t) => ({ value: t, label: t }))} />
            </Field>
            <Field label="Contact phone or email" hint="optional">
                <Input value={data.referrer_contact} onChange={(e) => setData('referrer_contact', e.target.value)} placeholder="09 555 0100 / referrer@example.nz" />
            </Field>
        </>
    );
}

function NeedStep({ data, setData, err }: { data: IntakeForm; setData: SetData; err: Partial<Record<string, string>> }) {
    return (
        <>
            <Field label="Urgency">
                <Segmented
                    value={data.urgency}
                    onChange={(v) => setData('urgency', v)}
                    options={[
                        { value: 'planned', label: 'Planned' },
                        { value: 'urgent', label: 'Urgent', tone: 'warning' },
                        { value: 'crisis', label: 'Crisis', tone: 'critical' },
                    ]}
                />
            </Field>
            {data.urgency === 'crisis' ? (
                <div className="mb-3.5 flex items-start gap-2.5 rounded-[10px] bg-status-critical-bg p-3 text-[12.5px] text-status-critical">
                    <Zap className="mt-0.5 h-4 w-4 shrink-0" />
                    <span>
                        <strong>Crisis referral</strong> — flags a 24-hour triage priority. Set the risk level to High and notify the on-call coordinator.
                    </span>
                </div>
            ) : null}
            <Field label="Reason for referral" error={err.referral_reason}>
                <Textarea
                    value={data.referral_reason}
                    onChange={(e) => setData('referral_reason', e.target.value)}
                    placeholder="Brief context — carer situation, support needs, behaviours to be aware of…"
                    rows={3}
                />
            </Field>
            <div className="grid grid-cols-[1.4fr_0.6fr] gap-3">
                <Field label="Preferred start" hint="optional">
                    <Input type="date" value={data.preferred_start} onChange={(e) => setData('preferred_start', e.target.value)} />
                </Field>
                <Field label="Nights" hint="optional">
                    <Input type="number" value={data.nights} onChange={(e) => setData('nights', e.target.value)} />
                </Field>
            </div>
        </>
    );
}

function ReviewStep({ data, setData, fundingSources }: { data: IntakeForm; setData: SetData; fundingSources: FundingOption[] }) {
    const rows: [string, string][] = [
        ['Client', data.mode === 'existing' ? (data.client_id ? 'Existing client' : '—') : `${data.first_name} ${data.last_name}`.trim() || '—'],
        ['Referrer', `${data.referrer_name || '—'} · ${data.referrer_type}`],
        ['Urgency', data.urgency],
        ['Preferred', data.preferred_start ? `${data.preferred_start} · ${data.nights} nights` : `${data.nights} nights (flexible)`],
    ];
    return (
        <>
            <div className="grid grid-cols-2 gap-3">
                <Field label="Funding source">
                    <Segmented
                        value={data.funding_source}
                        onChange={(v) => setData('funding_source', v)}
                        options={fundingSources}
                    />
                </Field>
                <Field label="Funding reference" hint="optional">
                    <Input value={data.funding_ref} onChange={(e) => setData('funding_ref', e.target.value)} placeholder="44213" />
                </Field>
            </div>
            <div className="mt-1 overflow-hidden rounded-xl border border-border">
                <div className="bg-muted px-3.5 py-2 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Review</div>
                <dl className="px-3.5">
                    {rows.map(([k, v], i) => (
                        <div key={i} className={cn('flex justify-between gap-4 py-2 text-[13px]', i < rows.length - 1 && 'border-b border-border/60')}>
                            <dt className="text-muted-foreground">{k}</dt>
                            <dd className="text-right font-semibold capitalize">{v}</dd>
                        </div>
                    ))}
                </dl>
            </div>
        </>
    );
}
