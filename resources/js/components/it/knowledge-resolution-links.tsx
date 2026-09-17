import { Button } from '@/components/ui/button';
import type { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useState } from 'react';

type SourceDocuments = {
    actor_user_id: number;
    ticket_id: number;
    can_draft: boolean;
    records: Array<{
        id: number;
        title: string;
        status: string;
        href: string;
        published_revision: boolean;
    }>;
};

export function KnowledgeResolutionLinks({ ticketId }: { ticketId: number }) {
    const actorId = usePage<SharedData>().props.auth.user.id;
    const [payload, setPayload] = useState<SourceDocuments | null>(null);
    const [failed, setFailed] = useState(false);
    const [generation, setGeneration] = useState(0);
    useEffect(() => {
        const request = new AbortController();
        setPayload(null);
        setFailed(false);
        axios
            .get<SourceDocuments>(
                `/it/knowledge/resolution-documents/${ticketId}`,
                { signal: request.signal },
            )
            .then(({ data }) => {
                if (request.signal.aborted) return;
                if (
                    data.actor_user_id !== actorId ||
                    data.ticket_id !== ticketId ||
                    !Array.isArray(data.records)
                )
                    throw new Error('Knowledge context changed');
                setPayload(data);
            })
            .catch(() => {
                if (!request.signal.aborted) setFailed(true);
            });
        return () => request.abort();
    }, [actorId, ticketId, generation]);
    const current =
        payload?.actor_user_id === actorId && payload.ticket_id === ticketId
            ? payload
            : null;
    if (failed)
        return (
            <div className="mt-4 space-y-2 border-t border-border pt-4">
                <p className="text-subtle">
                    Related knowledge could not be loaded.
                </p>
                <Button
                    variant="outline"
                    onClick={() => setGeneration((value) => value + 1)}
                >
                    Retry related knowledge
                </Button>
            </div>
        );
    if (!current || (!current.can_draft && !current.records.length))
        return null;
    return (
        <section
            aria-label="Knowledge from this resolution"
            className="mt-4 space-y-3 border-t border-border pt-4"
        >
            <h3 className="text-sm font-semibold">
                Knowledge from this resolution
            </h3>
            {current.records.map((record) => (
                <Link
                    key={record.id}
                    href={record.href}
                    className="frontline-focus flex min-h-11 items-center rounded-md text-sm text-primary"
                >
                    {record.title} ·{' '}
                    {record.published_revision
                        ? 'Referenced publication'
                        : record.status.replaceAll('_', ' ')}
                </Link>
            ))}
            {current.can_draft && (
                <Button asChild variant="outline">
                    <Link href={`/it/knowledge/from-resolution/${ticketId}`}>
                        Create guide from resolution
                    </Link>
                </Button>
            )}
        </section>
    );
}
