import type { ItDraftSnapshot } from '@/hooks/it-ticket-draft-contract';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { TicketWorkWorkspace } from '../ticket-work-workspace';

const mock = vi.hoisted(() => ({
    post: vi.fn(),
    get: vi.fn(),
    save: vi.fn(),
    discard: vi.fn(),
    refresh: vi.fn(),
    consumed: vi.fn(),
    check: vi.fn(),
    startNew: vi.fn(),
    draftState: 'ready',
    saved: null as ItDraftSnapshot | null,
}));
vi.mock('axios', () => ({
    default: {
        post: mock.post,
        get: mock.get,
        isAxiosError: (error: unknown) =>
            !!(error as { response?: unknown })?.response,
    },
}));
vi.mock('@/components/it/ticket-draft-recovery', () => ({
    TicketDraftRecovery: () => null,
}));
vi.mock('@/hooks/use-it-ticket-draft', () => ({
    useItTicketDraft: ({ enabled }: { enabled: boolean }) => ({
        state: enabled ? mock.draftState : 'disabled',
        busy: false,
        isSaved: (snapshot: ItDraftSnapshot) =>
            JSON.stringify(mock.saved) === JSON.stringify(snapshot),
        save: mock.save,
        discard: mock.discard,
        check: mock.check,
        startNew: mock.startNew,
        acknowledgeConsumed: mock.consumed,
        submissionReference: () => ({
            draft_uuid: '38de809a-d894-40ba-8427-377b0122b261',
            draft_revision: 1,
            draft_actor_user_id: 1,
        }),
        clearOwnedBrowserWork: vi.fn(),
    }),
}));

function workspace(drafts = false) {
    return render(
        <TicketWorkWorkspace
            work={{ ready: true, timezone: 'Pacific/Auckland' }}
            ticketId={7}
            actorId={1}
            version={3}
            tab="costs"
            editable
            onRefresh={mock.refresh}
            onNote={vi.fn()}
            draftsEnabled={drafts}
        />,
    );
}
async function enterCost() {
    fireEvent.click(screen.getByRole('button', { name: 'Add cost' }));
    await screen.findByRole('dialog');
    fireEvent.change(screen.getByLabelText('Description'), {
        target: { value: 'Replacement power supply' },
    });
    fireEvent.change(screen.getByLabelText('Unit cost (NZD)'), {
        target: { value: '75' },
    });
    fireEvent.click(screen.getByRole('button', { name: /^Save$/ }));
}
beforeEach(() => {
    vi.clearAllMocks();
    sessionStorage.clear();
    mock.saved = null;
    mock.draftState = 'ready';
    mock.check.mockResolvedValue({
        state: 'consumed',
        has_content: false,
        capabilities: { start_new: true },
    });
    mock.startNew.mockResolvedValue(true);
    mock.discard.mockResolvedValue(true);
    mock.save.mockImplementation(async (snapshot: ItDraftSnapshot) => {
        mock.saved = snapshot;
        return true;
    });
});
describe('ticket work save recovery', () => {
    it('retries an uncertain expense with the same UUID and immutable fields', async () => {
        mock.post
            .mockRejectedValueOnce(new Error('Connection interrupted'))
            .mockImplementationOnce(async (_url, command) => ({
                data: {
                    status: 'committed',
                    request_uuid: command.request_uuid,
                },
            }));
        workspace();
        await enterCost();
        await screen.findByRole('button', { name: 'Retry same save' });
        expect(screen.getByLabelText('Description')).toBeDisabled();
        const sent = mock.post.mock.calls[0][1];
        expect(sessionStorage.getItem('it-work-command:1:7')).not.toContain(
            'Replacement',
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry same save' }),
        );
        await waitFor(() => expect(mock.refresh).toHaveBeenCalledTimes(1));
        expect(mock.post.mock.calls[1][1]).toEqual(sent);
        expect(sessionStorage.getItem('it-work-command:1:7')).toBeNull();
    });
    it('saves the exact frozen command to its draft before committing a cost', async () => {
        mock.post.mockImplementation(async (_url, command) => ({
            data: {
                status: 'committed',
                request_uuid: command.request_uuid,
                draft: { state: 'consumed' },
            },
        }));
        workspace(true);
        await enterCost();
        await waitFor(() => expect(mock.refresh).toHaveBeenCalledTimes(1));
        const command = mock.post.mock.calls[0][1];
        const saved = JSON.parse(mock.saved!.fields.work_form!);
        expect(saved.pending.payload).toEqual(command.payload);
        // Laravel converts blank optional strings to null before validating the request.
        expect(command.payload.reference).toBeNull();
        expect(saved.pending.payload.reference).toBeNull();
        expect(saved.pending.request_uuid).toBe(command.request_uuid);
        expect(command.draft_uuid).toBe('38de809a-d894-40ba-8427-377b0122b261');
        expect(mock.consumed).toHaveBeenCalled();
    });
    it('refreshes consumed draft capabilities before opening the next work form', async () => {
        mock.draftState = 'terminal';
        mock.startNew.mockImplementation(
            async () => mock.check.mock.calls.length > 0,
        );
        workspace(true);
        fireEvent.click(screen.getByRole('button', { name: 'Add cost' }));
        await screen.findByRole('dialog');
        expect(mock.check).toHaveBeenCalledOnce();
        expect(mock.startNew).toHaveBeenCalledOnce();
    });
    it('preserves newer saved work discovered after a previous command finished', async () => {
        mock.draftState = 'terminal';
        mock.check.mockResolvedValue({ state: 'active', has_content: true });
        workspace(true);
        fireEvent.click(screen.getByRole('button', { name: 'Add cost' }));
        await screen.findByText(
            'Check and resume any saved work before starting a new form.',
        );
        expect(mock.startNew).not.toHaveBeenCalled();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
    it('unlocks invalid fields after a definitive validation rejection', async () => {
        mock.post.mockRejectedValue({
            response: { status: 422, data: { message: 'Choose a reviewer' } },
        });
        workspace();
        await enterCost();
        await screen.findByText('Choose a reviewer');
        expect(screen.getByLabelText('Description')).toBeEnabled();
        expect(sessionStorage.getItem('it-work-command:1:7')).toBeNull();
    });
    it('serializes cancellation of an unconfirmed request before discarding the fields', async () => {
        mock.post
            .mockRejectedValueOnce(new Error('Connection interrupted'))
            .mockImplementationOnce(async (url) => ({
                data: {
                    status: 'cancelled',
                    request_uuid: url.split('/').at(-2),
                },
            }));
        workspace();
        await enterCost();
        await screen.findByRole('button', { name: 'Retry same save' });
        fireEvent.click(screen.getByRole('button', { name: /^Cancel$/ }));
        fireEvent.click(
            await screen.findByRole('button', { name: 'Cancel request' }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(mock.post.mock.calls[1][0]).toContain('/commands/');
        expect(mock.post.mock.calls[1][0]).toContain('/cancel');
        expect(sessionStorage.getItem('it-work-command:1:7')).toBeNull();
    });
});
