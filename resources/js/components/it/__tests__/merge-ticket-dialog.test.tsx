import { draftRecord } from '@/hooks/it-ticket-draft-contract';
import { clearItTicketDraftMemory } from '@/hooks/use-it-ticket-draft-memory';
import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import axios, { type AxiosRequestConfig } from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { MergeTicketDialog } from '../merge-ticket-dialog';

const mocks = vi.hoisted(() => ({ visit: vi.fn(), on: vi.fn(() => vi.fn()) }));
vi.mock('@inertiajs/react', () => ({
    router: { visit: mocks.visit, on: vi.fn(() => vi.fn()) },
    useForm: vi.fn(),
}));
const ticket = {
    id: 41,
    reference: 'IT-000041',
    title: 'Original connection report',
    lock_version: 2,
};
const target = {
    id: 42,
    reference: 'IT-000042',
    title: 'Same connection issue',
    lock_version: 4,
    status: 'open',
    priority: 'high',
};
const reason = 'Duplicate report of the same connection issue';
function reviewed(config: AxiosRequestConfig, blockers: string[] = []) {
    const params = config.params as Record<string, number | string>;
    const inventory = {
        public_comments: 2,
        internal_notes: 1,
        ticket_files: 1,
        comment_files: 2,
        watchers: 2,
        links: 1,
        tasks: 1,
        unfinished_required_tasks: 0,
        approval_requests: 1,
        pending_approval_requests: 0,
        expired_approval_requests: 0,
    };
    return {
        status: 200,
        data: {
            status: 'reviewed',
            data: {
                viewer_user_id: params.actor_user_id,
                review_nonce: params.review_nonce,
                review_token: 'synthetic-review-proof',
                source: {
                    ...ticket,
                    lock_version: params.source_version,
                    status: 'open',
                    workflow_state: 'submitted',
                    work_type: 'incident',
                    inventory,
                },
                target: {
                    ...target,
                    lock_version: params.target_version,
                    workflow_state: 'submitted',
                    work_type: 'incident',
                    inventory,
                },
                access_scope_differences: [],
                lifecycle_blockers: blockers,
            },
        },
    };
}
function committed(body: unknown) {
    if (!draftRecord(body) || typeof body.request_uuid !== 'string')
        throw new Error('Missing command identity');
    return {
        status: 200,
        data: {
            status: 'committed',
            data: {
                viewer_user_id: 7,
                source_id: 41,
                target_id: 42,
                request_uuid: body.request_uuid,
                operation: 'ticket.merge',
                source_version: 4,
                target_version: 5,
                url: '/it/tickets/42?merged_from=41',
                replayed: false,
            },
        },
    };
}
const failure = (status: number, data: unknown = {}) => ({
    isAxiosError: true,
    response: { status, data },
});
const mount = () => {
    const onClose = vi.fn();
    return {
        onClose,
        ...render(
            <MergeTicketDialog
                actorId={7}
                ticket={ticket}
                targets={[target]}
                onClose={onClose}
            />,
        ),
    };
};
function propose() {
    fireEvent.click(
        screen.getByRole('button', { name: /Same connection issue/ }),
    );
    fireEvent.change(
        screen.getByRole('textbox', { name: /Reason for merging/ }),
        { target: { value: reason } },
    );
}
async function review() {
    fireEvent.click(screen.getByRole('button', { name: 'Review merge' }));
    await screen.findByText('Move to the survivor');
}
async function submit() {
    await review();
    fireEvent.click(
        screen.getByRole('checkbox', { name: /I have checked the survivor/ }),
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Merge into IT-000042' }),
    );
}
beforeEach(() => {
    sessionStorage.clear();
    clearItTicketDraftMemory();
    mocks.visit.mockClear();
});
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    clearItTicketDraftMemory();
    sessionStorage.clear();
});

it('explains possible duplicates without choosing or sending a merge and preserves search', () => {
    const get = vi.spyOn(axios, 'get');
    const post = vi.spyOn(axios, 'post');
    render(
        <MergeTicketDialog
            actorId={7}
            ticket={ticket}
            targets={[{ ...target, duplicate_reasons: ['same_title'] }]}
            onClose={vi.fn()}
        />,
    );
    expect(
        screen.getByText(/Possible duplicate · Same title, site and work type/),
    ).toBeVisible();
    expect(screen.getByText(/100 most recent open reports/)).toBeVisible();
    expect(
        screen.getByRole('button', { name: /Same connection issue/ }),
    ).toHaveAttribute('aria-pressed', 'false');
    expect(get).not.toHaveBeenCalled();
    expect(post).not.toHaveBeenCalled();
    fireEvent.change(
        screen.getByRole('textbox', { name: 'Search merge targets' }),
        {
            target: { value: 'another reference' },
        },
    );
    expect(screen.getByText('No tickets match this search.')).toBeVisible();
    expect(screen.queryByText(/Possible duplicate ·/)).not.toBeInTheDocument();
});

it('reviews real disposition and requires acknowledgement before a bound command, then explicit navigation', async () => {
    vi.spyOn(axios, 'get').mockImplementation(async (_url, config) =>
        reviewed(config!),
    );
    const post = vi
        .spyOn(axios, 'post')
        .mockImplementation(async (_url, body) => committed(body));
    const view = mount();
    propose();
    expect(post).not.toHaveBeenCalled();
    expect(screen.queryByText('100%')).not.toBeInTheDocument();
    await review();
    expect(screen.getByText('Preserve on the original')).toBeVisible();
    expect(screen.getByText(reason)).toBeVisible();
    expect(
        screen.getByRole('button', { name: 'Merge into IT-000042' }),
    ).toBeDisabled();
    fireEvent.click(
        screen.getByRole('checkbox', { name: /I have checked the survivor/ }),
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Merge into IT-000042' }),
    );
    await screen.findByText('Ticket merged');
    expect(post).toHaveBeenCalledWith(
        '/it/tickets/41/merge',
        expect.objectContaining({
            actor_user_id: 7,
            target_ticket_id: 42,
            source_version: 2,
            target_version: 4,
            reason,
            review_token: 'synthetic-review-proof',
            request_uuid: expect.any(String),
        }),
        expect.any(Object),
    );
    expect(mocks.visit).not.toHaveBeenCalled();
    fireEvent.click(
        screen.getByRole('button', { name: 'Open surviving ticket' }),
    );
    expect(view.onClose).toHaveBeenCalledOnce();
    expect(mocks.visit).toHaveBeenCalledWith('/it/tickets/42?merged_from=41');
});
it('starts each step at the top of the wizard body while retaining the proposal', async () => {
    vi.spyOn(axios, 'get').mockImplementation(async (_url, config) =>
        reviewed(config!),
    );
    mount();
    propose();
    const body = document.querySelector<HTMLElement>(
        '[data-wizard-region="body"]',
    )!;
    body.scrollTop = 180;
    await review();
    expect(body.scrollTop).toBe(0);
    expect(screen.getByText(reason)).toBeVisible();
    body.scrollTop = 240;
    fireEvent.click(screen.getByRole('button', { name: 'Back to proposal' }));
    expect(body.scrollTop).toBe(0);
    expect(document.activeElement).toHaveTextContent(
        'Choose the surviving ticket',
    );
    expect(
        screen.getByRole('textbox', { name: /Reason for merging/ }),
    ).toHaveValue(reason);
});
it('shows lifecycle blockers and cannot submit unfinished work', async () => {
    vi.spyOn(axios, 'get').mockImplementation(async (_url, config) =>
        reviewed(config!, ['Finish the outstanding required task.']),
    );
    const post = vi.spyOn(axios, 'post');
    mount();
    propose();
    await review();
    expect(screen.getByRole('alert')).toHaveTextContent(
        'Finish the outstanding required task.',
    );
    expect(
        screen.getByRole('button', { name: 'Merge into IT-000042' }),
    ).toBeDisabled();
    expect(post).not.toHaveBeenCalled();
});
it('retains a proposal after a failed read and retries review without submitting', async () => {
    const get = vi
        .spyOn(axios, 'get')
        .mockRejectedValueOnce(failure(500))
        .mockImplementationOnce(async (_url, config) => reviewed(config!));
    const post = vi.spyOn(axios, 'post');
    mount();
    propose();
    fireEvent.click(screen.getByRole('button', { name: 'Review merge' }));
    await screen.findByRole('alert');
    expect(
        screen.getByRole('textbox', { name: /Reason for merging/ }),
    ).toHaveValue(reason);
    await review();
    expect(get).toHaveBeenCalledTimes(2);
    expect(post).not.toHaveBeenCalled();
});
it('keeps dirty work when discard is cancelled and closes only on deliberate discard', () => {
    const view = mount();
    propose();
    fireEvent.click(screen.getAllByRole('button', { name: 'Close' })[0]);
    expect(screen.getByText('Discard this merge draft?')).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(view.onClose).not.toHaveBeenCalled();
    expect(
        screen.getByRole('textbox', { name: /Reason for merging/ }),
    ).toHaveValue(reason);
    fireEvent.click(screen.getAllByRole('button', { name: 'Close' })[0]);
    fireEvent.click(
        screen.getByRole('button', { name: 'Discard draft and leave' }),
    );
    expect(view.onClose).toHaveBeenCalledOnce();
});
it('does not announce success after lost acknowledgement and retries the identical command', async () => {
    vi.spyOn(axios, 'get').mockImplementation(async (_url, config) =>
        reviewed(config!),
    );
    const post = vi
        .spyOn(axios, 'post')
        .mockRejectedValueOnce(failure(500))
        .mockImplementationOnce(async (_url, body) => committed(body));
    mount();
    propose();
    await submit();
    await screen.findByRole('button', { name: 'Retry original merge' });
    expect(screen.queryByText('Ticket merged')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Review merge' })).toBeDisabled();
    fireEvent.click(
        screen.getByRole('button', { name: 'Retry original merge' }),
    );
    await screen.findByText('Ticket merged');
    expect(post.mock.calls[1][1]).toEqual(post.mock.calls[0][1]);
});
it('conceals the private proposal on session loss while retaining opaque recovery', async () => {
    vi.spyOn(axios, 'get').mockImplementation(async (_url, config) =>
        reviewed(config!),
    );
    vi.spyOn(axios, 'post').mockRejectedValue(failure(419));
    mount();
    propose();
    await submit();
    await screen.findByText('Merge details unavailable');
    expect(screen.queryByText(reason)).not.toBeInTheDocument();
    expect(screen.queryByText('Same connection issue')).not.toBeInTheDocument();
    expect(
        screen.getByRole('button', { name: 'Check result 1' }),
    ).toBeEnabled();
});
it('ignores a late review after switching the signed-in actor', async () => {
    let finish!: (value: ReturnType<typeof reviewed>) => void;
    let requested!: AxiosRequestConfig;
    vi.spyOn(axios, 'get').mockImplementation((_url, config) => {
        requested = config!;
        return new Promise((resolve) => {
            finish = resolve;
        });
    });
    const view = mount();
    propose();
    fireEvent.click(screen.getByRole('button', { name: 'Review merge' }));
    await waitFor(() => expect(finish).toBeTypeOf('function'));
    view.rerender(
        <MergeTicketDialog
            actorId={8}
            ticket={ticket}
            targets={[target]}
            onClose={view.onClose}
        />,
    );
    await act(async () => finish(reviewed(requested)));
    expect(screen.queryByText('Move to the survivor')).not.toBeInTheDocument();
    expect(
        screen.getByRole('textbox', { name: /Reason for merging/ }),
    ).toHaveValue('');
});

it('reauthorizes a retained proposal before restoring its reason and original target version', async () => {
    const post = vi
        .spyOn(axios, 'post')
        .mockImplementation(async (_url, body) => {
            if (!draftRecord(body))
                throw new Error('Expected candidate payload');
            return {
                status: 200,
                data: {
                    candidate: {
                        kind: 'memory',
                        memory_uuid: body.memory_uuid,
                        candidate_uuid: body.candidate_uuid,
                        actor_user_id: 7,
                        purpose: 'merge_work',
                        context_key: 'ticket:41',
                        base_ticket_version: 2,
                        current_ticket_version: 3,
                        authorized: true,
                        capabilities: { submit: false },
                        blocker: {
                            code: 'merge_review_required',
                            message: 'Review both current tickets.',
                        },
                    },
                },
            };
        });
    const first = mount();
    propose();
    fireEvent.click(
        screen.getByRole('button', { name: 'Keep draft and close' }),
    );
    expect(first.onClose).toHaveBeenCalledOnce();
    first.unmount();
    mount();
    expect(
        screen.getByRole('textbox', { name: /Reason for merging/ }),
    ).toHaveValue('');
    fireEvent.click(screen.getByRole('button', { name: 'Resume draft 1' }));
    await waitFor(() =>
        expect(
            screen.getByRole('textbox', { name: /Reason for merging/ }),
        ).toHaveValue(reason),
    );
    expect(post).toHaveBeenCalledWith(
        '/it/tickets/41/merge-candidates/validate',
        expect.objectContaining({
            actor_user_id: 7,
            ticket_id: 41,
            purpose: 'merge_work',
            base_ticket_version: 2,
            fields: { reason, target_ticket_id: 42, target_version: 4 },
            bound_scopes: expect.arrayContaining([
                expect.objectContaining({ target_ticket_id: 42 }),
            ]),
        }),
        expect.any(Object),
    );
    expect(screen.getByRole('button', { name: 'Review merge' })).toBeEnabled();
    expect(screen.queryByText('Move to the survivor')).not.toBeInTheDocument();
    expect(sessionStorage.length).toBe(0);
});

it('explains the missing survivor when the review rail is used before selecting a ticket', async () => {
    const get = vi.spyOn(axios, 'get');
    mount();
    fireEvent.click(
        screen.getByRole('button', {
            name: /Review merge.*Check records and retained evidence/,
        }),
    );
    expect(
        await screen.findByText(
            'Choose the ticket that should survive before reviewing the merge.',
        ),
    ).toBeVisible();
    expect(get).not.toHaveBeenCalled();
});
