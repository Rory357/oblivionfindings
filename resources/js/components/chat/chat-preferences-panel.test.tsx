import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { chatRequest } from './api';
import {
    ChatPreferencesProvider,
    defaultChatPreferences,
    type ChatPreferences,
} from './chat-personal-preferences';
import { ChatPreferencesPanel } from './chat-preferences-panel';

const appearance = vi.hoisted(() => vi.fn());
vi.mock('@/hooks/use-appearance', () => ({
    useAppearance: () => ({ updateAppearance: appearance }),
}));
vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
}));
vi.mock('./chat-calling', () => ({ useChatCalling: () => null }));
vi.mock('./api', async (original) => ({
    ...(await original<typeof import('./api')>()),
    chatRequest: vi.fn(),
}));

function choose(label: string, option: string) {
    fireEvent.keyDown(screen.getByLabelText(label), { key: 'Enter' });
    fireEvent.click(screen.getByRole('option', { name: option }));
}

describe('approved personal Chat preferences panel', () => {
    beforeEach(() => {
        appearance.mockReset();
        let values = { ...defaultChatPreferences };
        vi.mocked(chatRequest)
            .mockReset()
            .mockImplementation(async (_, options) => {
                if (options?.method === 'PATCH')
                    values = {
                        ...values,
                        ...JSON.parse(String(options.body)).values,
                    } as ChatPreferences;
                return {
                    actor_id: 1,
                    version: 'a'.repeat(64),
                    values,
                } as never;
            });
    });
    it('updates the reading preview and canonical appearance only after a confirmed save', async () => {
        render(
            <ChatPreferencesProvider actorId={1} enabled>
                <ChatPreferencesPanel actorId={1} />
            </ChatPreferencesProvider>,
        );
        await screen.findByText('Preferences saved');
        choose('Text size', 'Extra large');
        await waitFor(() =>
            expect(
                screen.getByText(
                    'Your changes apply to the shared conversation view.',
                ),
            ).toHaveClass('text-xl'),
        );
        choose('Message spacing', 'Compact');
        await waitFor(() =>
            expect(
                screen.getByText(
                    'Your changes apply to the shared conversation view.',
                ),
            ).toHaveClass('py-2'),
        );
        choose('Sending', 'Enter adds a line');
        await screen.findByText('Enter adds a line · Use Send to send');
        choose('Theme', 'Dark');
        await waitFor(() =>
            expect(appearance).toHaveBeenLastCalledWith('dark', {
                persist: false,
            }),
        );
        expect(
            screen.getByRole('link', { name: 'See in my conversation' }),
        ).toHaveAttribute('href', '/chat');
    });
    it('does not pretend an offline save succeeded', async () => {
        render(
            <ChatPreferencesProvider actorId={1} enabled>
                <ChatPreferencesPanel actorId={1} />
            </ChatPreferencesProvider>,
        );
        await screen.findByText('Preferences saved');
        vi.mocked(chatRequest).mockRejectedValue(new Error('Offline'));
        choose('Text size', 'Large');
        await waitFor(() =>
            expect(screen.getByRole('alert')).toHaveTextContent('Offline'),
        );
        expect(screen.getByLabelText('Text size')).toHaveTextContent('Normal');
        expect(screen.getByLabelText('Text size')).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Review latest preferences' }),
        ).toBeEnabled();
    });
});
