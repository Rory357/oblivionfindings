import EndOfShiftChecklist from '@/components/end-of-shift-checklist';
import { router } from '@inertiajs/react';
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatError, chatRequest } from './api';
import type { FamilyInboxPage } from './chat-family-inbox';
import { ChatShiftHandover } from './chat-shift-handover';

const context = vi.hoisted(() => ({ mobile: false }));
vi.mock('@inertiajs/react', () => ({
    router: { post: vi.fn() },
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    usePage: () => ({
        props: {
            auth: { user: { id: 99 } },
            my_day_labels: {},
            chatSharedExperience: true,
        },
    }),
}));
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => context.mobile }));
vi.mock('./api', async (original) => ({
    ...(await original<typeof import('./api')>()),
    chatRequest: vi.fn(),
}));
vi.mock('./chat-thread', () => ({
    ChatThread: ({ handoffRequest }: { handoffRequest: number }) => (
        <p>Shared handover request {handoffRequest}</p>
    ),
}));
const page = (count = 1): FamilyInboxPage => ({
    revision: 'a'.repeat(64),
    items: count
        ? [
              {
                  id: 71,
                  title: 'Jo Example',
                  client: { id: 8, name: 'Jamie Example' },
                  status: 'open',
                  assigned_to: 99,
                  owner_name: 'Alex Example',
                  callback_count: 0,
                  response_expectation: '',
              },
          ]
        : [],
    counts: { mine: count, open: count, waiting: 0, callbacks: 0, resolved: 0 },
    next_before: null,
    scan_floor: count ? 71 : null,
    older_before: null,
    selected_available: true,
    settings: {
        enabled: true,
        family_enabled: true,
        files_enabled: true,
        max_file_mb: 20,
        max_files: 5,
        response_expectation: '',
    },
});
const session = {
    id: 10,
    shift_id: null,
    client_name: null,
    site_name: 'Rimu House',
    break_minutes: 15,
    tasks: [],
    end_of_shift_blockers: [],
};

describe('Shift-end family handover', () => {
    beforeEach(() => {
        context.mobile = false;
        vi.mocked(chatRequest).mockReset().mockResolvedValue(page());
        vi.mocked(router.post).mockReset();
    });
    it('offers off-duty handover without clock-out language or any automatic write', async () => {
        render(
            <ChatShiftHandover
                actorId={99}
                enabled
                active
                context="off-duty"
            />,
        );
        expect(
            await screen.findByText(/You are already off duty/),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Review family handover' }),
        );
        expect(
            await screen.findByText(
                /Your off-duty preference is already saved/,
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(/Your clock-out details stay open/),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getAllByRole('button', { name: 'Return to Chat' }).at(-1)!,
        );
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(router.post).not.toHaveBeenCalled();
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.every(
                    ([, options]) =>
                        !options?.method || options.method === 'GET',
                ),
        ).toBe(true);
    });
    it.each([false, true])(
        'preserves clock-out answers while using the shared handover inbox (narrow=%s)',
        async (mobile) => {
            context.mobile = mobile;
            render(
                <EndOfShiftChecklist
                    session={session}
                    open
                    onOpenChange={vi.fn()}
                />,
            );
            fireEvent.change(screen.getByLabelText('Optional notes'), {
                target: { value: 'Unfinished shift notes' },
            });
            fireEvent.change(screen.getByLabelText('Break minutes'), {
                target: { value: '45' },
            });
            fireEvent.click(
                await screen.findByRole('button', {
                    name: 'Review family handover',
                }),
            );
            expect(
                await screen.findByRole('button', { name: 'Assigned to me 1' }),
            ).toHaveAttribute('aria-pressed', 'true');
            fireEvent.click(
                screen.getByRole('button', {
                    name: /Jo Example About Jamie Example/,
                }),
            );
            fireEvent.click(
                screen.getByRole('button', {
                    name: 'Hand over before shift end',
                }),
            );
            expect(
                screen.getByText('Shared handover request 1'),
            ).toBeInTheDocument();
            fireEvent.click(
                screen
                    .getAllByRole('button', { name: 'Return to clock-out' })
                    .at(-1)!,
            );
            await waitFor(() =>
                expect(screen.getByLabelText('Optional notes')).toBeVisible(),
            );
            expect(screen.getByLabelText('Optional notes')).toHaveValue(
                'Unfinished shift notes',
            );
            expect(screen.getByLabelText('Break minutes')).toHaveValue(45);
            const finish = screen.getByRole('button', { name: 'End shift' });
            expect(finish).toBeEnabled();
            fireEvent.click(finish);
            expect(router.post).toHaveBeenCalledWith(
                '/attendance/clock-out',
                expect.objectContaining({
                    notes: 'Unfinished shift notes',
                    break_minutes: 45,
                    force: false,
                }),
                expect.any(Object),
            );
            expect(
                vi
                    .mocked(chatRequest)
                    .mock.calls.every(([path]) => path.includes('filter=mine')),
            ).toBe(true);
        },
    );
    it('does not prevent clock-out when family work cannot be checked', async () => {
        vi.mocked(chatRequest).mockRejectedValue(new Error('Offline'));
        render(
            <EndOfShiftChecklist
                session={session}
                open
                onOpenChange={vi.fn()}
            />,
        );
        await screen.findByText(
            'Family work could not be checked. Open the inbox to review current ownership.',
        );
        fireEvent.click(screen.getByRole('button', { name: 'End shift' }));
        expect(router.post).toHaveBeenCalledTimes(1);
        expect(
            screen.queryByText(/No unresolved family/),
        ).not.toBeInTheDocument();
    });
    it('hides the prompt for no owned work and makes no requests when disabled or inactive', async () => {
        const { rerender } = render(
            <ChatShiftHandover actorId={99} enabled={false} active />,
        );
        expect(chatRequest).not.toHaveBeenCalled();
        rerender(<ChatShiftHandover actorId={99} enabled active={false} />);
        expect(chatRequest).not.toHaveBeenCalled();
        vi.mocked(chatRequest).mockResolvedValue(page(0));
        rerender(<ChatShiftHandover actorId={99} enabled active />);
        await waitFor(() => expect(chatRequest).toHaveBeenCalledTimes(1));
        expect(
            screen.queryByRole('region', { name: 'Family handover' }),
        ).not.toBeInTheDocument();
    });
    it('keeps authorised owned work visible when organisation defaults differ from a client site', async () => {
        const result = page();
        vi.mocked(chatRequest).mockResolvedValue({
            ...result,
            settings: {
                ...result.settings,
                enabled: false,
                family_enabled: false,
            },
        });
        render(<ChatShiftHandover actorId={99} enabled active />);
        expect(
            await screen.findByRole('button', {
                name: 'Review family handover',
            }),
        ).toBeEnabled();
    });
    it('clears the open handover and ownership summary when access is revoked', async () => {
        render(<ChatShiftHandover actorId={99} enabled active />);
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Review family handover',
            }),
        );
        await screen.findByRole('button', {
            name: /Jo Example About Jamie Example/,
        });
        vi.mocked(chatRequest).mockRejectedValue(
            new ChatError('Access changed', 403),
        );
        act(() => window.dispatchEvent(new Event('oblivion:chat-refresh')));
        await waitFor(() =>
            expect(screen.queryByText('Jo Example')).not.toBeInTheDocument(),
        );
        expect(
            screen.queryByRole('region', { name: 'Family handover' }),
        ).not.toBeInTheDocument();
    });
    it('ignores a late response from a previous actor', async () => {
        let resolve!: (value: FamilyInboxPage) => void;
        vi.mocked(chatRequest)
            .mockImplementationOnce(
                () =>
                    new Promise((done) => {
                        resolve = done;
                    }),
            )
            .mockResolvedValue(page(0));
        const { rerender } = render(
            <ChatShiftHandover actorId={99} enabled active />,
        );
        rerender(<ChatShiftHandover actorId={100} enabled active />);
        await act(async () => resolve(page(7)));
        expect(
            screen.queryByRole('region', { name: 'Family handover' }),
        ).not.toBeInTheDocument();
    });
});
