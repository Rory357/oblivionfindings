export type RecognitionPayload = {
    to_user_ids: string[];
    to_department_id: number | null;
    category: string;
    impact: string;
    message: string;
    mention_ids?: number[];
    images?: File[];
    client_request_id?: string;
};

/** Files use the same canonical receipt command without becoming JSON objects. */
export function recognitionFormData(
    payload: RecognitionPayload,
    actorId: number,
): FormData {
    const data = new FormData();
    data.set('hub_receipt', '1');
    data.set('actor_id', String(actorId));
    payload.to_user_ids.forEach((id) => data.append('to_user_ids[]', id));
    if (payload.to_department_id)
        data.set('to_department_id', String(payload.to_department_id));
    data.set('category', payload.category);
    data.set('impact', payload.impact);
    data.set('message', payload.message);
    if (payload.client_request_id)
        data.set('client_request_id', payload.client_request_id);
    payload.mention_ids?.forEach((id) =>
        data.append('mention_ids[]', String(id)),
    );
    payload.images?.forEach((file) => data.append('images[]', file));
    return data;
}

export type RecognitionReceipt = {
    saved: true;
    actor_id: number;
    records: {
        kudos_id: number;
        feed_post_id: number;
        recipient_type: 'person' | 'team';
        to_user_id: number | null;
        to_department_id: number | null;
        reader_url: string | null;
    }[];
};

export class RecognitionSubmissionError extends Error {
    constructor(
        message: string,
        readonly rejected: boolean,
    ) {
        super(message);
        this.name = 'RecognitionSubmissionError';
    }
}

const positiveId = (value: unknown): value is number =>
    typeof value === 'number' && Number.isSafeInteger(value) && value > 0;

/** Success must identify every actual recipient, with no invented reading URL. */
export function recognitionReceipt(
    value: unknown,
    actorId: number,
    payload: RecognitionPayload,
): RecognitionReceipt {
    const fail = () => {
        throw new RecognitionSubmissionError(
            'We could not confirm whether your shout-out was shared. Check the board before sharing it again.',
            false,
        );
    };
    if (!value || typeof value !== 'object') return fail();
    const result = value as Partial<RecognitionReceipt>;
    const expected = payload.to_department_id
        ? [payload.to_department_id]
        : [...new Set(payload.to_user_ids.map(Number))];
    if (
        result.saved !== true ||
        result.actor_id !== actorId ||
        !positiveId(actorId) ||
        !Array.isArray(result.records) ||
        result.records.length !== expected.length ||
        !expected.length
    )
        return fail();
    const kudos = new Set<number>(),
        posts = new Set<number>(),
        recipients = new Set<number>();
    for (const item of result.records) {
        if (
            !item ||
            !positiveId(item.kudos_id) ||
            !positiveId(item.feed_post_id) ||
            kudos.has(item.kudos_id) ||
            posts.has(item.feed_post_id)
        )
            return fail();
        const team = !!payload.to_department_id;
        const recipient = team ? item.to_department_id : item.to_user_id;
        if (
            item.recipient_type !== (team ? 'team' : 'person') ||
            (team ? item.to_user_id : item.to_department_id) !== null ||
            !positiveId(recipient) ||
            !expected.includes(recipient) ||
            recipients.has(recipient)
        )
            return fail();
        if (
            item.reader_url !== null &&
            item.reader_url !==
                `/company-hub?view=shoutouts&recognition=${item.kudos_id}#kudos-${item.kudos_id}`
        )
            return fail();
        kudos.add(item.kudos_id);
        posts.add(item.feed_post_id);
        recipients.add(recipient);
    }
    return result as RecognitionReceipt;
}
