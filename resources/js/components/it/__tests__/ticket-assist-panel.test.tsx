import {
    articleAssistContract,
    participantAssistContract,
    ticketAssistContract,
} from '@/components/it/__fixtures__/assist-contracts';
import { KnowledgeAssistPanel } from '@/components/it/knowledge-assist-panel';
import { TicketAssistPanel } from '@/components/it/ticket-assist-panel';
import { fireEvent, render, screen } from '@testing-library/react';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';

describe('TicketAssistPanel', () => {
    afterEach(() => vi.restoreAllMocks());

    it('shows the summary surface disabled with permitted sources and the untrusted-content note', async () => {
        vi.spyOn(axios, 'get').mockResolvedValue({ data: ticketAssistContract });

        render(<TicketAssistPanel ticketId={7} />);
        fireEvent.click(screen.getByRole('button', { name: 'Assist' }));

        expect(
            await screen.findByText(
                'Summarise the permitted conversation for handover',
            ),
        ).toBeVisible();
        expect(screen.getByText('Not enabled')).toBeVisible();
        expect(
            screen.getByText('Assistance is not enabled for this organisation.'),
        ).toBeVisible();
        expect(screen.getByText('Internal notes (2)')).toBeVisible();
        expect(
            screen.getByText(/version 3.*never sends or applies/),
        ).toBeVisible();
        expect(screen.getByText(/data, not instructions/)).toBeVisible();
        expect(vi.mocked(axios.get)).toHaveBeenCalledWith(
            '/it/tickets/7/assist',
            expect.anything(),
        );
    });

    it('offers the reply-draft surface audience-first with every result action disabled', async () => {
        vi.spyOn(axios, 'get').mockResolvedValue({ data: ticketAssistContract });

        render(<TicketAssistPanel ticketId={7} />);
        fireEvent.click(screen.getByRole('button', { name: 'Assist' }));
        fireEvent.click(await screen.findByRole('tab', { name: 'Reply draft' }));

        expect(
            screen.getByRole('button', { name: 'Public reply' }),
        ).toHaveAttribute('aria-pressed', 'true');
        fireEvent.click(screen.getByRole('button', { name: 'Internal note' }));
        expect(
            screen.getByRole('button', { name: 'Internal note' }),
        ).toHaveAttribute('aria-pressed', 'true');
        for (const label of [
            'Insert into draft',
            'Replace selected text',
            'Discard',
        ]) {
            expect(screen.getByRole('button', { name: label })).toBeDisabled();
        }
        expect(
            screen.getByText(/never sends automatically/),
        ).toBeVisible();
    });

    it('limits a participant-scope viewer to the public audience', async () => {
        vi.spyOn(axios, 'get').mockResolvedValue({
            data: participantAssistContract,
        });

        render(<TicketAssistPanel ticketId={7} />);
        fireEvent.click(screen.getByRole('button', { name: 'Assist' }));
        fireEvent.click(await screen.findByRole('tab', { name: 'Reply draft' }));

        expect(
            screen.getByRole('button', { name: 'Public reply' }),
        ).toBeVisible();
        expect(
            screen.queryByRole('button', { name: 'Internal note' }),
        ).not.toBeInTheDocument();
    });

    it('shows current triage values with no suggestion and the normal apply path', async () => {
        vi.spyOn(axios, 'get').mockResolvedValue({ data: ticketAssistContract });

        render(<TicketAssistPanel ticketId={7} />);
        fireEvent.click(screen.getByRole('button', { name: 'Assist' }));
        fireEvent.click(await screen.findByRole('tab', { name: 'Triage' }));

        expect(
            screen.getByText('Confirm the switch replacement window'),
        ).toBeVisible();
        expect(screen.getAllByText('No suggestion')).toHaveLength(4);
        expect(
            screen.getByText(/12 published/),
        ).toBeVisible();
        expect(
            screen.getByText(/normal versioned ticket update/),
        ).toBeVisible();
    });
});

describe('KnowledgeAssistPanel', () => {
    afterEach(() => vi.restoreAllMocks());

    it('shows documentation capabilities disabled with citations and the publication rule', async () => {
        vi.spyOn(axios, 'get').mockResolvedValue({ data: articleAssistContract });

        render(
            <KnowledgeAssistPanel articleId={21} open onClose={vi.fn()} />,
        );

        expect(
            await screen.findByText(
                'Draft a guide from a resolved ticket, with the ticket revision cited',
            ),
        ).toBeVisible();
        expect(screen.getAllByText('Not enabled')).toHaveLength(3);
        expect(
            screen.getByText(/draft version 5 · published revision 2/),
        ).toBeVisible();
        expect(
            screen.getByText('Linked resolved tickets (permission-checked) (1)'),
        ).toBeVisible();
        expect(
            screen.getByText(/existing author and reviewer actions/),
        ).toBeVisible();
        expect(vi.mocked(axios.get)).toHaveBeenCalledWith(
            '/it/knowledge/21/assist',
            expect.anything(),
        );
    });
});
