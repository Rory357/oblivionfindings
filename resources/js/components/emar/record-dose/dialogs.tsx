/* eMAR P01 — the recording dialog's simple companions, on the popup guide's
 * simple-dialog layout (SettingsModal): "Why can't I record this?" (NF-07)
 * and "Recorded and reported" (more than ordered was given). */
import { PersonDisc } from '@/components/lists/entity-cells';
import { SettingsModal } from '@/components/settings/settings-modal';
import { Button } from '@/components/ui/button';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { StatusBadge } from '@/components/ui/status-badge';
import { LogIn, MessageSquare, Pill, UserCheck } from 'lucide-react';
import type { ComponentProps } from 'react';
import { blockAllCopy } from './copy';
import { BlockedPanel, IdentityHeader, Notice, NotConfigured, activeBlock } from './parts';
import { useDoseRequirements } from './use-dose-requirements';
import { isBlockedAnswer, type BlockedRequirements, type DoseTarget } from './types';

const TZ = 'Pacific/Auckland';
const timeLabel = (iso: string | null | undefined) =>
    iso ? new Date(iso).toLocaleTimeString('en-NZ', { hour: 'numeric', minute: '2-digit', timeZone: TZ }) : '';

/**
 * "Why can't I record this?" when nothing can be recorded. The server sends
 * only the reason and the house (P0-1) — no identity details, allergies,
 * order or colleagues — so the person and medicine are named from the row
 * that opened it, and there is nothing to record: the reason, the next step
 * and the one real action.
 */
export function BlockedWhy({
    answer,
    target,
    onClose,
    onCloseAutoFocus,
}: {
    answer: BlockedRequirements;
    target: DoseTarget;
    onClose: () => void;
    onCloseAutoFocus?: ComponentProps<typeof SettingsModal>['onCloseAutoFocus'];
}) {
    const label = target.label ?? null;
    const copy = blockAllCopy(answer.block_all, label);
    const house = typeof answer.block_all.facts.house === 'string' ? answer.block_all.facts.house : null;
    const when = target.kind === 'scheduled' ? timeLabel(target.scheduledFor) : 'as needed';

    return (
        <SettingsModal
            width={720}
            title="Why can’t I record this?"
            description={label ? `${label.person} · ${label.medicine} · ${when}` : 'This dose'}
            onClose={onClose}
            onCloseAutoFocus={onCloseAutoFocus}
            footer={
                <>
                    <Button className="frontline-tap" variant={copy.action === 'clock-in' ? 'outline' : 'default'} onClick={onClose}>
                        Close
                    </Button>
                    {copy.action === 'clock-in' ? (
                        <Button asChild className="frontline-tap">
                            <a href="/attendance">
                                <LogIn className="size-4" /> Clock in
                            </a>
                        </Button>
                    ) : null}
                </>
            }
        >
            {label ? (
                <section aria-label="Person" className="flex items-center gap-3 rounded-xl border bg-card px-3 py-2.5">
                    <PersonDisc name={label.person} size={36} />
                    <div className="min-w-0 flex-1">
                        <span className="text-[15px] font-semibold">{label.person}</span>
                        {house ? <div className="text-caption">{house}</div> : null}
                    </div>
                </section>
            ) : null}
            <BlockedPanel copy={copy} />
        </SettingsModal>
    );
}

export function WhyDialog({
    target,
    onClose,
    onRecordNotGiven,
    onEligibility,
}: {
    target: DoseTarget;
    onClose: () => void;
    onRecordNotGiven: () => void;
    onEligibility?: () => void;
}) {
    const state = useDoseRequirements(target);
    if (state.status !== 'ready') {
        return (
            <SettingsModal
                width={720}
                title="Why can’t I record this?"
                description={state.status === 'loading' ? 'Checking the dose…' : 'We can’t show this dose. It may not exist, or it may not be available to you.'}
                onClose={onClose}
                footer={
                    <Button className="frontline-tap" variant="outline" onClick={onClose}>
                        Close
                    </Button>
                }
            >
                {null}
            </SettingsModal>
        );
    }
    if (isBlockedAnswer(state.data)) {
        return <BlockedWhy answer={state.data} target={target} onClose={onClose} />;
    }
    const req = state.data;
    const active = activeBlock(req);
    const still = active?.copy.still;
    const allowNotGiven = still === 'notgiven' || still === 'withheld' || still === 'withheld-always';
    const action = active?.copy.action;

    return (
        <SettingsModal
            width={720}
            title="Why can’t I record this?"
            description={`${req.person.preferred_name} · ${req.order.name}${req.due ? ` · ${timeLabel(req.due.due_at)}` : ''}`}
            onClose={onClose}
            footer={
                <>
                    <Button className="frontline-tap" variant="outline" onClick={onClose}>
                        Close
                    </Button>
                    {allowNotGiven ? (
                        <Button className="frontline-tap" variant={action ? 'outline' : 'default'} onClick={onRecordNotGiven}>
                            Record not given
                        </Button>
                    ) : null}
                    {action === 'clock-in' ? (
                        <Button asChild className="frontline-tap">
                            <a href="/attendance">
                                <LogIn className="size-4" /> Clock in
                            </a>
                        </Button>
                    ) : action === 'eligibility' && onEligibility ? (
                        <Button className="frontline-tap" onClick={onEligibility}>
                            <UserCheck className="size-4" /> View my eligibility
                        </Button>
                    ) : action === 'message-lead' && req.house_lead ? (
                        <Button asChild className="frontline-tap">
                            <a href="/operations/messages">
                                <MessageSquare className="size-4" /> Message {req.house_lead.name}
                            </a>
                        </Button>
                    ) : null}
                </>
            }
        >
            <IdentityHeader req={req} compact />
            {active ? (
                <BlockedPanel copy={active.copy} req={req} />
            ) : (
                <Notice tone="success" title="Nothing blocks this dose now">
                    It can be recorded. Checked again when you save.
                </Notice>
            )}
        </SettingsModal>
    );
}

/** One as-needed order the worker may choose (Meds today's prn_medications). */
export interface AsNeededChoice {
    id: number;
    client_id: number;
    client_name: string;
    name: string;
    given_last_24h: number;
    max_per_day: number | null;
    last_given_label: string | null;
    over_limit: boolean;
    interval_blocked: boolean;
    is_controlled: boolean;
}

/** Choose the person and as-needed medicine (searchable, grouped by person). */
export function AsNeededPicker({ choices, onClose, onPick }: { choices: AsNeededChoice[]; onClose: () => void; onPick: (orderId: number) => void }) {
    const people = [...new Map(choices.map((c) => [c.client_id, c.client_name])).entries()];
    return (
        <SettingsModal
            width={720}
            title="Record an as-needed dose"
            description="Choose the person and medicine. Only people on your shift and their current as-needed orders are listed."
            onClose={onClose}
            footer={
                <Button className="frontline-tap" variant="outline" onClick={onClose}>
                    Close
                </Button>
            }
        >
            <Command className="rounded-lg border">
                <CommandInput placeholder="Search people or medicines…" autoFocus />
                <CommandList className="max-h-[320px]">
                    <CommandEmpty>No as-needed medicine matches. Check the spelling, or open the person’s medication record.</CommandEmpty>
                    {people.map(([clientId, name]) => (
                        <CommandGroup key={clientId} heading={name}>
                            {choices
                                .filter((c) => c.client_id === clientId)
                                .map((c) => {
                                    const blocked = c.over_limit || c.interval_blocked;
                                    return (
                                        <CommandItem key={c.id} value={`${name} ${c.name}`} onSelect={() => onPick(c.id)} className="items-start">
                                            <Pill className="mt-0.5 size-4" aria-hidden="true" />
                                            <span className="min-w-0 flex-1">
                                                <span className="block text-sm font-medium">
                                                    {c.name}
                                                    {c.is_controlled ? ' · controlled' : ''}
                                                </span>
                                                <span className="block text-xs text-muted-foreground">
                                                    {c.given_last_24h} of {c.max_per_day ?? 'no limit'} in the last 24 hours{c.last_given_label ? ` · last ${c.last_given_label}` : ''}
                                                </span>
                                            </span>
                                            <StatusBadge variant={blocked ? 'critical' : 'neutral'} size="sm">
                                                {c.over_limit ? 'Limit reached' : c.interval_blocked ? 'Too soon' : 'Available'}
                                            </StatusBadge>
                                        </CommandItem>
                                    );
                                })}
                        </CommandGroup>
                    ))}
                </CommandList>
            </Command>
            <p className="text-caption">A medicine at its limit still opens, so you can see why it can’t be recorded and what to do.</p>
        </SettingsModal>
    );
}

export function ErrorCreatedDialog({
    medicine,
    person,
    amount,
    reference,
    incidentId,
    onClose,
}: {
    medicine: string;
    person: string;
    amount: string;
    reference: string | null;
    incidentId: number | null;
    onClose: () => void;
}) {
    return (
        <SettingsModal
            width={720}
            title="Recorded and reported"
            description={`${medicine} for ${person}: ${amount} given — more than ordered. The chart shows what was really given.`}
            onClose={onClose}
            footer={
                <>
                    {incidentId ? (
                        <Button asChild variant="outline" className="frontline-tap">
                            <a href={`/incidents/${incidentId}`}>Open the incident</a>
                        </Button>
                    ) : null}
                    <Button className="frontline-tap" onClick={onClose} autoFocus>
                        Done
                    </Button>
                </>
            }
        >
            <div className="grid gap-3 sm:grid-cols-2">
                <Notice tone="critical" title={`Medication error ${reference ?? ''}`.trim()}>
                    Created and linked to this dose · the house lead reviews the severity
                </Notice>
                <Notice tone="warning" title="One linked incident">
                    Created and linked to the error — one incident for this dose
                </Notice>
            </div>
            <Notice tone="critical" title="Do this now">
                <ol className="list-decimal pl-5">
                    <li>
                        Contact the prescriber or on-call contact: <NotConfigured />
                    </li>
                    <li>Stay with {person} and watch closely. Add what you see to the incident.</li>
                    <li>The house lead and everyone rostered at the house have been told; they see it until it’s resolved.</li>
                </ol>
            </Notice>
            <p className="text-caption">Saving again or trying again reuses this error and incident — never a second one.</p>
        </SettingsModal>
    );
}
