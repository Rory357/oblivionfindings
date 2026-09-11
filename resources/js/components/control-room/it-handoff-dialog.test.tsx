import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';

import axios from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { ControlRoomItHandoffDialog } from './it-handoff-dialog';

vi.mock('@inertiajs/react', () => ({
    router: { on: vi.fn(() => () => {}), visit: vi.fn(), reload: vi.fn() },
}));
const ticket = {
    id: 42,
    reference: 'IT-000042',
    title: 'Existing network work',
    status: 'open',
    version: 5,
    href: '/it/tickets/42',
};
const discovery = {
    viewer_user_id: 7,
    alert_id: 11,
    alert_version: 'a'.repeat(64),
    can_start: true,
    site: { id: 3, name: 'Approved house' },
    services: [{ id: 2, name: 'Network service' }],
    has_existing_work: false,
    existing_work: [],
    candidates: [ticket],
};
const props = {
    actorId: 7,
    alertId: 11,
    alertReference: 'AL-000011',
    allowed: true,
    onClose: vi.fn(),
};
const key = 'it.pending-control-room-handoff.v1.7:11';
beforeEach(() => {
    sessionStorage.clear();
    props.onClose.mockReset();
    vi.spyOn(axios, 'get').mockResolvedValue({ data: { data: discovery } });
});
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    sessionStorage.clear();
});

const interaction = {
    click: (element: HTMLElement) => fireEvent.click(element),
    type: (element: HTMLElement, value: string) =>
        fireEvent.change(element, { target: { value } }),
};

async function chooseLink() {
    const user = interaction;
    await user.click(
        await screen.findByRole('button', { name: /Link existing IT work/ }),
    );
    await user.click(
        screen.getByRole('combobox', { name: 'Existing IT incident' }),
    );
    await user.click(await screen.findByRole('option', { name: /IT-000042/ }));
    await user.click(screen.getByRole('button', { name: 'Continue' }));
    await user.type(
        screen.getByLabelText(/Why is this technical handoff needed/),
        'Restore the site network.',
    );
    await user.click(screen.getByRole('button', { name: 'Continue' }));
    return user;
}

it('links a reviewed permitted ticket and shows success only for its committed response', async () => {
    const post = vi
        .spyOn(axios, 'post')
        .mockImplementation(async (_path, body) => ({
            data: {
                status: 'committed',
                data: {
                    ...(body as Record<string, unknown>),
                    alert_id: 11,
                    replayed: false,
                    changed: true,
                    outcome: 'linked',
                    ticket,
                },
            },
        }));
    render(<ControlRoomItHandoffDialog {...props} />);
    const user = await chooseLink();
    expect(post).not.toHaveBeenCalled();
    await user.click(
        screen.getByRole('button', { name: 'Link selected ticket' }),
    );
    expect(await screen.findByText('IT handoff saved')).toBeInTheDocument();
    expect(
        screen.getByRole('button', { name: 'Open IT ticket' }),
    ).toHaveFocus();
    expect(post.mock.calls[0][0]).toBe('/it/control-room/alerts/11/handoff');
    expect(post.mock.calls[0][1]).toMatchObject({
        action: 'link',
        ticket_id: 42,
        ticket_version: 5,
        viewer_user_id: 7,
        reason: 'Restore the site network.',
    });
    expect(sessionStorage.getItem(key)).toBeNull();
});

it('requires complete technical classification and sends the reviewed creation fields', async () => {
    const post = vi
        .spyOn(axios, 'post')
        .mockImplementation(async (_path, body) => ({
            data: {
                status: 'committed',
                data: {
                    ...(body as Record<string, unknown>),
                    alert_id: 11,
                    replayed: false,
                    changed: true,
                    outcome: 'created',
                    ticket,
                },
            },
        }));
    render(<ControlRoomItHandoffDialog {...props} />);
    fireEvent.click(
        await screen.findByRole('button', { name: /Create IT work/ }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.change(screen.getByLabelText(/Ticket title/), {
        target: { value: 'Restore the network' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(
        await screen.findByText(
            'Complete the required technical details and handoff reason.',
        ),
    ).toBeInTheDocument();
    expect(screen.getByLabelText(/Ticket title/)).toHaveValue(
        'Restore the network',
    );
    expect(post).not.toHaveBeenCalled();
    fireEvent.change(screen.getByLabelText(/Technical work needed/), {
        target: { value: 'Test the approved site connection.' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Network' }));
    fireEvent.click(screen.getByRole('combobox', { name: 'Choose impact' }));
    fireEvent.click(screen.getByRole('option', { name: 'Site' }));
    fireEvent.click(screen.getByRole('combobox', { name: 'Choose urgency' }));
    fireEvent.click(screen.getByRole('option', { name: 'High' }));
    fireEvent.click(screen.getByRole('combobox', { name: 'Choose a service' }));
    fireEvent.click(screen.getByRole('option', { name: 'Network service' }));
    fireEvent.change(
        screen.getByLabelText(/Why is this technical handoff needed/),
        { target: { value: 'Restore operational connectivity.' } },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(post).not.toHaveBeenCalled();
    fireEvent.click(
        screen.getByRole('button', { name: 'Create and link ticket' }),
    );
    expect(await screen.findByText('IT handoff saved')).toBeInTheDocument();
    expect(post.mock.calls[0][1]).toMatchObject({
        action: 'create',
        title: 'Restore the network',
        description: 'Test the approved site connection.',
        category: 'network',
        impact: 'site',
        urgency: 'high',
        it_service_id: 2,
        reason: 'Restore operational connectivity.',
    });
});

it('retains the draft after a stale ticket and requires a fresh explicit selection before retrying', async () => {
    const post = vi.spyOn(axios, 'post').mockRejectedValue({
        isAxiosError: true,
        response: { status: 409, data: { code: 'stale_ticket' } },
    });
    render(<ControlRoomItHandoffDialog {...props} />);
    const user = await chooseLink();
    await user.click(
        screen.getByRole('button', { name: 'Link selected ticket' }),
    );
    expect(
        await screen.findByRole('button', {
            name: 'Refresh records and review again',
        }),
    ).toBeInTheDocument();
    expect(screen.getByText('Restore the site network.')).toBeInTheDocument();
    expect(
        screen.getByRole('button', { name: 'Link selected ticket' }),
    ).toBeDisabled();
    vi.mocked(axios.get).mockResolvedValue({
        data: {
            data: { ...discovery, candidates: [{ ...ticket, version: 6 }] },
        },
    });
    fireEvent.click(
        screen.getByRole('button', {
            name: 'Refresh records and review again',
        }),
    );
    await screen.findByRole('combobox', { name: 'Existing IT incident' });
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(
        await screen.findByText(
            'Choose whether to create IT work or select an existing ticket.',
        ),
    ).toBeInTheDocument();
    expect(post).toHaveBeenCalledTimes(1);
    fireEvent.click(
        screen.getByRole('combobox', { name: 'Existing IT incident' }),
    );
    fireEvent.click(screen.getByRole('option', { name: /IT-000042/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(
        screen.getByLabelText(/Why is this technical handoff needed/),
    ).toHaveValue('Restore the site network.');
});

it('explains server validation failures that have no field on the review step', async () => {
    vi.spyOn(axios, 'post').mockRejectedValue({
        isAxiosError: true,
        response: {
            status: 422,
            data: {
                message: 'Review the IT handoff fields.',
                errors: {
                    alert_version: [
                        'The operational alert changed. Refresh the handoff before submitting again.',
                    ],
                },
            },
        },
    });
    const view = render(<ControlRoomItHandoffDialog {...props} />);
    const user = await chooseLink();
    await user.click(
        screen.getByRole('button', { name: 'Link selected ticket' }),
    );
    expect(
        await screen.findByRole('list', { name: 'Handoff validation errors' }),
    ).toHaveTextContent(
        'The operational alert changed. Refresh the handoff before submitting again.',
    );
    expect(screen.getByText('Restore the site network.')).toBeInTheDocument();
    expect(
        screen.getByRole('button', { name: 'Link selected ticket' }),
    ).toBeDisabled();
    view.rerender(<ControlRoomItHandoffDialog {...props} allowed={false} />);
    expect(
        screen.queryByRole('list', { name: 'Handoff validation errors' }),
    ).not.toBeInTheDocument();
});

it('preserves the submitted identity on failure and distinguishes server cancellation from success', async () => {
    const post = vi
        .spyOn(axios, 'post')
        .mockRejectedValueOnce(new Error('Lost response'));
    render(<ControlRoomItHandoffDialog {...props} />);
    const user = await chooseLink();
    await user.click(
        screen.getByRole('button', { name: 'Link selected ticket' }),
    );
    expect(
        await screen.findByText('Handoff outcome unconfirmed'),
    ).toBeInTheDocument();
    expect(screen.queryByText('IT handoff saved')).not.toBeInTheDocument();
    const identity = JSON.parse(sessionStorage.getItem(key)!);
    expect(Object.keys(identity).sort()).toEqual([
        'actorId',
        'alertId',
        'requestUuid',
    ]);
    post.mockResolvedValueOnce({
        data: {
            status: 'cancelled',
            data: {
                viewer_user_id: 7,
                alert_id: 11,
                request_uuid: identity.requestUuid,
                replayed: false,
                cancelled_at: '2026-09-12T00:00:00Z',
            },
        },
    });
    await user.click(
        screen.getByRole('button', { name: 'Cancel pending handoff' }),
    );
    expect(await screen.findByText('Handoff cancelled')).toBeInTheDocument();
    expect(screen.queryByText('IT handoff saved')).not.toBeInTheDocument();
    expect(sessionStorage.getItem(key)).toBeNull();
});

it('requires an explicit discard and retains technical text when closing is cancelled', async () => {
    render(<ControlRoomItHandoffDialog {...props} />);
    const user = interaction;
    await user.click(
        await screen.findByRole('button', { name: /Create IT work/ }),
    );
    await user.click(screen.getByRole('button', { name: 'Continue' }));
    await user.type(
        screen.getByLabelText(/Ticket title/),
        'Private unsaved draft',
    );
    await user.click(screen.getByRole('button', { name: 'Back to alert' }));
    expect(
        await screen.findByText('Discard this handoff draft?'),
    ).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(screen.getByLabelText(/Ticket title/)).toHaveValue(
        'Private unsaved draft',
    );
    expect(props.onClose).not.toHaveBeenCalled();
    const beforeDiscard = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(beforeDiscard);
    expect(beforeDiscard.defaultPrevented).toBe(true);
    props.onClose.mockImplementationOnce(() => {
        const approvedLeave = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(approvedLeave);
        expect(approvedLeave.defaultPrevented).toBe(false);
    });
    await user.click(screen.getByRole('button', { name: 'Back to alert' }));
    await user.click(screen.getByRole('button', { name: 'Discard draft' }));
    expect(props.onClose).toHaveBeenCalledOnce();
});

it('blocks duplicate handoffs and offers canonical existing work without creating another ticket', async () => {
    vi.mocked(axios.get).mockResolvedValue({
        data: {
            data: {
                ...discovery,
                has_existing_work: true,
                existing_work: [ticket],
            },
        },
    });
    const post = vi.spyOn(axios, 'post');
    render(<ControlRoomItHandoffDialog {...props} />);
    expect(
        await screen.findByText('This alert already has IT work'),
    ).toBeInTheDocument();
    expect(
        screen.getByRole('button', { name: 'Open linked ticket' }),
    ).toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Continue' }),
    ).not.toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();
});

it('conceals fields immediately when capability is revoked and does not submit a draft', async () => {
    const post = vi.spyOn(axios, 'post');
    const view = render(<ControlRoomItHandoffDialog {...props} />);
    fireEvent.click(
        await screen.findByRole('button', { name: /Create IT work/ }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.change(screen.getByLabelText(/Ticket title/), {
        target: { value: 'Private draft title' },
    });
    view.rerender(<ControlRoomItHandoffDialog {...props} allowed={false} />);
    expect(
        screen.queryByDisplayValue('Private draft title'),
    ).not.toBeInTheDocument();
    expect(
        await screen.findByText('IT handoff access unavailable'),
    ).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();
});

it('restores an unknown request after alert resolution and never shows new-create controls', async () => {
    const identity = {
        actorId: 7,
        alertId: 11,
        requestUuid: crypto.randomUUID(),
    };
    sessionStorage.setItem(key, JSON.stringify(identity));
    vi.mocked(axios.get).mockImplementation(async (path) =>
        String(path).includes('/commands/')
            ? {
                  data: {
                      status: 'unconfirmed',
                      data: {
                          viewer_user_id: 7,
                          alert_id: 11,
                          request_uuid: identity.requestUuid,
                      },
                  },
              }
            : { data: { data: { ...discovery, can_start: false } } },
    );
    render(<ControlRoomItHandoffDialog {...props} />);
    fireEvent.click(
        await screen.findByRole('button', { name: 'Check saved result' }),
    );
    await waitFor(() =>
        expect(
            screen.getByText('Handoff outcome unconfirmed'),
        ).toBeInTheDocument(),
    );
    expect(
        screen.queryByRole('button', { name: 'Continue' }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Retry unchanged request' }),
    ).not.toBeInTheDocument();
    expect(sessionStorage.getItem(key)).not.toBeNull();
});
