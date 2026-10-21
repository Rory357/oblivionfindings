import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { ChatMentionInput, ChatMessageText } from './chat-mention-input';
import type { ChatMention } from './types';

function Harness({
    send,
    changed,
    enterSends = true,
}: {
    send: () => void;
    changed: (value: string, mentions: ChatMention[]) => void;
    enterSends?: boolean;
}) {
    const [value, setValue] = useState('');
    const [mentions, setMentions] = useState<ChatMention[]>([]);
    return (
        <ChatMentionInput
            enterSends={enterSends}
            value={value}
            mentions={mentions}
            people={[
                { id: 2, name: 'Rohan Patel' },
                { id: 3, name: 'Mere Thompson' },
            ]}
            disabled={false}
            onSend={send}
            onChange={(next, items) => {
                setValue(next);
                setMentions(items);
                changed(next, items);
            }}
        />
    );
}

describe('approved mention picker', () => {
    it('keeps Enter as a newline while retaining keyboard mention selection', () => {
        const send = vi.fn();
        const changed = vi.fn();
        render(<Harness send={send} changed={changed} enterSends={false} />);
        const input = screen.getByRole('textbox', { name: 'Message' });
        fireEvent.focus(input);
        fireEvent.change(input, {
            target: { value: '@Ro', selectionStart: 3 },
        });
        fireEvent.keyDown(input, { key: 'Enter' });
        expect(changed).toHaveBeenLastCalledWith(
            ' @Rohan Patel '.trimStart(),
            expect.any(Array),
        );
        expect(fireEvent.keyDown(input, { key: 'Enter' })).toBe(true);
        expect(send).not.toHaveBeenCalled();
    });
    it('inserts the selected person with keyboard navigation without sending', () => {
        const send = vi.fn();
        const changed = vi.fn();
        render(<Harness send={send} changed={changed} />);
        const input = screen.getByRole('textbox', { name: 'Message' });
        fireEvent.focus(input);
        fireEvent.change(input, {
            target: { value: '🙂 @', selectionStart: 4 },
        });
        expect(
            screen.getByRole('listbox', { name: 'Mention eligible members' }),
        ).toBeInTheDocument();
        fireEvent.keyDown(input, { key: 'ArrowDown' });
        fireEvent.keyDown(input, { key: 'Enter' });
        expect(changed).toHaveBeenLastCalledWith('🙂 @Mere Thompson ', [
            { user_id: 3, start: 2, length: 14, label: '@Mere Thompson' },
        ]);
        expect(send).not.toHaveBeenCalled();
        fireEvent.keyDown(input, { key: 'Enter' });
        expect(send).toHaveBeenCalledTimes(1);
    });
    it('closes suggestions with Escape and preserves Shift+Enter and composition', () => {
        const send = vi.fn();
        render(<Harness send={send} changed={vi.fn()} />);
        const input = screen.getByRole('textbox', { name: 'Message' });
        fireEvent.focus(input);
        fireEvent.change(input, {
            target: { value: '@Ro', selectionStart: 3 },
        });
        fireEvent.keyDown(input, { key: 'Escape' });
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
        fireEvent.keyDown(input, { key: 'Enter', shiftKey: true });
        fireEvent.compositionStart(input);
        fireEvent.keyDown(input, { key: 'Enter' });
        expect(send).not.toHaveBeenCalled();
    });
    it('only highlights validated text spans and treats message content as plain text', () => {
        render(
            <ChatMessageText
                content="🙂 @Rohan <script>"
                actorId={2}
                mentions={[
                    { user_id: 2, start: 2, length: 6, label: '@Rohan' },
                    { user_id: 8, start: 9, length: 6, label: '@Other' },
                ]}
            />,
        );
        expect(screen.getByTitle('Mentioned you')).toHaveTextContent('@Rohan');
        expect(document.querySelector('script')).toBeNull();
        expect(screen.queryByText('@Other')).not.toBeInTheDocument();
    });
    it('still allows sending plain text when the mention limit is reached', () => {
        const send = vi.fn();
        render(
            <ChatMentionInput
                value="@Ro"
                mentions={Array.from({ length: 30 }, (_, start) => ({
                    user_id: 2,
                    start,
                    length: 6,
                    label: '@Rohan',
                }))}
                people={[{ id: 2, name: 'Rohan' }]}
                disabled={false}
                onSend={send}
                onChange={vi.fn()}
            />,
        );
        const input = screen.getByRole('textbox', { name: 'Message' });
        fireEvent.focus(input, { target: { selectionStart: 3 } });
        expect(screen.getByRole('option')).toBeDisabled();
        expect(input).not.toHaveAttribute('aria-activedescendant');
        fireEvent.keyDown(input, { key: 'Enter' });
        expect(send).toHaveBeenCalledTimes(1);
    });
});
