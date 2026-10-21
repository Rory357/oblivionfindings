import { describe, expect, it } from 'vitest';
import { chatMentionQuery, moveChatMentions } from './mentions';

describe('mention identity during plain-text editing', () => {
    const mention = { user_id: 2, start: 2, length: 6, label: '@Rohan' };
    it('counts emoji as Unicode code points when moving a mention', () => {
        expect(
            moveChatMentions('🙂 @Rohan check', '🙂 👍 @Rohan check', [
                mention,
            ]),
        ).toEqual([{ ...mention, start: 4 }]);
        expect(
            moveChatMentions('🙂 @Rohan check', '@Rohan check', [mention]),
        ).toEqual([{ ...mention, start: 0 }]);
    });
    it('drops the identity if a name is edited, without turning other text into a mention', () => {
        expect(
            moveChatMentions('🙂 @Rohan check', '🙂 @Roh check', [mention]),
        ).toEqual([]);
        expect(moveChatMentions('hello', '@Rohan hello', [])).toEqual([]);
    });
    it('keeps explicit identity when equally named people are in the same conversation', () => {
        expect(
            moveChatMentions('🙂 @Rohan check', '🙂 @Rohan check!', [
                { ...mention, user_id: 9 },
            ]),
        ).toEqual([{ ...mention, user_id: 9 }]);
    });
    it('finds a query at the caret but does not treat email addresses as mention requests', () => {
        expect(chatMentionQuery('Ask @Ro today', 7)).toEqual({
            query: 'Ro',
            start: 4,
            end: 7,
        });
        expect(chatMentionQuery('user@example', 12)).toBeNull();
        expect(chatMentionQuery('@Rohan ', 7)).toBeNull();
    });
});
