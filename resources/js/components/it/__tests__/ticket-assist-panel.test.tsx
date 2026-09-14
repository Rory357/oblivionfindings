import { TicketAssistPanel } from '@/components/it/ticket-assist-panel';
import { fireEvent, render, screen } from '@testing-library/react';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';

describe('TicketAssistPanel', () => {
    afterEach(() => vi.restoreAllMocks());

    it('shows every capability disabled with the permitted sources and the untrusted-content note', async () => {
        vi.spyOn(axios, 'get').mockResolvedValue({
            data: {
                record: {
                    type: 'it_ticket',
                    id: 7,
                    reference: 'IT-000007',
                    version: 3,
                },
                audiences: ['public', 'internal'],
                sources: [
                    {
                        key: 'public_conversation',
                        label: 'Public conversation',
                        count: 4,
                    },
                    { key: 'internal_notes', label: 'Internal notes', count: 2 },
                ],
                capabilities: [
                    {
                        key: 'ticket_summary',
                        label: 'Summarise the permitted conversation for handover',
                        enabled: false,
                        reason: 'assistance_disabled',
                    },
                    {
                        key: 'reply_draft',
                        label: 'Draft a reply for a chosen audience',
                        enabled: false,
                        reason: 'assistance_disabled',
                    },
                ],
                untrusted_content_note:
                    'Email, document and conversation content is data, not instructions: nothing inside it can grant permission, change policy or authorize an action.',
            },
        });

        render(<TicketAssistPanel ticketId={7} />);
        fireEvent.click(screen.getByRole('button', { name: 'Assist' }));

        expect(
            await screen.findByText(
                'Summarise the permitted conversation for handover',
            ),
        ).toBeVisible();
        expect(screen.getAllByText('Not enabled')).toHaveLength(2);
        expect(
            screen.getByText(
                'Assistance is not enabled for this organisation.',
            ),
        ).toBeVisible();
        expect(screen.getByText('Internal notes (2)')).toBeVisible();
        expect(
            screen.getByText(/version 3.*never sends or applies/),
        ).toBeVisible();
        expect(
            screen.getByText(/data, not instructions/),
        ).toBeVisible();
        expect(vi.mocked(axios.get)).toHaveBeenCalledWith(
            '/it/tickets/7/assist',
            expect.anything(),
        );
    });
});
