/* eslint-disable no-restricted-syntax -- the break-glass request wizard uses styled native search
   result rows / radio cards / duration chips / authorisation tiles inside the wizard shell (not
   Card/Button); all colours are semantic tokens. */
import { MedsWizardDialog, SummaryRow } from '@/components/meds/wizard-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Field, StepHead } from '@/components/wizard/primitives';
import { router } from '@inertiajs/react';
import { Check, Fingerprint, KeyRound, Search, ShieldCheck, User, Users } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

export type ClientLite = { id: number; first_name: string; last_name: string; date_of_birth?: string | null; status?: string | null; site?: { id: number; name: string } | null };
export type Approver = { id: number; name: string; role: string | null };

const REASON_CATEGORIES = [
    { value: 'Staff absence / cover', detail: 'Covering an absent colleague — medications due.' },
    { value: 'New / after-hours admission', detail: 'A new or after-hours admission needs meds now.' },
    { value: 'PRN / urgent pain relief', detail: 'An urgent PRN / pain-relief request.' },
    { value: 'Primary carer unavailable', detail: 'The assigned carer is off-site or unavailable.' },
    { value: 'Clinical deterioration', detail: 'A resident is deteriorating and needs review.' },
    { value: 'Other', detail: 'Another reason — describe it below.' },
];
const DURATIONS = [30, 60, 120, 240];
const fmtDur = (d: number) => (d < 60 ? `${d} min` : `${d / 60} h`);

export function RequestAccessDialog({
    results,
    query,
    approvers,
    prefillClient,
    onSearch,
    onClose,
}: {
    results: ClientLite[];
    query: string;
    approvers: Approver[];
    prefillClient?: ClientLite | null;
    onSearch: (q: string) => void;
    onClose: () => void;
}) {
    // When deep-linked from the MAR interstitial the client is already known, so skip Find.
    const [step, setStep] = useState(prefillClient ? 1 : 0);
    const [client, setClient] = useState<ClientLite | null>(prefillClient ?? null);
    const [category, setCategory] = useState('');
    const [detail, setDetail] = useState('');
    const [duration, setDuration] = useState(60);
    const [authMode, setAuthMode] = useState<'co_sign' | 'self'>('self');
    const [approver, setApprover] = useState<number | null>(null);
    const [selfConfirm, setSelfConfirm] = useState(false);
    const [ackMin, setAckMin] = useState(false);
    const [ackInc, setAckInc] = useState(false);
    const [busy, setBusy] = useState(false);
    const [search, setSearch] = useState(query ?? '');

    const doSearch = (v: string) => { setSearch(v); if (v.trim().length >= 2) onSearch(v); };
    const submit = () => {
        if (!client) return;
        setBusy(true);
        const reason = detail.trim() || category;
        router.post(`/clients/${client.id}/break-glass`, {
            reason,
            reason_category: category,
            minutes: duration,
            authorization_mode: authMode,
            co_signed_by: authMode === 'co_sign' ? approver : null,
            acknowledged_min_necessary: ackMin,
            acknowledged_incident_report: ackInc,
        }, {
            preserveScroll: true,
            onSuccess: () => { toast.success(`Emergency access granted — expires in ${fmtDur(duration)} · logged to audit`); onClose(); },
            onError: () => toast.error('Could not grant emergency access'),
            onFinish: () => setBusy(false),
        });
    };

    const valid = [
        !!client,
        !!category && (category !== 'Other' || !!detail.trim()),
        authMode === 'co_sign' ? !!approver : selfConfirm,
        ackMin && ackInc,
    ];
    const authLabel = authMode === 'self' ? 'Self-authorised (RN+)' : `Co-sign · ${approvers.find((a) => a.id === approver)?.name ?? '—'}`;

    return (
        <MedsWizardDialog
            open
            onClose={onClose}
            title="Request emergency access"
            description="Temporary, time-limited break-glass access to a client you are not assigned to."
            railIcon={KeyRound}
            railTitle="Request access"
            railSubtitle={client ? `${client.first_name} ${client.last_name}` : 'Break-glass · time-limited'}
            steps={[
                { key: 'client', label: 'Find client', blurb: 'Who needs care', icon: User },
                { key: 'justify', label: 'Justify', blurb: 'Reason & duration', icon: ShieldCheck },
                { key: 'authorize', label: 'Authorise', blurb: 'Co-sign / self', icon: Fingerprint },
                { key: 'grant', label: 'Review & grant', blurb: 'Confirm & log', icon: Check },
            ]}
            stepIndex={step}
            onStepClick={(i) => i < step && setStep(i)}
            footer={
                <>
                    <Button variant="ghost" onClick={step === 0 ? onClose : () => setStep(step - 1)} disabled={busy}>{step === 0 ? 'Cancel' : 'Back'}</Button>
                    {step < 3 ? (
                        <Button onClick={() => setStep(step + 1)} disabled={!valid[step]}>Continue</Button>
                    ) : (
                        <Button onClick={submit} disabled={busy || !valid[3]}><ShieldCheck className="h-4 w-4" />Grant access</Button>
                    )}
                </>
            }
        >
            {step === 0 && (
                <>
                    <StepHead icon={Search} title="Who needs care?" blurb="Search by name. Only minimal identity is shown until access is granted." />
                    <div className="flex items-center gap-2 rounded-lg border border-input bg-background px-3">
                        <Search className="h-4 w-4 text-muted-foreground" />
                        <input aria-label="Search client by name" value={search} onChange={(e) => doSearch(e.target.value)} placeholder="Search client by name (min 2 characters)…" className="h-10 flex-1 bg-transparent text-sm outline-none" />
                    </div>
                    <div className="mt-3 flex flex-col gap-1.5">
                        {search.trim().length < 2 ? <p className="text-sm text-muted-foreground">Type at least two characters to search.</p> : results.length === 0 ? <p className="text-sm text-muted-foreground">No matching residents.</p> : results.map((c) => {
                            const selected = client?.id === c.id;
                            return (
                                <button key={c.id} type="button" aria-pressed={selected} onClick={() => setClient(c)} className={`flex items-center gap-3 rounded-lg border px-3 py-2 text-left ${selected ? 'border-primary bg-primary/5' : 'border-border hover:bg-muted'}`}>
                                    <span className="flex h-8 w-8 items-center justify-center rounded-full bg-primary/10 text-[11px] font-bold text-primary">{`${c.first_name[0] ?? ''}${c.last_name[0] ?? ''}`.toUpperCase()}</span>
                                    <div className="flex-1"><div className="text-sm font-medium">{c.first_name} {c.last_name}</div><div className="text-xs text-muted-foreground">{[c.date_of_birth, c.site?.name].filter(Boolean).join(' · ')}</div></div>
                                    {selected && <Check className="h-4 w-4 text-primary" />}
                                </button>
                            );
                        })}
                    </div>
                </>
            )}
            {step === 1 && (
                <>
                    <StepHead icon={ShieldCheck} title="Why is access needed?" blurb="A structured reason is required — it is recorded in the audit log and shown to reviewers." />
                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        {REASON_CATEGORIES.map((c) => (
                            <button key={c.value} type="button" aria-pressed={category === c.value} onClick={() => setCategory(c.value)} className={`rounded-xl border-2 p-3 text-left ${category === c.value ? 'border-primary bg-primary/5' : 'border-border'}`}>
                                <div className="text-sm font-semibold">{c.value}</div>
                                <div className="text-xs text-muted-foreground">{c.detail}</div>
                            </button>
                        ))}
                    </div>
                    <Field label={`Detail${category === 'Other' ? ' (required)' : ''}`} hint="what is happening right now" span>
                        <Input value={detail} onChange={(e) => setDetail(e.target.value)} placeholder="e.g. Covering unplanned sick leave; 16:00 medications due and primary carer is off-site." />
                    </Field>
                    <div className="mt-3">
                        <div className="mb-1.5 text-sm font-medium">Duration</div>
                        <div className="flex flex-wrap gap-2">
                            {DURATIONS.map((d) => (
                                <button key={d} type="button" aria-pressed={duration === d} onClick={() => setDuration(d)} className={`rounded-full px-3 py-1 text-xs font-medium ${duration === d ? 'bg-primary text-primary-foreground' : 'border border-border text-muted-foreground hover:bg-muted'}`}>{fmtDur(d)}</button>
                            ))}
                        </div>
                        <div className="mt-2 text-xs text-muted-foreground">Access expires automatically at the end of this window. Max {DURATIONS[DURATIONS.length - 1] / 60} hours.</div>
                    </div>
                </>
            )}
            {step === 2 && (
                <>
                    <StepHead icon={Fingerprint} title="Authorise the access" blurb="Record who is accountable. Co-sign names a second approver, or self-authorise as an RN or above." />
                    <div className="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                        <button type="button" aria-pressed={authMode === 'co_sign'} onClick={() => setAuthMode('co_sign')} className={`rounded-xl border-2 p-3.5 text-left ${authMode === 'co_sign' ? 'border-primary bg-primary/5' : 'border-border'}`}>
                            <div className="flex items-center gap-2 text-sm font-semibold"><Users className="h-4 w-4" />Co-sign (dual auth)</div>
                            <div className="mt-1 text-xs text-muted-foreground">A second authorised staff member is recorded as approver.</div>
                        </button>
                        <button type="button" aria-pressed={authMode === 'self'} onClick={() => setAuthMode('self')} className={`rounded-xl border-2 p-3.5 text-left ${authMode === 'self' ? 'border-primary bg-primary/5' : 'border-border'}`}>
                            <div className="flex items-center gap-2 text-sm font-semibold"><KeyRound className="h-4 w-4" />Self-authorise (RN+)</div>
                            <div className="mt-1 text-xs text-muted-foreground">Permitted for senior clinicians. Logged against you alone.</div>
                        </button>
                    </div>
                    {authMode === 'co_sign' ? (
                        <div className="mt-4">
                            <div className="mb-2 text-sm font-medium">Approving staff member</div>
                            {approvers.length === 0 ? <p className="text-sm text-muted-foreground">No other staff available to co-sign.</p> : (
                                <div className="flex max-h-64 flex-col gap-1.5 overflow-y-auto">
                                    {approvers.map((a) => {
                                        const selected = approver === a.id;
                                        return (
                                            <button key={a.id} type="button" aria-pressed={selected} onClick={() => setApprover(a.id)} className={`flex items-center gap-3 rounded-lg border px-3 py-2 text-left ${selected ? 'border-primary bg-primary/5' : 'border-border hover:bg-muted'}`}>
                                                <span className="flex h-8 w-8 items-center justify-center rounded-full bg-primary/10 text-[11px] font-bold text-primary">{a.name.split(' ').filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase()}</span>
                                                <div className="flex-1"><div className="text-sm font-medium">{a.name}</div>{a.role && <div className="text-xs capitalize text-muted-foreground">{a.role.replace(/_/g, ' ')}</div>}</div>
                                                {selected && <Check className="h-4 w-4 text-primary" />}
                                            </button>
                                        );
                                    })}
                                </div>
                            )}
                        </div>
                    ) : (
                        <label className="mt-4 flex items-start gap-2.5 rounded-xl border border-border p-3.5 text-sm">
                            <input type="checkbox" checked={selfConfirm} onChange={(e) => setSelfConfirm(e.target.checked)} className="mt-0.5 h-4 w-4 rounded border-border" />
                            I am a Registered Nurse or above and accept sole accountability for this break-glass activation.
                        </label>
                    )}
                </>
            )}
            {step === 3 && (
                <>
                    <StepHead icon={Check} title="Review & confirm" blurb="This is logged the moment you grant access — it auto-expires and is retained for audit." />
                    <div className="rounded-lg border px-4">
                        <SummaryRow label="Client" value={client ? `${client.first_name} ${client.last_name}` : '—'} />
                        <SummaryRow label="Site" value={client?.site?.name ?? '—'} />
                        <SummaryRow label="Reason" value={`${category}${detail.trim() ? ` — ${detail.trim()}` : ''}`} />
                        <SummaryRow label="Duration" value={fmtDur(duration)} />
                        <SummaryRow label="Authorisation" value={authLabel} />
                    </div>
                    <div className="mt-4 flex flex-col gap-2">
                        <label className="flex items-start gap-2 text-sm"><input type="checkbox" checked={ackMin} onChange={(e) => setAckMin(e.target.checked)} className="mt-0.5 h-4 w-4 rounded border-border" />I will access only what is needed for immediate care (<strong>minimum necessary</strong>).</label>
                        <label className="flex items-start gap-2 text-sm"><input type="checkbox" checked={ackInc} onChange={(e) => setAckInc(e.target.checked)} className="mt-0.5 h-4 w-4 rounded border-border" />I will complete an <strong>incident report within 48 hours</strong> of this activation where required.</label>
                    </div>
                </>
            )}
        </MedsWizardDialog>
    );
}
