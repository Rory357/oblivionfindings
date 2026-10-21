import type { ChatMention } from './types';

// Store code-point offsets so Unicode names and emoji agree with the server.
export function moveChatMentions(
    before: string,
    after: string,
    mentions: ChatMention[],
): ChatMention[] {
    const previous = Array.from(before);
    const next = Array.from(after);
    let prefix = 0;
    while (
        prefix < previous.length &&
        prefix < next.length &&
        previous[prefix] === next[prefix]
    )
        prefix++;
    let suffix = 0;
    while (
        suffix < previous.length - prefix &&
        suffix < next.length - prefix &&
        previous[previous.length - suffix - 1] ===
            next[next.length - suffix - 1]
    )
        suffix++;
    const oldEnd = previous.length - suffix;
    const delta = next.length - previous.length;
    return mentions.flatMap((mention) => {
        const end = mention.start + mention.length;
        const start =
            end <= prefix
                ? mention.start
                : mention.start >= oldEnd
                  ? mention.start + delta
                  : null;
        if (
            start === null ||
            next.slice(start, start + mention.length).join('') !== mention.label
        )
            return [];
        return [{ ...mention, start }];
    });
}

export function chatMentionQuery(value: string, caret: number) {
    const match = /(?:^|\s)@([^\s@]{0,60})$/u.exec(value.slice(0, caret));
    if (!match) return null;
    return { query: match[1], start: caret - match[1].length - 1, end: caret };
}
