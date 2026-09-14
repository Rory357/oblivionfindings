import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { StatusBadge } from '@/components/ui/status-badge';
import axios from 'axios';
import { Sparkles } from 'lucide-react';
import { useState } from 'react';

type AssistContract = {
    record: { type: string; id: number; reference: string; version: number };
    audiences: string[];
    sources: { key: string; label: string; count: number }[];
    capabilities: {
        key: string;
        label: string;
        enabled: boolean;
        reason: string;
    }[];
    untrusted_content_note: string;
};

const REASON_COPY: Record<string, string> = {
    assistance_disabled: 'Assistance is not enabled for this organisation.',
    provider_not_integrated:
        'No assistance provider is integrated; execution stays disabled.',
};

/**
 * W25 surface: the Assist panel in its disabled state. It shows exactly
 * what a future suggestion would be allowed to see — the record and
 * version, the permitted audiences and source descriptors — and that
 * every capability is disabled, with the reason. No content leaves the
 * page and nothing can be executed from here.
 */
export function TicketAssistPanel({ ticketId }: { ticketId: number }) {
    const [open, setOpen] = useState(false);
    const [contract, setContract] = useState<AssistContract | null>(null);
    const [error, setError] = useState('');

    const load = async () => {
        setError('');
        try {
            const response = await axios.get(
                `/it/tickets/${ticketId}/assist`,
                { headers: { Accept: 'application/json' } },
            );
            setContract(response.data as AssistContract);
        } catch {
            setError('Assistance information could not be loaded.');
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
                <DialogContent className="sm:max-w-lg">
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
                    {contract && (
                        <div className="space-y-4 text-sm">
                            <section aria-label="Assistance capabilities">
                                <ul className="space-y-2">
                                    {contract.capabilities.map((capability) => (
                                        <li
                                            key={capability.key}
                                            className="flex items-start justify-between gap-3 rounded-lg border border-border p-2.5"
                                        >
                                            <span>{capability.label}</span>
                                            <StatusBadge
                                                variant="neutral"
                                                label="Not enabled"
                                            />
                                        </li>
                                    ))}
                                </ul>
                                <p className="mt-2 text-muted-foreground">
                                    {REASON_COPY[
                                        contract.capabilities[0]?.reason ?? ''
                                    ] ?? 'Assistance is not available.'}
                                </p>
                            </section>
                            <section aria-label="What assistance may use">
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
                                    Audiences:{' '}
                                    {contract.audiences.join(', ')}. Credential
                                    values are never available to assistance.
                                </p>
                            </section>
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
