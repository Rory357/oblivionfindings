import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { StatusBadge } from '@/components/ui/status-badge';
import axios from 'axios';
import { useEffect, useState } from 'react';

export type KnowledgeAssistContract = {
    record: {
        type: string;
        id: number;
        reference: string;
        version: number;
        published_revision: number | null;
        audience: string;
    };
    sources: { key: string; label: string; count: number }[];
    capabilities: {
        key: string;
        label: string;
        enabled: boolean;
        reason: string;
    }[];
    publication_note: string;
    untrusted_content_note: string;
};

const REASON_COPY: Record<string, string> = {
    assistance_disabled: 'Assistance is not enabled for this organisation.',
    provider_not_integrated:
        'No assistance provider is integrated; execution stays disabled.',
};

/**
 * W25 documentation-assistance surface for authors, in its disabled state.
 * Draft-from-resolution, summarisation and completeness review are shown as
 * layouts only; publishing keeps the human author/reviewer actions.
 */
export function KnowledgeAssistPanel({
    articleId,
    open,
    onClose,
}: {
    articleId: number;
    open: boolean;
    onClose: () => void;
}) {
    const [contract, setContract] = useState<KnowledgeAssistContract | null>(
        null,
    );
    const [error, setError] = useState('');

    useEffect(() => {
        if (!open) return;
        let mounted = true;
        setError('');
        axios
            .get(`/it/knowledge/${articleId}/assist`, {
                headers: { Accept: 'application/json' },
            })
            .then((response) => {
                if (mounted) {
                    setContract(response.data as KnowledgeAssistContract);
                }
            })
            .catch(() => {
                if (mounted) {
                    setError('Assistance information could not be loaded.');
                }
            });

        return () => {
            mounted = false;
        };
    }, [articleId, open]);

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) onClose();
            }}
        >
            <DialogContent className="sm:max-w-lg">
                <DialogTitle>Documentation assistance</DialogTitle>
                <DialogDescription>
                    {contract
                        ? `${contract.record.reference} · draft version ${contract.record.version}${contract.record.published_revision ? ` · published revision ${contract.record.published_revision}` : ' · not yet published'}.`
                        : 'Draft suggestions for this document.'}
                </DialogDescription>
                {error && (
                    <p role="alert" className="text-sm text-status-critical">
                        {error}
                    </p>
                )}
                {contract && (
                    <div className="space-y-3 text-sm">
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
                        <p className="text-muted-foreground">
                            {REASON_COPY[
                                contract.capabilities[0]?.reason ?? ''
                            ] ?? 'Assistance is not available.'}
                        </p>
                        <div>
                            <p className="font-medium">
                                What a suggestion would be allowed to cite
                            </p>
                            <ul className="mt-1 list-disc pl-5 text-muted-foreground">
                                {contract.sources.map((source) => (
                                    <li key={source.key}>
                                        {source.label} ({source.count})
                                    </li>
                                ))}
                            </ul>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            {contract.publication_note} Credentials have no
                            assistance action.
                        </p>
                        <p className="rounded-lg bg-muted/40 p-2.5 text-xs text-muted-foreground">
                            {contract.untrusted_content_note}
                        </p>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
