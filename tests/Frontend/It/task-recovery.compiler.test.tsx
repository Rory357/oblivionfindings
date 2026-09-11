import { TicketWorkTasks } from '@/components/it/ticket-work-tasks';
import { clearItTicketDraftMemory } from '@/hooks/use-it-ticket-draft-memory';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const props = {
    actorId: 7,
    ticketId: 42,
    version: 4,
    tasks: [],
    canViewWork: true,
    canManage: true,
    taskWork: { storage_ready: true, can_create: true, can_reorder: true },
    assignees: [],
    teams: [],
    onCommitted: vi.fn(),
};

async function keepNewTask() {
    fireEvent.click(screen.getByRole('button', { name: 'Add task' }));
    fireEvent.change(screen.getByLabelText(/^Task title/), {
        target: { value: 'Retained compiler regression task' },
    });
    fireEvent.change(screen.getByLabelText(/^Description/), {
        target: { value: 'Private proposal kept without a server write.' },
    });
    fireEvent.keyDown(screen.getByRole('dialog'), {
        key: 'Escape',
        code: 'Escape',
    });
    fireEvent.click(
        await screen.findByRole('button', { name: 'Keep draft and close' }),
    );
    await waitFor(() =>
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
    );
}

describe('production-compiled task recovery discovery', () => {
    beforeEach(() => {
        clearItTicketDraftMemory();
        sessionStorage.clear();
        props.onCommitted.mockClear();
        const unexpectedRequest = () =>
            Promise.reject(
                new Error('This local recovery journey needs no HTTP request.'),
            );
        vi.spyOn(axios, 'request').mockImplementation(unexpectedRequest);
        vi.spyOn(axios, 'get').mockImplementation(unexpectedRequest);
        vi.spyOn(axios, 'post').mockImplementation(unexpectedRequest);
    });

    afterEach(async () => {
        cleanup();
        await new Promise((resolve) => setTimeout(resolve, 0));
        clearItTicketDraftMemory();
        sessionStorage.clear();
        vi.restoreAllMocks();
    });

    it('advertises a kept task in the existing register without reopening Add task', async () => {
        render(<TicketWorkTasks {...props} />);
        await keepNewTask();

        // Capture the parent register before opening another editor. A new
        // editor mount must not be needed to discover a retained proposal.
        const registerAdvertised =
            screen.queryByText('Task work to recover') !== null;
        expect(screen.getByText('No work tasks yet')).toBeVisible();
        expect(
            screen.queryByText('Private proposal kept without a server write.'),
        ).not.toBeInTheDocument();

        // Distinguish missing notice publication from loss of the private copy.
        fireEvent.click(screen.getByRole('button', { name: 'Add task' }));
        expect(
            await screen.findByRole('button', { name: 'Resume draft 1' }),
        ).toBeVisible();
        expect(screen.getByLabelText(/^Task title/)).toHaveValue('');
        expect(
            screen.queryByRole('button', { name: 'Resume draft 2' }),
        ).toBeNull();
        expect(axios.request).not.toHaveBeenCalled();
        expect(axios.get).not.toHaveBeenCalled();
        expect(axios.post).not.toHaveBeenCalled();
        expect(
            registerAdvertised,
            'The mounted register must publish the kept task notice.',
        ).toBe(true);
    });

    it('removes a previously discovered notice after explicit discard in its editor', async () => {
        const originalPage = render(<TicketWorkTasks {...props} />);
        await keepNewTask();
        originalPage.unmount();

        // A newly mounted register initially sees the existing metadata.
        render(<TicketWorkTasks {...props} />);
        expect(screen.getByText('Task work to recover')).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Open retained create draft 1',
            }),
        );
        fireEvent.click(
            await screen.findByRole('button', { name: 'Discard draft 1' }),
        );
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Discard draft',
            }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
        expect(
            screen.queryByRole('button', { name: 'Resume draft 1' }),
        ).toBeNull();
        fireEvent.keyDown(screen.getByRole('dialog'), {
            key: 'Escape',
            code: 'Escape',
        });
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(axios.request).not.toHaveBeenCalled();
        expect(axios.get).not.toHaveBeenCalled();
        expect(axios.post).not.toHaveBeenCalled();
        expect(
            screen.queryByText('Task work to recover'),
            'The mounted register must remove the explicitly discarded notice.',
        ).not.toBeInTheDocument();
    });
});
