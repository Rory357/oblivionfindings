import {
    recognitionFormData,
    RecognitionSubmissionError,
    type RecognitionPayload,
} from '@/components/recognition/recognition-receipt';
import { hubRequest, HubRequestError } from './_api';

export async function shareHubRecognition(
    actorId: number,
    payload: RecognitionPayload,
): Promise<unknown> {
    try {
        return await hubRequest(
            '/hr/feed/kudos',
            'POST',
            recognitionFormData(payload, actorId),
        );
    } catch (cause) {
        if (
            cause instanceof HubRequestError &&
            [401, 403, 409, 419, 422, 429].includes(cause.status)
        )
            throw new RecognitionSubmissionError(cause.message, true);
        throw cause;
    }
}
