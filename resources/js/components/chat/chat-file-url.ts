import { ChatError } from './api';

/** The expected account is not a credential; the server still checks current file access. */
export function chatFileUrl(actorId: number, path: string): string {
    if (
        !Number.isSafeInteger(actorId) ||
        actorId <= 0 ||
        !/^\/chat\/api\/(?:attachments\/[1-9][0-9]*(?:\/preview)?|conversations\/[1-9][0-9]*\/uploads\/[0-9a-f-]{36}\/(?:preview|download))$/.test(
            path,
        )
    )
        throw new ChatError(
            'This attachment could not be verified. Refresh Chat and try again.',
            403,
        );
    return `${path}?_chat_actor=${actorId}`;
}
