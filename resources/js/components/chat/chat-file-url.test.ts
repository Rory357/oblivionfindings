import { describe, expect, it } from 'vitest';
import { chatFileUrl } from './chat-file-url';

describe('account-bound Chat file links', () => {
    it('binds previews and downloads to the rendered account', () => {
        const paths = [
            '/chat/api/attachments/9',
            '/chat/api/attachments/9/preview',
            ...['preview', 'download'].map(
                (kind) =>
                    `/chat/api/conversations/71/uploads/9071f3af-d342-4d6e-bd78-ae236a1ae19e/${kind}`,
            ),
        ];
        for (const path of paths)
            expect(chatFileUrl(11, path)).toBe(`${path}?_chat_actor=11`);
    });
    it('rejects missing identity, external links and routes that are not Chat files', () => {
        for (const actor of [0, -1, NaN, 1.5, Infinity])
            expect(() =>
                chatFileUrl(actor, '/chat/api/attachments/9'),
            ).toThrow();
        for (const path of [
            'https://example.com/chat/api/attachments/9',
            '//example.com/chat/api/attachments/9',
            '/chat/api/conversations',
            '/chat/api/attachments/9?_chat_actor=2',
            '/chat/api/attachments/9/../10',
            '/chat/api/attachments/9#other',
        ])
            expect(() => chatFileUrl(11, path)).toThrow();
    });
});
