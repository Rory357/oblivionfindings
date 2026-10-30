export interface SecondPersonEvidence {
    second_person_kind?: string | null;
    second_person_status?: string | null;
    witness_method?: string | null;
    witness?: string | null;
    second_person_confirmation?: {
        id: number;
        status: 'pending' | 'confirmed' | 'disputed' | 'expired';
        nominated_name: string | null;
        due_at: string;
    } | null;
}

/** Only server-recorded evidence can describe a second person as verified. */
export function secondPersonDisplay(evidence: SecondPersonEvidence) {
    const confirmation = evidence.second_person_confirmation;
    const status = confirmation?.status ?? evidence.second_person_status;
    const name = confirmation?.nominated_name ?? evidence.witness;
    if (status === 'disputed')
        return {
            tone: 'critical' as const,
            label: 'Confirmation disputed',
            detail: 'The colleague disagreed. The house lead must review this dose.',
        };
    if (status === 'expired')
        return {
            tone: 'warning' as const,
            label: 'Confirmation overdue',
            detail: 'No confirmation was received in time. The house lead must review this dose.',
        };
    if (
        status === 'pending' ||
        status === 'not_verified' ||
        (evidence.witness_method === 'pin_forgotten' &&
            status !== 'confirmed' &&
            status !== 'verified')
    )
        return {
            tone: 'warning' as const,
            label: 'Second-person confirmation pending',
            detail: name
                ? 'Awaiting ' + name + '’s own sign-in. Not yet verified.'
                : 'Awaiting the nominated colleague. Not yet verified.',
        };
    if (status === 'not_confirmed')
        return {
            tone: 'warning' as const,
            label: 'Not confirmed by a second person',
            detail: 'Follow-up for the house lead.',
        };
    if (
        status === 'confirmed' ||
        evidence.witness_method === 'own_session_confirmation'
    )
        return {
            tone: 'success' as const,
            label: 'Confirmed in their own account',
            detail: name ?? 'Confirmation recorded.',
        };
    if (evidence.witness)
        return {
            tone: 'success' as const,
            label:
                evidence.witness_method === 'historical_paper_own_pin'
                    ? 'Historical witness verified'
                    : evidence.witness_method === 'witness_pin'
                      ? 'Witness PIN verified'
                      : 'Second person recorded',
            detail: evidence.witness,
        };
    return null;
}
