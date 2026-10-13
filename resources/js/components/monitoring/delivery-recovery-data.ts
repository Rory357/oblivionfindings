export type RecoveryAttempt = {
    uuid: string;
    actor: number;
    site: number | null;
    letter: number;
    operation: 'replay' | 'discard';
};
export type RecoveryAcknowledgement = {
    decision: {
        dead_letter_id: number;
        site_id: number | null;
        consumer: string;
        message_reference: string;
        reason_code: string;
        confirms: 'replay_request_recorded' | 'discard_recorded';
        confirms_processing: false;
        confirms_device_command: false;
    };
    receipt: {
        schema_version: number;
        owner: string;
        request_uuid: string;
        actor_id: number;
        operation: 'replay' | 'discard';
        dead_letter_id: number;
        site_id: number | null;
        committed_at: string;
    };
    current: {
        state:
            | 'replay_requested'
            | 'replay_processed'
            | 'discarded'
            | 'awaiting_decision';
        replay_count: number;
        resolved_at: string | null;
    };
};
export function confirmedRecovery(
    value: unknown,
    attempt: RecoveryAttempt,
): RecoveryAcknowledgement {
    const reply = value as RecoveryAcknowledgement,
        r = reply?.receipt,
        d = reply?.decision,
        c = reply?.current;
    if (
        !r ||
        !d ||
        !c ||
        r.schema_version !== 1 ||
        r.owner !== 'Monitoring delivery recovery' ||
        r.request_uuid !== attempt.uuid ||
        r.actor_id !== attempt.actor ||
        r.operation !== attempt.operation ||
        r.dead_letter_id !== attempt.letter ||
        r.site_id !== attempt.site ||
        d.dead_letter_id !== attempt.letter ||
        d.site_id !== attempt.site ||
        d.confirms !==
            (attempt.operation === 'replay'
                ? 'replay_request_recorded'
                : 'discard_recorded') ||
        d.confirms_processing !== false ||
        d.confirms_device_command !== false ||
        typeof d.consumer !== 'string' ||
        typeof d.message_reference !== 'string' ||
        typeof d.reason_code !== 'string' ||
        typeof r.committed_at !== 'string' ||
        !/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]+)?(?:Z|[+-][0-9]{2}:[0-9]{2})$/.test(
            r.committed_at,
        ) ||
        !Number.isFinite(Date.parse(r.committed_at)) ||
        ![
            'replay_requested',
            'replay_processed',
            'discarded',
            'awaiting_decision',
        ].includes(c.state) ||
        !Number.isSafeInteger(c.replay_count) ||
        c.replay_count < 0 ||
        (c.resolved_at !== null &&
            (typeof c.resolved_at !== 'string' ||
                !Number.isFinite(Date.parse(c.resolved_at))))
    )
        throw Error('The saved recovery decision does not match this request.');
    return reply;
}
