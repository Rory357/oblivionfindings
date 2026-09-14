import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { StatusBadge } from '@/components/ui/status-badge';
import { Tabs } from '@/components/ui/tabs';
import axios from 'axios';
import { Sparkles } from 'lucide-react';
import { useState } from 'react';

type Capability = {
    key: string;
    label: string;
    enabled: boolean;
    reason: string;
};

export type TicketAssistContract = {
    record: { type: string; id: number; reference: string; version: number };
    current: {
        status: string;
        category: string;
        priority: string;
        next_action: string | null;
    };
    audiences: string[];
    sources: { key: string; label: string; count: number }[];
    capabilities: Capability[];
    untrusted_content_note: string;
};

const REASON_COPY: Record<string, string> = {
    assistance_disabled: 'Assistance is not enabled for this organisation.',
    provider_not_integrated:
        'No assistance provider is integrated; execution stays disabled.',
};

function reasonFor(contract: TicketAssistContract): string {
    return (
        REASON_COPY[contract.capabilities[0]?.reason ?? ''] ??
        'Assistance is not available.'
    );
}

function capability(
    contract: TicketAssistContract,
    key: string,
): Capability | undefined {
    return contract.capabilities.find((entry) => entry.key === key);
}

/**
 * W25 surfaces in their disabled state. Every tab shows exactly what a
 * future suggestion would be allowed to see and do, and that nothing can be
 * executed, sent or applied from here. No content leaves the page.
 */
export function TicketAssistPanel({ ticketId }: { ticketId: number }) {
    const [open, setOpen] = useState(false);
    const [contract, setContract] = useState<TicketAssistContract | null>(
        null,
    );
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [audience, setAudience] = useState<'public' | 'internal'>('public');

    const load = async () => {
        setError('');
        setLoading(true);
        try {
            const response = await axios.get(
                `/it/tickets/${ticketId}/assist`,
                { headers: { Accept: 'application/json' } },
            );
            setContract(response.data as TicketAssistContract);
        } catch {
            setError('Assistance information could not be loaded.');
        } finally {
            setLoading(false);
        }
    };

    return (
        <>
            <Button
                variant="ghost"
                size="sm"
                onClick={() => {
                    setOpen(true);
                    void load();
                }}
            >
                <Sparkles className="h-3.5 w-3.5" /> Assist
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-xl">
                    <DialogTitle>Assistance</DialogTitle>
                    <DialogDescription>
                        {contract
                            ? `Draft suggestions for ${contract.record.reference} (version ${contract.record.version}). A suggestion never sends or applies anything itself.`
                            : 'Draft suggestions for this ticket.'}
                    </DialogDescription>
                    {error && (
                        <p role="alert" className="text-sm text-status-critical">
                            {error}
                        </p>
                    )}
                    {loading && !contract && (
                        <p role="status" className="text-sm text-muted-foreground">
                            Checking what assistance may use…
                        </p>
                    )}
                    {contract && (
                        <div className="space-y-3 text-sm">
                            <Tabs
                                tabs={[
                                    {
                                        key: 'summary',
                                        label: 'Summary',
                                        content: (
                                            <SummarySurface contract={contract} />
                                        ),
                                    },
                                    {
                                        key: 'reply',
                                        label: 'Reply draft',
                                        content: (
                                            <ReplyDraftSurface
                                                contract={contract}
                                                audience={audience}
                                                onAudience={setAudience}
                                            />
                                        ),
                                    },
                                    {
                                        key: 'triage',
                                        label: 'Triage',
                                        content: (
                                            <TriageSurface contract={contract} />
                                        ),
                                    },
                                ]}
                            />
                            <p className="rounded-lg bg-muted/40 p-2.5 text-xs text-muted-foreground">
                                {contract.untrusted_content_note}
                            </p>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

function DisabledCapability({
    contract,
    capabilityKey,
}: {
    contract: TicketAssistContract;
    capabilityKey: string;
}) {
    const entry = capability(contract, capabilityKey);
    if (!entry) return null;

    return (
        <div className="flex items-start justify-between gap-3 rounded-lg border border-border p-2.5">
            <span>{entry.label}</span>
            <StatusBadge variant="neutral" label="Not enabled" />
        </div>
    );
}

function SummarySurface({ contract }: { contract: TicketAssistContract }) {
    return (
        <section aria-label="Summary assistance" className="space-y-3 pt-3">
            <DisabledCapability contract={contract} capabilityKey="ticket_summary" />
            <p className="text-muted-foreground">{reasonFor(contract)}</p>
            <div>
                <p className="font-medium">
                    What a suggestion would be allowed to use
                </p>
                <ul className="mt-1 list-disc pl-5 text-muted-foreground">
                    {contract.sources.map((source) => (
                        <li key={source.key}>
                            {source.label} ({source.count})
                        </li>
                    ))}
                </ul>
                <p className="mt-1 text-muted-foreground">
                    Audiences: {contract.audiences.join(', ')}. Credential
                    values are never available to assistance.
                </p>
            </div>
        </section>
    );
}

function ReplyDraftSurface({
    contract,
    audience,
    onAudience,
}: {
    contract: TicketAssistContract;
    audience: 'public' | 'internal';
    onAudience: (audience: 'public' | 'internal') => void;
}) {
    return (
        <section aria-label="Reply draft assistance" className="space-y-3 pt-3">
            <DisabledCapability contract={contract} capabilityKey="reply_draft" />
            <fieldset className="space-y-1.5">
                <legend className="font-medium">Audience first</legend>
                <div className="flex flex-wrap gap-2">
                    {contract.audiences.map((option) => (
                        <Button
                            key={option}
                            type="button"
                            size="sm"
                            variant={audience === option ? 'default' : 'outline'}
                            aria-pressed={audience === option}
                            onClick={() =>
                                onAudience(option as 'public' | 'internal')
                            }
                        >
                            {option === 'internal'
                                ? 'Internal note'
                                : 'Public reply'}
                        </Button>
                    ))}
                </div>
            </fieldset>
            <div className="rounded-lg border border-dashed border-border p-3 text-muted-foreground">
                <p className="font-medium text-foreground">Draft suggestion</p>
                <p className="mt-1">{reasonFor(contract)}</p>
                <div className="mt-3 flex flex-wrap gap-2">
                    <Button size="sm" disabled>
                        Insert into draft
                    </Button>
                    <Button size="sm" variant="outline" disabled>
                        Replace selected text
                    </Button>
                    <Button size="sm" variant="ghost" disabled>
                        Discard
                    </Button>
                </div>
            </div>
            <p className="text-xs text-muted-foreground">
                Your typed text is always preserved. A suggestion never sends
                automatically, and a stale suggestion can never replace a newer
                draft.
            </p>
        </section>
    );
}

function TriageSurface({ contract }: { contract: TicketAssistContract }) {
    const rows: { label: string; value: string | null }[] = [
        { label: 'Category', value: contract.current.category },
        { label: 'Priority', value: contract.current.priority },
        { label: 'Status', value: contract.current.status },
        { label: 'Next action', value: contract.current.next_action },
    ];

    return (
        <section aria-label="Triage assistance" className="space-y-3 pt-3">
            <DisabledCapability
                contract={contract}
                capabilityKey="triage_suggestion"
            />
            <table className="w-full text-sm">
                <thead className="text-left text-xs text-muted-foreground uppercase">
                    <tr>
                        <th className="py-1 pr-3">Field</th>
                        <th className="py-1 pr-3">Now</th>
                        <th className="py-1">Suggested</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.label} className="border-t border-border">
                            <td className="py-1.5 pr-3 font-medium">
                                {row.label}
                            </td>
                            <td className="py-1.5 pr-3">
                                {row.value ?? '—'}
                            </td>
                            <td className="py-1.5 text-muted-foreground">
                                No suggestion
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
            <p className="text-muted-foreground">
                {reasonFor(contract)} A future suggestion would cite the
                permitted guides (
                {contract.sources.find(
                    (source) => source.key === 'published_guides',
                )?.count ?? 0}{' '}
                published) and explain any missing context; applying it would
                use the normal versioned ticket update.
            </p>
        </section>
    );
}
