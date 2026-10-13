import { Button } from '@/components/ui/button';
import { InfoCard } from '@/components/wizard/primitives';
import { AlertTriangle, ExternalLink } from 'lucide-react';
import { type NoteOutcome } from './use-note-command';

export function noteRecoveryUrl(
    week: string,
    clientId: number | null,
    authorId?: number | null,
) {
    const query = new URLSearchParams({ week, status: 'all', page: '1' });
    if (clientId) query.set('client_id', String(clientId));
    if (authorId) query.set('author_id', String(authorId));
    return `/operations/shift-notes?${query}`;
}
export function NoteCommandFeedback({
    outcome,
    recoveryUrl,
    noteId,
}: {
    outcome: NoteOutcome | null;
    recoveryUrl: string;
    noteId?: number;
}) {
    if (!outcome || outcome.status === 'confirmed') return null;
    return (
        <div role="alert" className="mb-5">
            <InfoCard
                icon={AlertTriangle}
                tone={outcome.status === 'unknown' ? 'warn' : 'crit'}
            >
                <p>{outcome.message}</p>
                {outcome.status === 'unknown' && (
                    <>
                        <p className="mt-2">
                            {noteId ? `Look for note #${noteId}. ` : ''}Review
                            all pages and clear the author filter if needed. A
                            missing or inaccessible note does not confirm that
                            the save failed.
                        </p>
                        <Button
                            asChild
                            variant="outline"
                            className="mt-3 min-h-11"
                        >
                            <a
                                href={recoveryUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <ExternalLink className="size-4" />
                                Check current notes in a new tab
                            </a>
                        </Button>
                    </>
                )}
            </InfoCard>
        </div>
    );
}
