/**
 * New booking-request intake — a compact pop-up that turns an accepted referral
 * (or an existing client) into a RespiteBookingRequest. Posts to the request
 * store with _modal so the workspace stays put and the lists refresh in place;
 * nothing navigates. Funding + cultural detail carried from the referral is
 * filled server-side, so this form only collects dates + placement preferences.
 */
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import { CalendarPlus, Check, Sparkles, X } from 'lucide-react';
import { useEffect, type ReactNode } from 'react';
import type {
    ClientOption,
    FundingOption,
    RespiteReferralRow,
    ServiceAgreementSummary,
} from '../types';

interface RequestForm {
    client_id: string;
    requested_start: string;
    nights: string;
    service_context_id: string;
    funding_source: string;
    funding_reference: string;
    service_agreement_id: string;
    priority: 'routine' | 'priority' | 'crisis';
    preference_notes: string;
}

const BLANK: RequestForm = {
    client_id: '',
    requested_start: '',
    nights: '7',
    service_context_id: '',
    funding_source: '',
    funding_reference: '',
    service_agreement_id: '',
    priority: 'routine',
    preference_notes: '',
};

/** Date-only arithmetic, kept in local time to avoid UTC off-by-one drift. */
function addDays(date: string, nights: number): string {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date);
    if (!m) return '';
    const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
    d.setDate(d.getDate() + Math.max(1, nights));
    const mo = String(d.getMonth() + 1).padStart(2, '0');
    const da = String(d.getDate()).padStart(2, '0');
    return `${d.getFullYear()}-${mo}-${da}`;
}

export function RequestIntakeModal({
    open,
    onClose,
    referral,
    clients,
    serviceContexts,
    serviceAgreements,
    fundingSources,
}: {
    open: boolean;
    onClose: () => void;
    referral: RespiteReferralRow | null;
    clients: ClientOption[];
    serviceContexts: { id: number; name: string }[];
    serviceAgreements: (ServiceAgreementSummary & { clientId: number })[];
    fundingSources: FundingOption[];
}) {
    const form = useForm<RequestForm>({ ...BLANK });
    const { data, setData, processing } = form;
    // Server errors may key on transformed fields (e.g. requested_end) that
    // aren't form fields, so read them through a string-keyed view.
    const err = form.errors as Record<string, string | undefined>;

    useEffect(() => {
        if (!open) return;
        form.clearErrors();
        form.setData({
            ...BLANK,
            client_id: referral?.clientId ? String(referral.clientId) : '',
            funding_source: referral?.fundingSource ?? '',
            funding_reference: referral?.fundingReference ?? '',
            priority: referral?.urgency === 'crisis' ? 'crisis' : 'routine',
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, referral]);

    const lockedClient = referral?.clientId != null;
    const agreementsForClient = serviceAgreements.filter(
        (a) => String(a.clientId) === data.client_id,
    );

    const valid =
        data.client_id !== '' &&
        data.requested_start !== '' &&
        Number(data.nights) >= 1;

    const submit = () => {
        if (!valid) return;
        form.transform((d) => ({
            _modal: true,
            referral_id: referral?.id ?? null,
            client_id: d.client_id,
            requested_start: d.requested_start,
            requested_end: addDays(d.requested_start, Number(d.nights)),
            service_context_id: d.service_context_id || null,
            funding_source: d.funding_source || null,
            funding_reference: d.funding_reference || null,
            service_agreement_id: d.service_agreement_id || null,
            priority: d.priority,
            preference_notes: d.preference_notes || null,
        }));
        form.post('/respite/requests', {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-xl gap-0 overflow-hidden p-0">
                <div className="border-b border-border p-5">
                    <div className="flex items-center gap-3">
                        <span className="grid h-9 w-9 place-items-center rounded-[11px] bg-primary/10 text-primary">
                            <CalendarPlus className="h-5 w-5" />
                        </span>
                        <div>
                            <DialogTitle className="text-base">
                                New booking request
                            </DialogTitle>
                            <DialogDescription>
                                Reviewed and approved before a confirmed booking
                                is created.
                            </DialogDescription>
                        </div>
                    </div>
                </div>

                <div className="max-h-[60vh] space-y-3.5 overflow-y-auto p-5">
                    {referral ? (
                        <div className="flex items-start gap-2 rounded-[10px] bg-status-info-bg p-3 text-[12.5px] text-status-info">
                            <Sparkles className="mt-0.5 h-4 w-4 shrink-0" />
                            <span>
                                From referral <strong>{referral.ref}</strong> —
                                funding and cultural detail carry across
                                automatically.
                            </span>
                        </div>
                    ) : null}

                    <Field label="Client" error={err.client_id}>
                        {lockedClient ? (
                            <div className="flex h-9 items-center rounded-md border border-input bg-muted px-3 text-sm font-medium">
                                {referral?.client}
                            </div>
                        ) : (
                            <NativeSelect
                                value={data.client_id}
                                onChange={(v) => setData('client_id', v)}
                            >
                                <option value="">Choose a client…</option>
                                {clients.map((c) => (
                                    <option key={c.id} value={String(c.id)}>
                                        {c.first_name} {c.last_name}
                                    </option>
                                ))}
                            </NativeSelect>
                        )}
                    </Field>

                    <div className="grid grid-cols-[1.4fr_0.6fr] gap-3">
                        <Field
                            label="Requested start"
                            error={err.requested_start}
                        >
                            <Input
                                type="date"
                                value={data.requested_start}
                                onChange={(e) =>
                                    setData('requested_start', e.target.value)
                                }
                            />
                        </Field>
                        <Field label="Nights" error={err.requested_end}>
                            <Input
                                type="number"
                                min={1}
                                value={data.nights}
                                onChange={(e) =>
                                    setData('nights', e.target.value)
                                }
                            />
                        </Field>
                    </div>

                    <Field label="Priority">
                        <Segmented
                            value={data.priority}
                            onChange={(v) => setData('priority', v)}
                            options={[
                                { value: 'routine', label: 'Routine' },
                                {
                                    value: 'priority',
                                    label: 'Priority',
                                    tone: 'warning',
                                },
                                {
                                    value: 'crisis',
                                    label: 'Crisis',
                                    tone: 'critical',
                                },
                            ]}
                        />
                    </Field>

                    <Field label="Service context" hint="optional">
                        <NativeSelect
                            value={data.service_context_id}
                            onChange={(v) => setData('service_context_id', v)}
                        >
                            <option value="">Use client's default</option>
                            {serviceContexts.map((s) => (
                                <option key={s.id} value={String(s.id)}>
                                    {s.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>

                    <div className="grid grid-cols-2 gap-3">
                        <Field label="Funding source" hint="optional">
                            <NativeSelect
                                value={data.funding_source}
                                onChange={(v) => setData('funding_source', v)}
                            >
                                <option value="">Not set</option>
                                {fundingSources.map((f) => (
                                    <option key={f.value} value={f.value}>
                                        {f.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field label="Funding reference" hint="optional">
                            <Input
                                value={data.funding_reference}
                                onChange={(e) =>
                                    setData('funding_reference', e.target.value)
                                }
                                placeholder="44213"
                            />
                        </Field>
                    </div>

                    {agreementsForClient.length > 0 ? (
                        <Field label="Service agreement" hint="optional">
                            <NativeSelect
                                value={data.service_agreement_id}
                                onChange={(v) =>
                                    setData('service_agreement_id', v)
                                }
                            >
                                <option value="">None</option>
                                {agreementsForClient.map((a) => (
                                    <option key={a.id} value={String(a.id)}>
                                        {a.title ?? `Agreement #${a.id}`}
                                        {a.referenceNumber
                                            ? ` · ${a.referenceNumber}`
                                            : ''}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                    ) : null}

                    <Field label="Preference notes" hint="optional">
                        <Textarea
                            value={data.preference_notes}
                            onChange={(e) =>
                                setData('preference_notes', e.target.value)
                            }
                            rows={3}
                            placeholder="Preferred home, room, support needs…"
                        />
                    </Field>
                </div>

                <div className="flex items-center justify-between gap-3 border-t border-border bg-muted/40 p-4">
                    <Button type="button" variant="ghost" onClick={onClose}>
                        <X className="h-3.5 w-3.5" /> Cancel
                    </Button>
                    <Button
                        type="button"
                        onClick={submit}
                        disabled={!valid || processing}
                    >
                        <Check className="h-3.5 w-3.5" /> Submit request
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}

/* ---- primitives (kept local for parity with referral-intake) ------------ */

function Field({
    label,
    hint,
    error,
    children,
}: {
    label: string;
    hint?: string;
    error?: string;
    children: ReactNode;
}) {
    return (
        <div>
            <Label className="mb-1.5 flex items-center gap-2 text-[12.5px]">
                {label}
                {hint ? (
                    <span className="font-normal text-muted-foreground">
                        {hint}
                    </span>
                ) : null}
            </Label>
            {children}
            {error ? (
                <p className="mt-1 text-[11.5px] text-status-critical">
                    {error}
                </p>
            ) : null}
        </div>
    );
}

function NativeSelect({
    value,
    onChange,
    children,
}: {
    value: string;
    onChange: (v: string) => void;
    children: ReactNode;
}) {
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
                    <Button unstyled
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
                    </Button>
                );
            })}
        </div>
    );
}
