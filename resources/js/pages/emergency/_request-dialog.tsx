/* eslint-disable no-restricted-syntax -- the break-glass request wizard uses styled native search
   result rows / radio cards / duration chips inside the wizard shell (not Card/Button); all colours
   are semantic tokens. */
import { MedsWizardDialog, SummaryRow } from '@/components/meds/wizard-shell';
import { Field, StepHead } from '@/components/wizard/primitives';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { router } from '@inertiajs/react';
import { Check, KeyRound, Search, ShieldCheck, User } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

export type ClientLite = { id: number; first_name: string; last_name: string; date_of_birth?: string | null; status?: string | null; site?: { id: number; name: string } | null };

const REASON_CATEGORIES = [
    { value: 'Clinical urgency', detail: 'An urgent clinical need for a client you are not assigned to.' },
    { value: 'Medication round cover', detail: 'Covering a round for an absent colleague.' },
    { value: 'Handover gap', detail: 'A handover gap left a resident without cover.' },
    { value: 'Visiting / transfer', detail: 'A visiting or transferring resident.' },
    { value: 'Family / GP request', detail: 'A request from family or the GP.' },
    { value: 'Other', detail: 'Another reason — describe it below.' },
];
const DURATIONS = [30, 60, 120, 240];

export function RequestAccessDialog({ results, query, onSearch, onClose }: { results: ClientLite[]; query: string; onSearch: (q: string) => void; onClose: () => void }) {
    const [step, setStep] = useState(0);
    const [client, setClient] = useState<ClientLite | null>(null);
    const [category, setCategory] = useState('');
    const [detail, setDetail] = useState('');
    const [duration, setDuration] = useState(60);
    const [ackMin, setAckMin] = useState(false);
    const [ackInc, setAckInc] = useState(false);
    const [busy, setBusy] = useState(false);
    const [search, setSearch] = useState(query ?? '');

    const doSearch = (v: string) => { setSearch(v); if (v.trim().length >= 2) onSearch(v); };
    const submit = () => {
        if (!client) return;
        setBusy(true);
        const reason = `${category}${detail.trim() ? ` — ${detail.trim()}` : ''}`;
        router.post(`/clients/${client.id}/break-glass`, { reason, minutes: duration }, {
            preserveScroll: true,
            onSuccess: () => { toast.success(`Emergency access granted — expires in ${duration} min · logged to audit`); onClose(); },
            onError: () => toast.error('Could not grant emergency access'),
            onFinish: () => setBusy(false),
        });
    };

    const valid = [!!client, !!category && (category !== 'Other' || !!detail.trim()), ackMin && ackInc];
    return (
        <MedsWizardDialog open onClose={onClose} title="Request emergency access" description="Temporary, time-limited break-glass access to a client you are not assigned to." railIcon={KeyRound} railTitle="Break-glass" railSubtitle={client ? `${client.first_name} ${client.last_name}` : 'Emergency access'} steps={[{ key: 'client', label: 'Find client', blurb: 'Who', icon: User }, { key: 'justify', label: 'Justify', blurb: 'Reason & duration', icon: ShieldCheck }, { key: 'grant', label: 'Review & grant', blurb: 'Confirm', icon: Check }]} stepIndex={step} onStepClick={(i) => i < step && setStep(i)} footer={<><Button variant="ghost" onClick={step === 0 ? onClose : () => setStep(step - 1)} disabled={busy}>{step === 0 ? 'Cancel' : 'Back'}</Button>{step < 2 ? <Button onClick={() => setStep(step + 1)} disabled={!valid[step]}>Continue</Button> : <Button onClick={submit} disabled={busy || !valid[2]}>Grant access</Button>}</>}>
            {step === 0 && (
                <>
                    <StepHead icon={User} title="Find client" blurb="Search for the resident who needs emergency access." />
                    <div className="flex items-center gap-2 rounded-lg border border-input bg-background px-3">
                        <Search className="h-4 w-4 text-muted-foreground" />
                        <input value={search} onChange={(e) => doSearch(e.target.value)} placeholder="Search name (min 2 characters)…" className="h-10 flex-1 bg-transparent text-sm outline-none" />
                    </div>
                    <div className="mt-3 flex flex-col gap-1.5">
                        {search.trim().length < 2 ? <p className="text-sm text-muted-foreground">Type at least two characters to search.</p> : results.length === 0 ? <p className="text-sm text-muted-foreground">No matching residents.</p> : results.map((c) => {
                            const selected = client?.id === c.id;
                            return (
                                <button key={c.id} type="button" onClick={() => setClient(c)} className={`flex items-center gap-3 rounded-lg border px-3 py-2 text-left ${selected ? 'border-primary bg-primary/5' : 'border-border hover:bg-muted'}`}>
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
                    <StepHead icon={ShieldCheck} title="Justify" blurb="Every break-glass activation needs a reason and a duration." />
                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        {REASON_CATEGORIES.map((c) => (
                            <button key={c.value} type="button" onClick={() => setCategory(c.value)} className={`rounded-xl border-2 p-3 text-left ${category === c.value ? 'border-primary bg-primary/5' : 'border-border'}`}>
                                <div className="text-sm font-semibold">{c.value}</div>
                                <div className="text-xs text-muted-foreground">{c.detail}</div>
                            </button>
                        ))}
                    </div>
                    <Field label={`Detail${category === 'Other' ? ' (required)' : ''}`} span>
                        <Input value={detail} onChange={(e) => setDetail(e.target.value)} placeholder="What is the urgent need?" />
                    </Field>
                    <div className="mt-3"><div className="mb-1.5 text-sm font-medium">Duration</div><div className="flex flex-wrap gap-2">{DURATIONS.map((d) => (<button key={d} type="button" onClick={() => setDuration(d)} className={`rounded-full px-3 py-1 text-xs font-medium ${duration === d ? 'bg-primary text-primary-foreground' : 'border border-border text-muted-foreground hover:bg-muted'}`}>{d < 60 ? `${d} min` : `${d / 60} h`}</button>))}</div></div>
                </>
            )}
            {step === 2 && (
                <>
                    <StepHead icon={Check} title="Review & grant" blurb="Confirm — access auto-expires and is logged to the break-glass audit." />
                    <div className="rounded-lg border px-4">
                        <SummaryRow label="Client" value={client ? `${client.first_name} ${client.last_name}` : '—'} />
                        <SummaryRow label="Site" value={client?.site?.name ?? '—'} />
                        <SummaryRow label="Reason" value={`${category}${detail.trim() ? ` — ${detail.trim()}` : ''}`} />
                        <SummaryRow label="Duration" value={duration < 60 ? `${duration} min` : `${duration / 60} h`} />
                    </div>
                    <div className="mt-4 flex flex-col gap-2">
                        <label className="flex items-start gap-2 text-sm"><input type="checkbox" checked={ackMin} onChange={(e) => setAckMin(e.target.checked)} className="mt-0.5 h-4 w-4 rounded border-border" />I will access only the minimum necessary for this clinical need.</label>
                        <label className="flex items-start gap-2 text-sm"><input type="checkbox" checked={ackInc} onChange={(e) => setAckInc(e.target.checked)} className="mt-0.5 h-4 w-4 rounded border-border" />I will complete an incident report within 48 hours where required.</label>
                    </div>
                </>
            )}
        </MedsWizardDialog>
    );
}
