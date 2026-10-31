import { EntityStatusChip } from '@/components/lists/entity-cells';
import { MapPin } from 'lucide-react';

export function ParticipationOwnershipChip({
    state,
}: {
    state?: 'recorded' | 'unconfirmed';
}) {
    return state === 'unconfirmed' ? (
        <EntityStatusChip variant="warning" icon={MapPin}>
            Site ownership not confirmed
        </EntityStatusChip>
    ) : null;
}

export function ParticipationOwnershipNotice({
    state,
}: {
    state?: 'recorded' | 'unconfirmed';
}) {
    if (state !== 'unconfirmed') return null;
    return (
        <div className="mb-5 space-y-2" role="note">
            <ParticipationOwnershipChip state={state} />
            <p className="text-caption">
                No Site is recorded. This may be an organisation-wide record or
                one whose earlier Site was removed. Its Site ownership needs
                review.
            </p>
        </div>
    );
}
