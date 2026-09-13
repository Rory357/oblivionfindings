import { Card } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';

export type HandoverPersonNote = {
    client_id: number;
    name?: string;
    notes: string;
    no_updates: boolean;
    not_supported?: boolean;
    follow_up_needed: boolean;
};

export type HandoverWorkerNotes = {
    people: HandoverPersonNote[];
    shared_notes: string;
};

export default function HandoverPersonNotes({
    notes,
}: {
    notes?: HandoverWorkerNotes | null;
}) {
    if (!notes) return null;
    return (
        <div className="space-y-3">
            {notes.people.map((person) => (
                <Card key={person.client_id} className="gap-2 p-4">
                    <div className="flex items-center justify-between gap-3">
                        <h3 className="text-sm font-semibold">
                            {person.name ?? 'Person supported'}
                        </h3>
                        {person.follow_up_needed && (
                            <StatusBadge variant="warning">
                                Needs follow-up
                            </StatusBadge>
                        )}
                    </div>
                    <p className="text-sm leading-relaxed whitespace-pre-wrap">
                        {person.not_supported
                            ? 'Did not support this person this shift.'
                            : person.notes ||
                              (person.no_updates
                                  ? 'No updates to pass on.'
                                  : 'Follow-up requested. Check with the outgoing worker.')}
                    </p>
                </Card>
            ))}
            {notes.shared_notes && (
                <Card className="gap-2 p-4">
                    <h3 className="text-sm font-semibold">Whole site</h3>
                    <p className="text-sm leading-relaxed whitespace-pre-wrap">
                        {notes.shared_notes}
                    </p>
                </Card>
            )}
        </div>
    );
}
