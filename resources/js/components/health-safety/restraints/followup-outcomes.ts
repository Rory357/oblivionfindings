/** A restraint follow-up's recorded outcome in plain words (the server's stored values, RestraintService). */
export const FOLLOWUP_OUTCOME_LABEL: Record<string, string> = {
    contacted: 'Contact made',
    attempted: 'Tried to make contact',
    unable_to_contact: 'Couldn’t make contact',
    declined: 'Declined',
    not_required: 'Not needed',
    recorded: 'Done',
    other: 'Done (other)',
};
