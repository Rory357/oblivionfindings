import { chatRequest } from '@/components/chat/api';
import ChatInvestigationView, {
    type InvestigationPage,
} from '@/pages/settings/chat-investigation';
import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import { InvestigationAccess } from './investigation-access';
import { PortalRelationships } from './portal-relationships';
import { PreservationHolds } from './preservation-holds';
import { SettingsAudit } from './settings-audit';

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
}));

const conversation = {
    id: 41,
    type: 'direct' as const,
    title: null,
    members: ['Alex Morgan', 'Sam Lee'],
    created_at: '2026-10-01T00:00:00.000Z',
    held: false,
};

beforeEach(() => {
    vi.mocked(chatRequest).mockReset();
});

it('tells a settings administrator that investigation needs the restricted role and offers no form', () => {
    render(<InvestigationAccess actorId={7} investigator={false} />);
    expect(
        screen.getByText(/Global settings access alone does not authorise/),
    ).toBeTruthy();
    expect(
        screen.getByText(/does not hold the restricted investigator/),
    ).toBeTruthy();
    expect(screen.queryByLabelText('Case reference')).toBeNull();
    expect(screen.queryByRole('button', { name: /Log and open/ })).toBeNull();
    expect(chatRequest).not.toHaveBeenCalled();
});

it('requires a case, a reason and one conversation before logging bound case access', async () => {
    vi.mocked(chatRequest).mockImplementation(async (path: string) => {
        if (path.includes('/conversations?q='))
            return { conversations: [conversation] };
        if (path.endsWith('/grants'))
            return { grant_id: 9, url: '/settings/chat/investigations/9' };
        return {
            grants: [
                {
                    id: 9,
                    case_reference: 'HR-14',
                    conversation,
                    expires_at: '2026-10-21T00:00:00.000Z',
                    revoked_at: null,
                    active: true,
                },
            ],
        };
    });
    render(<InvestigationAccess actorId={7} investigator />);
    const submit = screen.getByRole('button', {
        name: 'Log and open authorised scope',
    });
    expect((submit as HTMLButtonElement).disabled).toBe(true);
    fireEvent.change(screen.getByLabelText('Case reference'), {
        target: { value: 'HR-14' },
    });
    fireEvent.change(screen.getByLabelText('Reason for access'), {
        target: { value: 'Complaint about night shift messages' },
    });
    expect((submit as HTMLButtonElement).disabled).toBe(true);
    fireEvent.change(screen.getByLabelText('Authorised scope'), {
        target: { value: 'Alex' },
    });
    fireEvent.click(screen.getByRole('button', { name: /Find/ }));
    fireEvent.click(
        await screen.findByRole('button', {
            name: /Direct chat · Alex Morgan, Sam Lee/,
        }),
    );
    expect((submit as HTMLButtonElement).disabled).toBe(false);
    fireEvent.click(submit);
    expect(await screen.findByText(/Case access logged/)).toBeTruthy();
    const call = vi
        .mocked(chatRequest)
        .mock.calls.find(([path]) => String(path).endsWith('/grants'));
    expect(call).toBeTruthy();
    const [, options] = call!;
    expect(new Headers(options?.headers).get('X-Chat-Actor')).toBe('7');
    expect(JSON.parse(String(options?.body))).toEqual({
        conversation_id: 41,
        case_reference: 'HR-14',
        reason: 'Complaint about night shift messages',
        duration_hours: 24,
    });
    expect(
        screen
            .getByRole('link', { name: 'Open the read-only case view' })
            .getAttribute('href'),
    ).toBe('/settings/chat/investigations/9');
    expect(await screen.findByText('Your active case access')).toBeTruthy();
});

it('only places a preservation hold once case authority is confirmed', async () => {
    vi.mocked(chatRequest).mockImplementation(async (path: string) => {
        if (path.includes('/conversations?q='))
            return { conversations: [conversation] };
        return {
            holds: [
                {
                    id: 3,
                    conversation: { ...conversation, held: true },
                    case_reference: 'HS-3',
                    reason: 'Keep for review of the complaint',
                    placed_by: 'Mere Thompson',
                    placed_at: '2026-10-20T00:00:00.000Z',
                    released_by: null,
                    released_at: null,
                    active: true,
                },
            ],
        };
    });
    render(<PreservationHolds actorId={7} investigator />);
    fireEvent.change(screen.getByLabelText('Case reference'), {
        target: { value: 'HS-3' },
    });
    fireEvent.change(screen.getByLabelText('Reason'), {
        target: { value: 'Keep for review of the complaint' },
    });
    fireEvent.change(screen.getByLabelText('Conversation to preserve'), {
        target: { value: 'Alex' },
    });
    fireEvent.click(screen.getByRole('button', { name: /Find/ }));
    fireEvent.click(await screen.findByRole('button', { name: /Direct chat/ }));
    const place = screen.getByRole('button', {
        name: 'Place preservation hold',
    });
    expect((place as HTMLButtonElement).disabled).toBe(true);
    fireEvent.click(
        screen.getByRole('checkbox', {
            name: 'Case authority has been reviewed.',
        }),
    );
    expect((place as HTMLButtonElement).disabled).toBe(false);
    fireEvent.click(place);
    expect(
        await screen.findByText('Preservation hold placed and logged.'),
    ).toBeTruthy();
    expect(screen.getByText('Held')).toBeTruthy();
});

it('lists one client’s approved relationships with links into portal administration and the effective policy', async () => {
    const onViewPolicy = vi.fn();
    vi.mocked(chatRequest).mockImplementation(async (path: string) => {
        if (path.includes('/relationships?q='))
            return {
                clients: [{ id: 5, name: 'Sam Taylor', site: 'Kōwhai House' }],
            };
        return {
            client: {
                id: 5,
                name: 'Sam Taylor',
                site: { id: 2, name: 'Kōwhai House' },
            },
            relatives: [
                {
                    id: 11,
                    name: 'Jo Taylor',
                    relationship: 'mother',
                    status: 'approved',
                    access: 'Own conversations (1); separate from other relatives unless invited',
                },
            ],
            named_workers: [
                {
                    id: 12,
                    name: 'Alex Morgan',
                    access: 'Assigned conversations for Sam',
                },
            ],
            cover: [
                {
                    id: 13,
                    name: 'Mere Thompson',
                    access: 'Disclosed cover conversations',
                },
            ],
            cover_total: 1,
            policy: {
                source: 'site_override',
                scope: '2',
                family_enabled: true,
                family_calls_enabled: false,
                response_expectation: '',
            },
            links: { portal_users: '/clients/5/portal-users' },
        };
    });
    render(<PortalRelationships actorId={7} onViewPolicy={onViewPolicy} />);
    fireEvent.change(screen.getByLabelText('Person supported'), {
        target: { value: 'Sam' },
    });
    fireEvent.click(screen.getByRole('button', { name: /Find/ }));
    fireEvent.click(await screen.findByRole('button', { name: /Sam Taylor/ }));
    expect(await screen.findByText('Sam Taylor · Kōwhai House')).toBeTruthy();
    expect(screen.getByText('Approved family')).toBeTruthy();
    expect(screen.getByText('Named worker')).toBeTruthy();
    expect(screen.getByText('Authorised cover')).toBeTruthy();
    expect(
        screen.getByText(/Kōwhai House overrides \+ organisation defaults/),
    ).toBeTruthy();
    expect(
        screen
            .getByRole('link', { name: /Manage portal relationships/ })
            .getAttribute('href'),
    ).toBe('/clients/5/portal-users');
    expect(
        screen.queryByRole('link', { name: /Family portal settings/ }),
    ).toBeNull();
    fireEvent.click(
        screen.getByRole('button', { name: 'View effective Chat policy' }),
    );
    expect(onViewPolicy).toHaveBeenCalledWith('2');
});

it('shows policy changes in the settings audit without investigation records', async () => {
    vi.mocked(chatRequest).mockResolvedValue({
        entries: [
            {
                id: 1,
                kind: 'policy',
                actor: 'Mere Thompson',
                created_at: '2026-10-15T21:50:00.000Z',
                changes: [
                    { scope: '2', field: 'max_files', before: 5, after: 4 },
                ],
                offboarding: null,
            },
        ],
    });
    render(
        <SettingsAudit actorId={7} sites={[{ id: 2, name: 'Kōwhai House' }]} />,
    );
    expect(
        screen.getByText(/does not grant access to private chat content/),
    ).toBeTruthy();
    expect(await screen.findByText(/Kōwhai House ·/)).toBeTruthy();
    expect(screen.getByText(/: 5 → 4/)).toBeTruthy();
    expect(vi.mocked(chatRequest).mock.calls[0][0]).toBe(
        '/settings/chat/administration/audit',
    );
});

it('renders the investigation view read-only without sending anything on open', () => {
    const page: InvestigationPage = {
        grant: {
            id: 9,
            case_reference: 'HR-14',
            reason: 'Formal complaint',
            expires_at: '2026-10-21T00:00:00.000Z',
        },
        conversation: { ...conversation, held: true },
        holds: [
            {
                id: 3,
                case_reference: 'HS-3',
                active: true,
                placed_at: '2026-10-20T00:00:00.000Z',
            },
        ],
        messages: [
            {
                id: 1,
                sender: 'Alex Morgan',
                content: 'Edited wording',
                created_at: '2026-10-19T00:00:00.000Z',
                edited_at: '2026-10-19T00:05:00.000Z',
                removed_at: null,
                attachments: [],
                previous_versions: [
                    {
                        version: 1,
                        content: 'Original wording',
                        replaced_at: '2026-10-19T00:05:00.000Z',
                    },
                ],
            },
            {
                id: 2,
                sender: 'Sam Lee',
                content: 'Removed later',
                created_at: '2026-10-19T01:00:00.000Z',
                edited_at: null,
                removed_at: '2026-10-19T01:02:00.000Z',
                attachments: [
                    {
                        name: 'roster.pdf',
                        mime_type: 'application/pdf',
                        size_bytes: 10,
                    },
                ],
                previous_versions: [],
            },
        ],
        next_before: null,
    };
    render(<ChatInvestigationView investigation={page} />);
    expect(screen.getByText('Investigation view')).toBeTruthy();
    expect(screen.getByText('Read-only')).toBeTruthy();
    expect(screen.getByText(/Preservation hold · HS-3/)).toBeTruthy();
    expect(screen.getByText(/You are not a participant/)).toBeTruthy();
    expect(screen.getByText(/Removed by sender/)).toBeTruthy();
    expect(screen.getByText('Original wording')).toBeTruthy();
    expect(screen.getByText('roster.pdf')).toBeTruthy();
    expect(screen.queryByRole('textbox')).toBeNull();
    expect(chatRequest).not.toHaveBeenCalled();
});
