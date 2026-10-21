import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatError, chatRequest } from './api';
import {
    ChatUrgentHelp,
    type UrgentContactSetting,
} from './chat-urgent-contact';
import { ChatUrgentContactSettings } from './chat-urgent-contact-settings';

vi.mock('./api', async (original) => ({
    ...(await original<typeof import('./api')>()),
    chatRequest: vi.fn(),
}));
const contact = {
    label: 'Service on-call team',
    phone: '0800 555 0100',
    instructions: 'Call for urgent service support.',
    phone_uri: 'tel:08005550100',
};
const result = { actor_id: 1, client_id: 8, contact };

describe('Shared urgent service contact', () => {
    beforeEach(() => {
        vi.mocked(chatRequest).mockReset();
    });

    it('checks the current actor and client before offering the published phone number and returns focus on close', async () => {
        vi.mocked(chatRequest).mockResolvedValue(result as never);
        render(<ChatUrgentHelp actorId={1} clientId={8} />);
        expect(chatRequest).not.toHaveBeenCalled();
        const trigger = screen.getByRole('button', {
            name: 'Need urgent help?',
        });
        fireEvent.click(trigger);
        expect(
            await screen.findByRole('link', { name: '0800 555 0100' }),
        ).toHaveAttribute('href', 'tel:08005550100');
        expect(screen.getByText(/For immediate danger/)).toBeVisible();
        expect(chatRequest).toHaveBeenCalledWith(
            '/chat/api/clients/8/urgent-contact',
            expect.objectContaining({ headers: { 'X-Chat-Actor': '1' } }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Close' }));
        await waitFor(() => expect(trigger).toHaveFocus());
    });

    it('distinguishes an unconfigured contact from a failed check and retries explicitly', async () => {
        vi.mocked(chatRequest)
            .mockRejectedValueOnce(new ChatError('Offline', 503))
            .mockResolvedValueOnce({ ...result, contact: null } as never);
        render(<ChatUrgentHelp actorId={1} clientId={8} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Need urgent help?' }),
        );
        await screen.findByRole('alert');
        expect(
            screen.queryByText('Urgent contact not configured'),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
        expect(
            await screen.findByRole('button', {
                name: 'Urgent contact not configured',
            }),
        ).toBeDisabled();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('removes a previously shown number when access is revoked', async () => {
        let revoked = false;
        vi.mocked(chatRequest).mockImplementation(async () => {
            if (revoked) throw new ChatError('Access changed', 403);
            return result as never;
        });
        render(<ChatUrgentHelp actorId={1} clientId={8} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Need urgent help?' }),
        );
        await screen.findByRole('link');
        revoked = true;
        act(() => window.dispatchEvent(new Event('oblivion:chat-refresh')));
        expect(
            await screen.findByText(
                'This service contact is no longer available to your account.',
            ),
        ).toBeVisible();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
        expect(
            screen.queryByText('Service on-call team'),
        ).not.toBeInTheDocument();
    });

    it.each([
        ['different client', { ...result, client_id: 9 }],
        ['different actor', { ...result, actor_id: 2 }],
        [
            'unsafe link',
            {
                ...result,
                contact: { ...contact, phone_uri: 'https://example.test' },
            },
        ],
        [
            'different number',
            {
                ...result,
                contact: { ...contact, phone_uri: 'tel:08005550999' },
            },
        ],
    ])('withholds a response for %s', async (_, response) => {
        vi.mocked(chatRequest).mockResolvedValue(response as never);
        render(<ChatUrgentHelp actorId={1} clientId={8} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Need urgent help?' }),
        );
        await screen.findByRole('alert');
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('ignores an earlier client response after the selected family client changes', async () => {
        let resolve!: (value: unknown) => void;
        vi.mocked(chatRequest)
            .mockImplementationOnce(
                () =>
                    new Promise((done) => {
                        resolve = done;
                    }) as never,
            )
            .mockResolvedValueOnce({
                ...result,
                client_id: 9,
                contact: null,
            } as never);
        const view = render(<ChatUrgentHelp actorId={1} clientId={8} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Need urgent help?' }),
        );
        await screen.findByText('Checking service contact…');
        view.rerender(<ChatUrgentHelp actorId={1} clientId={9} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Need urgent help?' }),
        );
        await screen.findByRole('button', {
            name: 'Urgent contact not configured',
        });
        await act(async () => resolve(result));
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', {
                name: 'Urgent contact not configured',
            }),
        ).toBeVisible();
    });

    it('requires renewed confirmation after editing the published contact and can remove it', async () => {
        function Editor() {
            const [value, setValue] = useState<UrgentContactSetting | null>({
                label: contact.label,
                phone: contact.phone,
                instructions: contact.instructions,
                confirmed: true,
            });
            return (
                <ChatUrgentContactSettings
                    value={value}
                    source="Organisation policy"
                    disabled={false}
                    onChange={setValue}
                />
            );
        }
        render(<Editor />);
        const confirmation = screen.getByRole('checkbox', {
            name: /I have checked/,
        });
        expect(confirmation).toBeChecked();
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Service phone number' }),
            { target: { value: '0800 555 0200' } },
        );
        expect(confirmation).not.toBeChecked();
        fireEvent.click(confirmation);
        expect(confirmation).toBeChecked();
        fireEvent.click(
            screen.getByRole('checkbox', {
                name: 'Configure a family-facing urgent contact',
            }),
        );
        expect(
            screen.queryByRole('textbox', { name: 'Service phone number' }),
        ).not.toBeInTheDocument();
    });
});
