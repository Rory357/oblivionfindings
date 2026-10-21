import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { toast } from 'sonner';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    ChatError,
    chatRequest,
    readPublicationMemory,
    setChatDraftActor,
} from './api';
import { ChatAttachmentPublication } from './chat-attachment-publication';

vi.mock('./api', async () => ({
    ...(await vi.importActual<typeof import('./api')>('./api')),
    chatRequest: vi.fn(),
}));
vi.mock('sonner', () => ({ toast: { success: vi.fn() } }));
const updates = vi.hoisted(() => ({
    refresh: undefined as undefined | (() => Promise<boolean>),
}));
vi.mock('./chat-update-watch', () => ({
    watchChatUpdates: (refresh: () => Promise<boolean>) => {
        updates.refresh = refresh;
        void refresh();
        return () => {};
    },
}));
const path = '/chat/api/attachments/9/publication';
const key = '1:staff:71:attachment:9';
const options = {
    clients: [{ id: 8, name: 'Jamie Example' }],
    locked_client_id: 8,
    has_more: false,
    destinations: ['documents', 'photos'],
};
const initialReview = {
    client: { id: 8, name: 'Jamie Example' },
    file_name: 'Visit.png',
    destination: 'documents',
    family_visible: false,
    family_readers: [],
    record_audience: 'Authorised record readers, now and in future.',
    audience_token: 'first-review',
};
const close = vi.fn();
function choose(label: string, option: string) {
    fireEvent.keyDown(screen.getByLabelText(label), { key: 'Enter' });
    fireEvent.click(screen.getByRole('option', { name: option }));
}
function component(actorId = 1) {
    return (
        <ChatAttachmentPublication
            actorId={actorId}
            memoryKey={actorId === 1 ? key : `${actorId}:staff:71:attachment:9`}
            attachmentId={9}
            onClose={close}
            onReturnFocus={vi.fn()}
        />
    );
}
const publish = () =>
    screen.getByRole('button', { name: 'Publish reviewed copy' });
async function acknowledge() {
    fireEvent.click(await screen.findByRole('checkbox'));
}

describe('reviewed attachment publication', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        setChatDraftActor(null);
        setChatDraftActor(1);
        updates.refresh = undefined;
        vi.mocked(chatRequest).mockImplementation(async (url) => {
            if (url.includes('?q=')) return options;
            if (url.endsWith('/review')) return initialReview;
            return {
                client_id: 8,
                destination: 'documents',
                href: '/clients/8?tab=documents',
            };
        });
    });
    it('requires an audience acknowledgement and publishes the exact reviewed selection', async () => {
        render(component());
        await screen.findByText('Visit.png → Jamie Example · Documents');
        expect(screen.getByLabelText('Client')).toBeDisabled();
        expect(publish()).toBeDisabled();
        await acknowledge();
        fireEvent.click(publish());
        await waitFor(() => expect(close).toHaveBeenCalledTimes(1));
        const write = vi
            .mocked(chatRequest)
            .mock.calls.find(([url]) => url === path)!;
        expect(JSON.parse(String(write[1]?.body))).toMatchObject({
            client_id: 8,
            destination: 'documents',
            family_visible: false,
            audience_token: 'first-review',
            audience_confirmed: true,
        });
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.every(
                    ([, init]) =>
                        new Headers(init?.headers).get('X-Chat-Actor') === '1',
                ),
        ).toBe(true);
        expect(readPublicationMemory(key).pending).toBeNull();
        expect(toast.success).toHaveBeenCalled();
    });
    it('keeps an uncertain request across remount and retries the same mutation without another review', async () => {
        let writes = 0;
        vi.mocked(chatRequest).mockImplementation(async (url) => {
            if (url === path) {
                if (++writes === 1) throw new Error('Lost response');
                return { client_id: 8, destination: 'documents', href: null };
            }
            if (url.includes('?q=')) return options;
            return initialReview;
        });
        const first = render(component());
        await acknowledge();
        fireEvent.click(publish());
        await screen.findByRole('button', { name: 'Retry same publication' });
        const pending = readPublicationMemory(key).pending;
        first.unmount();
        render(component());
        expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry same publication' }),
        );
        await waitFor(() => expect(close).toHaveBeenCalledTimes(1));
        const bodies = vi
            .mocked(chatRequest)
            .mock.calls.filter(([url]) => url === path)
            .map(([, init]) => init?.body);
        expect(bodies).toEqual([pending, pending]);
    });
    it('withdraws acknowledgement when readers change and blocks writes after a failed audience check', async () => {
        render(component());
        await acknowledge();
        expect(publish()).toBeEnabled();
        vi.mocked(chatRequest).mockResolvedValue({
            ...initialReview,
            audience_token: 'new-review',
        });
        await act(async () => {
            await updates.refresh?.();
        });
        expect(screen.getByRole('checkbox')).not.toBeChecked();
        expect(publish()).toBeDisabled();
        await acknowledge();
        vi.mocked(chatRequest).mockRejectedValue(
            new Error('Connection unavailable'),
        );
        await act(async () => {
            await updates.refresh?.();
        });
        expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
        expect(publish()).toBeDisabled();
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Connection unavailable',
        );
    });
    it.each([403, 419])(
        'clears denied access (%s) and ignores a late result from a previous account',
        async (status) => {
            const first = render(component());
            await acknowledge();
            vi.mocked(chatRequest).mockRejectedValue(
                new ChatError('Access ended', status),
            );
            await act(async () => {
                await updates.refresh?.();
            });
            expect(screen.queryByText(/Visit.png →/)).not.toBeInTheDocument();
            expect(readPublicationMemory(key).client).toBe('');
            first.unmount();
            vi.mocked(chatRequest).mockImplementation(async (url) =>
                url.includes('?q=') ? options : initialReview,
            );
            const second = render(component());
            await acknowledge();
            let finish!: (value: unknown) => void;
            vi.mocked(chatRequest).mockImplementation(
                () =>
                    new Promise((resolve) => {
                        finish = resolve;
                    }),
            );
            fireEvent.click(publish());
            const oldSignal = vi.mocked(chatRequest).mock.calls.at(-1)![1]
                ?.signal;
            setChatDraftActor(2);
            second.rerender(component(2));
            expect(oldSignal?.aborted).toBe(true);
            expect(screen.queryByText(/Visit.png →/)).not.toBeInTheDocument();
            await act(async () =>
                finish({
                    client_id: 8,
                    destination: 'documents',
                    href: '/clients/8',
                }),
            );
            expect(close).not.toHaveBeenCalled();
            expect(toast.success).not.toHaveBeenCalled();
            expect(readPublicationMemory(key).pending).toBeNull();
        },
    );
    it('requires a new acknowledgement after a conflict without silently publishing refreshed details', async () => {
        render(component());
        await acknowledge();
        vi.mocked(chatRequest).mockImplementation(async (url) => {
            if (url === path) throw new ChatError('Audience changed', 409);
            if (url.includes('?q=')) return options;
            return { ...initialReview, audience_token: 'latest-review' };
        });
        fireEvent.click(publish());
        await screen.findByText('Audience changed');
        await screen.findByRole('checkbox');
        expect(screen.getByRole('checkbox')).not.toBeChecked();
        expect(publish()).toBeDisabled();
        expect(close).not.toHaveBeenCalled();
        expect(readPublicationMemory(key).pending).toBeNull();
    });
    it('reviews the family audience and photo destination before enabling publication', async () => {
        vi.mocked(chatRequest).mockImplementation(async (url, init) => {
            if (url.includes('?q=')) return options;
            const selection = JSON.parse(String(init?.body));
            return {
                ...initialReview,
                ...selection,
                family_readers: selection.family_visible
                    ? [{ id: 3, name: 'Moana Example' }]
                    : [],
                audience_token: JSON.stringify(selection),
            };
        });
        render(component());
        await acknowledge();
        choose('Destination', 'Client photos');
        choose('Record audience', 'Staff and approved family');
        await screen.findByText(/Approved family now: Moana Example/);
        expect(screen.getByRole('checkbox')).not.toBeChecked();
        expect(publish()).toBeDisabled();
        expect(
            screen.getByText(/Later approved family members/),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/including any location or camera details/),
        ).toBeInTheDocument();
    });

    it('preserves staff client search through close and reopen but requires a fresh audience acknowledgement', async () => {
        vi.mocked(chatRequest).mockImplementation(async (url) => {
            if (url.includes('?q='))
                return {
                    ...options,
                    locked_client_id: null,
                    clients: url.endsWith('Jamie') ? options.clients : [],
                    has_more: !url.endsWith('Jamie'),
                };
            return initialReview;
        });
        const first = render(component());
        fireEvent.change(screen.getByLabelText('Find client'), {
            target: { value: 'Jamie' },
        });
        // Wait for the "Jamie" results: enabled, complete, and not empty.
        await waitFor(() => {
            expect(screen.getByLabelText('Client')).toBeEnabled();
            expect(
                screen.queryByText(/More clients match/),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByText(/No matching clients/),
            ).not.toBeInTheDocument();
        });
        fireEvent.keyDown(screen.getByLabelText('Client'), { key: 'Enter' });
        fireEvent.click(
            await screen.findByRole('option', { name: 'Jamie Example' }),
        );
        await acknowledge();
        expect(publish()).toBeEnabled();
        first.unmount();
        render(component());
        expect(screen.getByLabelText('Find client')).toHaveValue('Jamie');
        await screen.findByRole('checkbox');
        expect(screen.getByLabelText('Client')).toHaveTextContent(
            'Jamie Example',
        );
        expect(screen.getByRole('checkbox')).not.toBeChecked();
        expect(publish()).toBeDisabled();
    });
});
