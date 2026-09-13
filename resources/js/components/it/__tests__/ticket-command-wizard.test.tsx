import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import axios from 'axios';
import { Ticket } from 'lucide-react';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { Button } from '@/components/ui/button';
import { useItTicketCommand } from '@/hooks/use-it-ticket-command';
import { TicketCommandWizard, ticketFormData } from '../ticket-command-wizard';

function PendingWizard({ onClose }: { onClose: () => void }) {
    const command = useItTicketCommand({ actorId: 230 });
    return (
        <TicketCommandWizard
            command={command}
            dirty
            open
            onClose={onClose}
            title="Synthetic ticket"
            description="Synthetic command verification"
            railIcon={Ticket}
            railTitle="Ticket"
            railSub="IT"
            steps={[
                { key: 'details', label: 'Details', blurb: '', icon: Ticket },
            ]}
            stepIndex={0}
            onStepClick={vi.fn()}
            footerEnd={
                <Button
                    disabled={!command.canEdit}
                    onClick={() =>
                        void command.submit(
                            ticketFormData({
                                title: 'Private synthetic details',
                                attachments: [
                                    new File(['Private file'], 'private.txt'),
                                ],
                            }),
                        )
                    }
                >
                    Submit request
                </Button>
            }
        >
            <p>{command.result?.reference ?? 'Private synthetic details'}</p>
        </TicketCommandWizard>
    );
}

function Harness() {
    const [open, setOpen] = useState(true);
    return open ? (
        <PendingWizard onClose={() => setOpen(false)} />
    ) : (
        <Button onClick={() => setOpen(true)}>Reopen ticket form</Button>
    );
}

describe('Ticket command exit recovery', () => {
    beforeEach(() => {
        sessionStorage.clear();
        vi.spyOn(axios, 'post');
        vi.spyOn(axios, 'get');
    });
    afterEach(() => vi.restoreAllMocks());

    it.each([500, 409, 419])(
        'can go back or explicitly close an uncertain result after HTTP %s and recover on reopening',
        async (status) => {
            vi.mocked(axios.post).mockRejectedValueOnce({
                isAxiosError: true,
                response: { status },
            });
            render(<Harness />);
            fireEvent.click(
                screen.getByRole('button', { name: 'Submit request' }),
            );
            await screen.findByRole('button', { name: 'Check saved request' });
            fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
            const confirm = screen.getByRole('alertdialog');
            expect(
                within(confirm).getByText(/Closing does not cancel it/),
            ).toBeVisible();
            const requestId = (
                within(confirm).getByRole('textbox', {
                    name: 'Pending request reference',
                }) as HTMLInputElement
            ).value;
            await waitFor(() =>
                expect(
                    within(confirm).getByRole('button', { name: 'Go back' }),
                ).toHaveFocus(),
            );
            fireEvent.click(
                within(confirm).getByRole('button', { name: 'Go back' }),
            );
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
            expect(screen.getByRole('dialog')).toBeVisible();
            expect(sessionStorage.length).toBe(1);
            expect(axios.post).toHaveBeenCalledTimes(1);

            fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
            fireEvent.click(
                screen.getByRole('button', {
                    name: 'Close and keep reference',
                }),
            );
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
            expect(
                screen.queryByText('Private synthetic details'),
            ).not.toBeInTheDocument();
            expect(
                sessionStorage.getItem(
                    'it.pending-ticket-command.v1.actor.230',
                ),
            ).toBe(requestId);
            fireEvent.click(
                screen.getByRole('button', { name: 'Reopen ticket form' }),
            );
            expect(
                screen.getByText(/Only its request reference was kept/),
            ).toBeVisible();
            expect(
                screen.queryByRole('button', { name: 'Retry same request' }),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByRole('button', { name: 'Submit request' }),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByText('Private synthetic details'),
            ).not.toBeInTheDocument();
            vi.mocked(axios.get).mockResolvedValueOnce({
                status: 200,
                data: {
                    status: 'committed',
                    data: {
                        id: 9,
                        reference: 'IT-000009',
                        url: '/it/tickets/9',
                        request_uuid: requestId,
                        replayed: true,
                        viewer_user_id: 230,
                    },
                },
            });
            fireEvent.click(
                screen.getByRole('button', { name: 'Check saved request' }),
            );
            expect(await screen.findByText('IT-000009')).toBeVisible();
            expect(sessionStorage.length).toBe(0);
            expect(axios.post).toHaveBeenCalledTimes(1);
        },
    );

    it('stops only waiting when a busy wizard is closed, and keeps the request after going back', async () => {
        vi.mocked(axios.post).mockReturnValueOnce(new Promise(() => {}));
        render(<Harness />);
        fireEvent.click(screen.getByRole('button', { name: 'Submit request' }));
        const signal = vi.mocked(axios.post).mock.calls[0][2]?.signal;
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(signal?.aborted).toBe(true);
        fireEvent.click(screen.getByRole('button', { name: 'Go back' }));
        expect(
            screen.getByText(/Stopped waiting. This does not cancel/),
        ).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Retry same request' }),
        ).toBeEnabled();
        expect(sessionStorage.length).toBe(1);
    });

    it('does not claim reference retention if storage fails and requires a second explicit exit acknowledgement', async () => {
        vi.mocked(axios.post).mockRejectedValueOnce({
            isAxiosError: true,
            response: { status: 500 },
        });
        render(<Harness />);
        fireEvent.click(screen.getByRole('button', { name: 'Submit request' }));
        await screen.findByRole('button', { name: 'Check saved request' });
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('Storage disabled');
        });
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Close and keep reference' }),
        );
        expect(screen.getByRole('alertdialog')).toBeVisible();
        expect(screen.getByText(/This browser could not keep/)).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Go back' }));
        expect(
            screen.getByRole('button', { name: 'Retry same request' }),
        ).toBeEnabled();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Close and keep reference' }),
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Close without retained reference',
            }),
        );
        expect(
            screen.getByRole('button', { name: 'Reopen ticket form' }),
        ).toBeVisible();
        expect(sessionStorage.length).toBe(0);
    });

    it('forgets a resumed reference only after duplicate-risk confirmation; cancellation preserves it', async () => {
        const requestId = crypto.randomUUID();
        sessionStorage.setItem(
            'it.pending-ticket-command.v1.actor.230',
            requestId,
        );
        render(<Harness />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Start a different request' }),
        );
        const confirmation = screen.getByRole('alertdialog');
        expect(
            within(confirmation).getByText(/may create a duplicate/),
        ).toBeVisible();
        fireEvent.click(
            within(confirmation).getByRole('button', { name: 'Cancel' }),
        );
        expect(
            sessionStorage.getItem('it.pending-ticket-command.v1.actor.230'),
        ).toBe(requestId);
        fireEvent.click(
            screen.getByRole('button', { name: 'Start a different request' }),
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Forget reference and start another',
            }),
        );
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Submit request' }),
            ).toBeEnabled(),
        );
        expect(sessionStorage.length).toBe(0);
        expect(axios.post).not.toHaveBeenCalled();
    });
});
