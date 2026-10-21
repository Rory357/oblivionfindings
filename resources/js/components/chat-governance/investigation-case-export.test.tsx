import { ChatError, chatFileRequest, chatRequest } from '@/components/chat/api';
import ChatInvestigationView, {
    type InvestigationPage,
} from '@/pages/settings/chat-investigation';
import { fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import { groupByCase, InvestigationAccess } from './investigation-access';
import type { InvestigableConversation, InvestigationGrant } from './types';

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => children,
}));
vi.mock('@/layouts/settings/layout', () => ({
    default: ({ children }: { children: ReactNode }) => children,
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ props: { auth: { user: { id: 7 } } } }),
}));
vi.mock('@/components/chat/api', async (original) => ({
    ...(await original<typeof import('@/components/chat/api')>()),
    chatRequest: vi.fn(),
    chatFileRequest: vi.fn(),
}));

const direct: InvestigableConversation = {
    id: 41,
    type: 'direct',
    title: null,
    members: ['Alex Morgan', 'Sam Lee'],
    created_at: '2026-10-01T00:00:00.000Z',
    held: false,
};
const group: InvestigableConversation = {
    id: 42,
    type: 'group',
    title: 'Night team',
    members: ['Alex Morgan', 'Jo Rangi', 'Sam Lee'],
    created_at: '2026-10-02T00:00:00.000Z',
    held: true,
};
const other: InvestigableConversation = {
    id: 43,
    type: 'direct',
    title: null,
    members: ['Jo Rangi', 'Kim Tane'],
    created_at: '2026-10-03T00:00:00.000Z',
    held: false,
};

const future = new Date(Date.now() + 3_600_000).toISOString();

function grant(
    id: number,
    conversation: InvestigableConversation,
    caseBatch: string | null,
    active = true,
): InvestigationGrant {
    return {
        id,
        case_reference: 'HR-21',
        case_batch: caseBatch,
        conversation,
        expires_at: future,
        revoked_at: null,
        active,
    };
}

beforeEach(() => {
    vi.mocked(chatRequest).mockReset();
    vi.mocked(chatFileRequest).mockReset();
});

it('ticks several conversations across two searches and grants them under one case', async () => {
    vi.mocked(chatRequest).mockImplementation(async (path: string) => {
        if (path.includes('/conversations?q=Alex'))
            return { conversations: [direct, group] };
        if (path.includes('/conversations?q=Kim'))
            return { conversations: [other] };
        if (path.endsWith('/grants'))
            return {
                grant_id: 11,
                grant_ids: [11, 12, 13],
                case_batch: 'b-21',
                url: '/settings/chat/investigations/11',
            };
        return {
            grants: [
                grant(11, direct, 'b-21'),
                grant(12, group, 'b-21'),
                grant(13, other, 'b-21'),
            ],
        };
    });
    render(<InvestigationAccess actorId={7} investigator />);
    fireEvent.change(screen.getByLabelText('Case reference'), {
        target: { value: 'HR-21' },
    });
    fireEvent.change(screen.getByLabelText('Reason for access'), {
        target: { value: 'Complaint covering several chats' },
    });
    const submit = screen.getByRole('button', {
        name: 'Log and open authorised scope',
    }) as HTMLButtonElement;
    expect(submit.disabled).toBe(true);

    const search = screen.getByLabelText('Authorised scope');
    fireEvent.change(search, { target: { value: 'Alex' } });
    fireEvent.click(screen.getByRole('button', { name: /Find/ }));
    fireEvent.click(
        await screen.findByRole('checkbox', { name: /Direct chat · Alex/ }),
    );
    fireEvent.click(
        screen.getByRole('checkbox', { name: /Private group · Night team/ }),
    );
    expect(submit.disabled).toBe(false);

    // A second search keeps the earlier ticks and adds to them.
    fireEvent.change(search, { target: { value: 'Kim' } });
    fireEvent.click(screen.getByRole('button', { name: /Find/ }));
    fireEvent.click(
        await screen.findByRole('checkbox', { name: /Jo Rangi, Kim Tane/ }),
    );
    const selected = screen.getByRole('list', {
        name: 'Conversations selected for this case',
    });
    expect(within(selected).getAllByRole('listitem')).toHaveLength(3);
    expect(screen.getByText('Selected for this case (3)')).toBeTruthy();

    // Removing one from the selection leaves the others.
    fireEvent.click(
        within(selected).getByRole('button', {
            name: /Remove Private group · Night team/,
        }),
    );
    expect(within(selected).getAllByRole('listitem')).toHaveLength(2);
    fireEvent.change(search, { target: { value: 'Alex' } });
    fireEvent.click(screen.getByRole('button', { name: /Find/ }));
    fireEvent.click(
        await screen.findByRole('checkbox', {
            name: /Private group · Night team/,
        }),
    );

    fireEvent.click(submit);
    expect(await screen.findByText(/Case access logged for 3/)).toBeTruthy();
    const call = vi
        .mocked(chatRequest)
        .mock.calls.find(([path]) => String(path).endsWith('/grants'));
    expect(JSON.parse(String(call![1]?.body))).toEqual({
        conversation_ids: [41, 43, 42],
        case_reference: 'HR-21',
        reason: 'Complaint covering several chats',
        duration_hours: 24,
    });
    const cases = await screen.findByRole('list', {
        name: 'Conversations for case HR-21',
    });
    expect(within(cases).getAllByRole('link', { name: 'Open' })).toHaveLength(
        3,
    );
});

it('groups grants made together as one case and keeps single grants apart', () => {
    const groups = groupByCase([
        grant(14, other, null),
        grant(13, group, 'b-21'),
        grant(12, direct, 'b-21'),
    ]);
    expect(groups.map((item) => item.grants.map((g) => g.id))).toEqual([
        [14],
        [12, 13],
    ]);
});

function casePage(
    overrides: Partial<InvestigationPage> = {},
): InvestigationPage {
    return {
        grant: {
            id: 11,
            case_reference: 'HR-21',
            case_batch: 'b-21',
            reason: 'Complaint covering several chats',
            expires_at: future,
        },
        conversation: direct,
        case_conversations: [
            { grant_id: 11, conversation: direct, active: true },
            { grant_id: 12, conversation: group, active: true },
            { grant_id: 13, conversation: other, active: false },
        ],
        holds: [],
        messages: [],
        next_before: null,
        ...overrides,
    };
}

it('lists the case’s conversations and exports a watermarked PDF for the chosen scope', async () => {
    const createObjectURL = vi.fn(() => 'blob:export');
    // jsdom has no object URLs.
    Object.assign(URL, { createObjectURL, revokeObjectURL: vi.fn() });
    const click = vi
        .spyOn(HTMLAnchorElement.prototype, 'click')
        .mockImplementation(() => undefined);
    vi.mocked(chatFileRequest).mockResolvedValue({
        blob: new Blob(['%PDF-1.7']),
        filename: 'chat-investigation-hr-21-20261022-101500.pdf',
        headers: new Headers({ 'X-Export-Sha256': 'abc123' }),
    });
    render(<ChatInvestigationView investigation={casePage()} />);

    const nav = screen.getByRole('navigation', {
        name: 'Conversations in this case',
    });
    expect(
        within(nav)
            .getByRole('link', { name: /Private group · Night team/ })
            .getAttribute('href'),
    ).toBe('/settings/chat/investigations/12');
    expect(within(nav).queryByRole('link', { name: /Kim Tane/ })).toBeNull();
    expect(within(nav).getByText('Access ended')).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: /Export PDF/ }));
    const dialog = await screen.findByRole('dialog');
    expect(
        within(dialog).getByText(/Confidential — investigation record/),
    ).toBeTruthy();
    expect(
        within(dialog).getByText(/attachments are\s+listed by name only/),
    ).toBeTruthy();
    fireEvent.click(
        within(dialog).getByRole('radio', {
            name: 'All 2 conversations still active in this case',
        }),
    );
    fireEvent.click(
        within(dialog).getByRole('button', { name: 'Export and log' }),
    );

    expect(
        await within(dialog).findByText(
            /Exported and logged as chat-investigation-hr-21-20261022-101500\.pdf\. SHA-256 abc123\./,
        ),
    ).toBeTruthy();
    const [path, options] = vi.mocked(chatFileRequest).mock.calls[0];
    expect(path).toBe('/settings/chat/investigations/api/grants/11/export');
    expect(options?.method).toBe('POST');
    expect(JSON.parse(String(options?.body))).toEqual({ scope: 'case' });
    expect(new Headers(options?.headers).get('X-Chat-Actor')).toBe('7');
    expect(createObjectURL).toHaveBeenCalledTimes(1);
    expect(click).toHaveBeenCalledTimes(1);
    click.mockRestore();
});

it('shows the server’s refusal when the export is no longer allowed', async () => {
    vi.mocked(chatFileRequest).mockRejectedValue(
        new ChatError(
            'This case access has expired or was ended. Record a new case reason to continue.',
            403,
        ),
    );
    render(
        <ChatInvestigationView
            investigation={casePage({
                case_conversations: [
                    { grant_id: 11, conversation: direct, active: true },
                ],
            })}
        />,
    );
    // A single-conversation case has no scope choice.
    expect(
        screen.queryByRole('navigation', { name: /in this case/ }),
    ).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: /Export PDF/ }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).queryByRole('radio')).toBeNull();
    fireEvent.click(
        within(dialog).getByRole('button', { name: 'Export and log' }),
    );
    expect((await within(dialog).findByRole('alert')).textContent).toContain(
        'has expired or was ended',
    );
    expect(
        JSON.parse(String(vi.mocked(chatFileRequest).mock.calls[0][1]?.body)),
    ).toEqual({
        scope: 'conversation',
    });
});

it('hides the export once access has expired or is ended', async () => {
    const { unmount } = render(
        <ChatInvestigationView
            investigation={casePage({
                grant: {
                    ...casePage().grant,
                    expires_at: '2026-01-01T00:00:00.000Z',
                },
            })}
        />,
    );
    expect(screen.queryByRole('button', { name: /Export PDF/ })).toBeNull();
    unmount();

    vi.mocked(chatRequest).mockResolvedValue({});
    render(<ChatInvestigationView investigation={casePage()} />);
    expect(screen.getByRole('button', { name: /Export PDF/ })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'End access now' }));
    expect(await screen.findByText(/Case access ended/)).toBeTruthy();
    expect(screen.queryByRole('button', { name: /Export PDF/ })).toBeNull();
    expect(chatFileRequest).not.toHaveBeenCalled();
});
