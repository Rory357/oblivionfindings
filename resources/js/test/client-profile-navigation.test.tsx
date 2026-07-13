import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { User } from 'lucide-react';
import type React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const clientShowHarness = vi.hoisted(() => ({
    pageProps: {} as Record<string, unknown>,
    router: {
        delete: vi.fn(),
        post: vi.fn(),
        push: vi.fn(({ url }: { url: string }) => {
            window.history.pushState(window.history.state, '', url);
        }),
        put: vi.fn(),
        reload: vi.fn(),
        replace: vi.fn(({ url }: { url: string }) => {
            window.history.replaceState(window.history.state, '', url);
        }),
        visit: vi.fn(),
    },
}));

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');

    return {
        Head: () => null,
        Link: ({
            href,
            children,
            ...props
        }: {
            href: string;
            children: React.ReactNode;
        }) => (
            <a href={href} {...props}>
                {children}
            </a>
        ),
        router: clientShowHarness.router,
        useForm: <T,>(initial: T) => ({
            data: initial,
            delete: vi.fn(),
            errors: {},
            patch: vi.fn(),
            post: vi.fn(),
            processing: false,
            put: vi.fn(),
            reset: vi.fn(),
            setData: vi.fn(),
        }),
        usePage: () => ({ props: clientShowHarness.pageProps }),
    };
});

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));

vi.mock('@/components/page-shell', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));

vi.mock('@/components/clients/profile/hero', () => ({
    AlertRibbon: () => null,
    ClientProfileHero: ({
        footer,
        noteCapabilities,
        onChat,
        onEdit,
    }: {
        footer?: React.ReactNode;
        noteCapabilities: {
            dailyNote: boolean;
            quickNote: boolean;
            communicationNote: boolean;
        };
        onChat?: () => void;
        onEdit?: () => void;
    }) => (
        <div data-testid="client-profile-hero">
            {noteCapabilities.dailyNote ? <span>Daily capture</span> : null}
            {noteCapabilities.quickNote ? <span>Quick capture</span> : null}
            {noteCapabilities.communicationNote ? (
                <span>Communication capture</span>
            ) : null}
            {onChat ? (
                // eslint-disable-next-line no-restricted-syntax -- raw test-harness control exposes whether the optional callback was supplied
                <button onClick={onChat}>Family chat action</button>
            ) : null}
            {onEdit ? (
                // eslint-disable-next-line no-restricted-syntax -- raw test-harness control exposes whether the optional callback was supplied
                <button onClick={onEdit}>Edit profile action</button>
            ) : null}
            {footer}
        </div>
    ),
}));

vi.mock('@/components/clients/profile/dialog-host', () => ({
    ProfileDialogs: ({ dialog }: { dialog?: { key?: string } | null }) =>
        dialog?.key ? (
            <div data-testid="profile-dialog">{dialog.key}</div>
        ) : null,
}));

vi.mock('@/components/client-edit-dialog', () => ({
    ClientEditDialog: () => null,
}));

vi.mock('@/pages/operations/clients/dialogs/daily-note-wizard', () => ({
    DailyNoteWizard: ({
        open,
        mode,
        note,
    }: {
        open: boolean;
        mode?: string;
        note?: { id?: number } | null;
    }) =>
        open ? (
            <div
                data-testid={
                    mode === 'communication'
                        ? 'communication-note-dialog'
                        : 'daily-note-dialog'
                }
            >
                {note?.id ? `note-${note.id}` : 'new-note'}
            </div>
        ) : null,
}));

vi.mock('@/pages/operations/clients/dialogs/quick-note-dialog', () => ({
    QuickNoteDialog: ({ open }: { open: boolean }) =>
        open ? <div data-testid="quick-note-dialog" /> : null,
}));

vi.mock(
    '@/pages/operations/shifts/components/use-create-shift-launcher',
    () => ({
        useCreateShiftLauncher: () => ({ dialog: null }),
    }),
);

import {
    GroupPillRail,
    type ProfileNavGroup,
} from '@/components/clients/profile/nav';

import ClientShow from '@/pages/operations/clients/show';
import * as profileNavigation from '@/pages/operations/clients/tabs/_groups';
import {
    CLIENT_TAB_GROUPS,
    groupForTab,
} from '@/pages/operations/clients/tabs/_groups';

function clientShowProps(): React.ComponentProps<typeof ClientShow> {
    return {
        profile_section_access: {
            notes: true,
            timeline: true,
            care_plans: true,
            assessments: true,
            behaviour: true,
            medical: true,
            health: true,
            finance: true,
            consents: true,
            risks: true,
            incidents: true,
            first_aid: true,
            calendar: true,
            documents: true,
            portal_access: true,
            audit: true,
            privacy: true,
            respite: true,
        },
        client: {
            id: 1,
            first_name: 'Tane',
            last_name: 'Rangi',
            status: 'active',
            site: { id: 10, name: 'Kauri House' },
            support_workers: [],
        },
        support_plan: null,
        assessments: [],
        photos: [],
        personal_assets: [],
        events: [],
        handover: [],
        onboarding: {
            items: [],
            completed: 0,
            total: 0,
            percent: 0,
            status: 'incomplete',
        },
        can: {
            edit: false,
            assign_workers: false,
        },
    };
}

function setClientShowPageProps() {
    clientShowHarness.pageProps = {
        auth: {
            can: {
                calendar: { view: true, create: false, manage: false },
                progress_notes: {},
            },
            user: { id: 7, name: 'Support Worker' },
        },
        labels: {},
        client_daily_notes: [],
        daily_notes_summary: {},
        communication_notes: [],
        health_monitoring: {},
        client_routines: [],
        actions_reviews: [],
        actions_reviews_summary: {},
        client_agreements: [],
        family_notes: [],
        care_plans_summary: {},
        client_risks: [],
        client_incidents: [],
        first_aid_records: [],
    };
}

beforeEach(() => {
    setClientShowPageProps();
    window.localStorage.clear();
});

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});

describe('client profile navigation registry', () => {
    it('places Privacy in governance and keeps the legacy support-plan deep link in Plans & goals', () => {
        const governance = CLIENT_TAB_GROUPS.find(
            (group) => group.key === 'governance',
        );

        expect(governance?.tabKeys).toContain('privacy');
        expect(groupForTab('privacy')).toBe('governance');
        expect(groupForTab('support_plan')).toBe('plans');
    });

    it('selects the remembered visible subtab when a hero group is reopened', async () => {
        const groups: ProfileNavGroup[] = [
            {
                key: 'snapshot',
                label: 'Snapshot',
                icon: User,
                tabs: [
                    { key: 'profile', label: 'Overview', icon: User },
                    {
                        key: 'personal_details',
                        label: 'Personal details',
                        icon: User,
                    },
                ],
            },
            {
                key: 'daily',
                label: 'Daily care',
                icon: User,
                tabs: [
                    {
                        key: 'progress_notes',
                        label: 'Daily notes',
                        icon: User,
                    },
                    { key: 'timeline', label: 'Timeline', icon: User },
                ],
            },
        ];
        const onOpenGroup = vi.fn();
        const props = { groups, onOpenGroup, onSearch: vi.fn() };
        const { rerender } = render(
            <GroupPillRail {...props} openGroup="daily" activeTab="timeline" />,
        );

        rerender(
            <GroupPillRail
                {...props}
                openGroup="snapshot"
                activeTab="profile"
            />,
        );
        await screen.getByRole('button', { name: 'Daily care' }).click();

        expect(onOpenGroup).toHaveBeenCalledWith('daily', 'timeline');
    });

    it('normalizes legacy tabs and pushes tab changes into browser history', () => {
        const navigation = profileNavigation as typeof profileNavigation & {
            canonicalProfileTab?: (tab: string) => string;
            updateClientProfileQuery?: (
                values: Record<string, string | null>,
                mode?: 'push' | 'replace',
            ) => void;
        };

        expect(navigation.canonicalProfileTab).toBeTypeOf('function');
        expect(navigation.updateClientProfileQuery).toBeTypeOf('function');
        if (
            !navigation.canonicalProfileTab ||
            !navigation.updateClientProfileQuery
        ) {
            return;
        }

        window.history.replaceState(
            {},
            '',
            '/operations/clients/1?tab=support_plan',
        );
        const before = window.history.length;

        expect(navigation.canonicalProfileTab('support_plan')).toBe(
            'care_plans',
        );
        navigation.updateClientProfileQuery({ tab: 'timeline' }, 'push');

        expect(window.location.search).toBe('?tab=timeline');
        expect(window.history.length).toBe(before + 1);
    });

    it('falls back to a visible tab in the requested group when permissions hide the deep link', () => {
        const navigation = profileNavigation as typeof profileNavigation & {
            resolveVisibleProfileTab?: (
                requestedTab: string,
                groups: Array<{
                    key: string;
                    tabs: Array<{ key: string }>;
                }>,
            ) => string;
        };

        expect(navigation.resolveVisibleProfileTab).toBeTypeOf('function');
        if (!navigation.resolveVisibleProfileTab) return;

        expect(
            navigation.resolveVisibleProfileTab('privacy', [
                { key: 'snapshot', tabs: [{ key: 'profile' }] },
                {
                    key: 'governance',
                    tabs: [{ key: 'family_tree' }, { key: 'audit_history' }],
                },
            ]),
        ).toBe('family_tree');
    });

    it('round-trips profile dialog and record state through the URL', () => {
        const navigation = profileNavigation as typeof profileNavigation & {
            profileDialogFromSearch?: (search: string) => {
                key: string;
                ctx?: { recordId?: number };
            } | null;
            profileDialogQuery?: (
                key: string,
                context?: Record<string, unknown>,
            ) => Record<string, string | null>;
        };

        expect(navigation.profileDialogFromSearch).toBeTypeOf('function');
        expect(navigation.profileDialogQuery).toBeTypeOf('function');
        if (
            !navigation.profileDialogFromSearch ||
            !navigation.profileDialogQuery
        ) {
            return;
        }

        expect(
            navigation.profileDialogFromSearch(
                '?tab=documents&dialog=document&record=123',
            ),
        ).toEqual({ key: 'document', ctx: { recordId: 123 } });
        expect(navigation.profileDialogFromSearch('?tab=documents')).toBeNull();
        expect(
            navigation.profileDialogQuery('goal', { goal: { id: 9 } }),
        ).toEqual({ dialog: 'goal', record: '9' });
        expect(
            navigation.profileDialogQuery('emar', { medicationId: 12 }),
        ).toEqual({ dialog: 'emar', record: '12' });
    });

    it('hydrates fetch-backed record deep links into the matching dialog context', () => {
        const navigation = profileNavigation as typeof profileNavigation & {
            profileDialogStateFromSearch?: (
                search: string,
                sources?: Record<string, unknown>,
            ) => {
                key: string;
                ctx?: Record<string, unknown>;
            } | null;
        };

        expect(navigation.profileDialogStateFromSearch).toBeTypeOf('function');
        if (!navigation.profileDialogStateFromSearch) return;

        expect(
            navigation.profileDialogStateFromSearch('?dialog=goal&record=9'),
        ).toEqual({
            key: 'goal',
            ctx: { recordId: 9, goal: { id: 9 } },
        });
        expect(
            navigation.profileDialogStateFromSearch('?dialog=abc&record=14'),
        ).toEqual({
            key: 'abc',
            ctx: { recordId: 14, entry: { id: 14 } },
        });
        expect(
            navigation.profileDialogStateFromSearch('?dialog=emar&record=12'),
        ).toEqual({
            key: 'emar',
            ctx: { recordId: 12, medicationId: 12 },
        });
    });

    it('hydrates collection-backed edit dialogs and fails closed for an unknown record', () => {
        const navigation = profileNavigation as typeof profileNavigation & {
            profileDialogStateFromSearch?: (
                search: string,
                sources?: {
                    carePlans?: Array<Record<string, unknown>>;
                    risks?: Array<Record<string, unknown>>;
                    carePlanContext?: Record<string, unknown>;
                },
            ) => {
                key: string;
                ctx?: Record<string, unknown>;
            } | null;
        };

        expect(navigation.profileDialogStateFromSearch).toBeTypeOf('function');
        if (!navigation.profileDialogStateFromSearch) return;

        const plan = { id: 21, title: 'My support plan' };
        const risk = { id: 33, label: 'Falls' };
        const serviceAgreementOptions = [
            { value: '4', label: 'Community support' },
        ];

        expect(
            navigation.profileDialogStateFromSearch(
                '?dialog=care_plan&record=21',
                {
                    carePlans: [plan],
                    carePlanContext: { serviceAgreementOptions },
                },
            ),
        ).toEqual({
            key: 'care_plan',
            ctx: {
                recordId: 21,
                plan,
                serviceAgreementOptions,
            },
        });
        expect(
            navigation.profileDialogStateFromSearch(
                '?dialog=edit_risk&record=33',
                { risks: [risk] },
            ),
        ).toEqual({
            key: 'edit_risk',
            ctx: { recordId: 33, risk },
        });
        expect(
            navigation.profileDialogStateFromSearch(
                '?dialog=edit_risk&record=999',
                { risks: [risk] },
            ),
        ).toBeNull();
    });

    it('clears dialog state without dropping the active profile tab', () => {
        window.history.replaceState(
            {},
            '',
            '/operations/clients/1?tab=goals_path&dialog=goal&record=9',
        );

        profileNavigation.updateClientProfileQuery(
            { dialog: null, record: null },
            'replace',
        );

        expect(window.location.search).toBe('?tab=goals_path');
    });

    it('keeps the real client profile group, subtab, content, URL, and browser history in sync', async () => {
        window.history.replaceState(
            {},
            '',
            '/operations/clients/1?tab=personal_details',
        );
        const { container } = render(<ClientShow {...clientShowProps()} />);

        expect(
            screen.getByRole('button', { name: 'Snapshot' }),
        ).toHaveAttribute('aria-pressed', 'true');
        expect(
            container.querySelector(
                '[data-test="client-personal-details-tab"]',
            ),
        ).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Daily care' }));

        await waitFor(() => {
            expect(window.location.search).toBe('?tab=progress_notes');
        });
        expect(
            screen.getByRole('button', { name: 'Daily care' }),
        ).toHaveAttribute('aria-pressed', 'true');
        expect(
            container.querySelector('[data-test="client-daily-notes-tab"]'),
        ).toBeInTheDocument();

        fireEvent.click(
            container.querySelector(
                '[data-test="client-tab-timeline"]',
            ) as HTMLElement,
        );

        await waitFor(() => {
            expect(window.location.search).toBe('?tab=timeline');
            expect(
                screen.getByPlaceholderText('Search events...'),
            ).toBeVisible();
        });

        fireEvent.click(screen.getByRole('button', { name: 'Snapshot' }));

        await waitFor(() => {
            expect(window.location.search).toBe('?tab=personal_details');
            expect(
                container.querySelector(
                    '[data-test="client-personal-details-tab"]',
                ),
            ).toBeInTheDocument();
        });

        act(() => window.history.back());
        await waitFor(() => {
            expect(window.location.search).toBe('?tab=timeline');
            expect(
                screen.getByPlaceholderText('Search events...'),
            ).toBeVisible();
        });

        act(() => window.history.back());
        await waitFor(() => {
            expect(window.location.search).toBe('?tab=progress_notes');
            expect(
                container.querySelector('[data-test="client-daily-notes-tab"]'),
            ).toBeInTheDocument();
        });

        act(() => window.history.forward());
        await waitFor(() => {
            expect(window.location.search).toBe('?tab=timeline');
            expect(
                screen.getByPlaceholderText('Search events...'),
            ).toBeVisible();
        });
    });

    it('uses the canonical active tab in recent-client links from the real client profile', async () => {
        window.localStorage.setItem(
            'recentClients',
            JSON.stringify([
                {
                    id: 2,
                    name: 'Ria Test',
                    photo: null,
                    house: 'Rimu House',
                },
            ]),
        );
        window.history.replaceState(
            {},
            '',
            '/operations/clients/1?tab=support_plan',
        );

        render(<ClientShow {...clientShowProps()} />);

        await waitFor(() => {
            expect(window.location.search).toBe('?tab=care_plans');
        });
        expect(await screen.findByTitle('Ria Test')).toHaveAttribute(
            'href',
            '/operations/clients/2?tab=care_plans',
        );
    });

    it('canonicalizes a legacy tab through Inertia replace while preserving the complete query', async () => {
        window.history.replaceState(
            { encryptedPage: 'preserve-me' },
            '',
            '/operations/clients/1?tab=support_plan&dialog=quick_note&record=99&source=legacy',
        );

        render(<ClientShow {...clientShowProps()} />);

        await waitFor(() => {
            expect(clientShowHarness.router.replace).toHaveBeenCalledWith({
                url: '/operations/clients/1?tab=care_plans&dialog=quick_note&record=99&source=legacy',
                preserveScroll: true,
                preserveState: true,
            });
        });
        expect(window.history.state).toEqual({
            encryptedPage: 'preserve-me',
        });
    });

    it('keeps family-note lifecycle actions hidden until manage access is explicit', async () => {
        window.history.replaceState(
            {},
            '',
            '/operations/clients/1?tab=family_notes',
        );
        clientShowHarness.pageProps.family_notes = [
            {
                id: 41,
                title: 'Restricted family request',
                description: 'Please follow up with whānau.',
                note_type: 'note',
                priority: 'normal',
                status: 'open',
                creator_name: 'Family member',
                created_at: '2026-07-10T08:00:00+12:00',
                is_overdue: false,
            },
        ];
        const readOnlyProps = clientShowProps();
        readOnlyProps.profile_section_access = {
            ...readOnlyProps.profile_section_access,
            family_notes: true,
        };
        readOnlyProps.can.manage_family_notes = false;
        const { rerender } = render(<ClientShow {...readOnlyProps} />);

        expect(
            await screen.findByText('Restricted family request'),
        ).toBeVisible();
        expect(screen.queryByRole('button', { name: 'Done' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Start' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Reply' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Shift' })).toBeNull();

        const managerProps = clientShowProps();
        managerProps.profile_section_access = {
            ...managerProps.profile_section_access,
            family_notes: true,
        };
        managerProps.can.manage_family_notes = true;
        rerender(<ClientShow {...managerProps} />);

        expect(screen.getByRole('button', { name: 'Done' })).toBeVisible();
        expect(screen.getByRole('button', { name: 'Start' })).toBeVisible();
        expect(screen.getByRole('button', { name: 'Reply' })).toBeVisible();
        expect(screen.getByRole('button', { name: 'Shift' })).toBeVisible();
    });

    it('shows the agreement creation action only with its exact capability', async () => {
        window.history.replaceState(
            {},
            '',
            '/operations/clients/1?tab=service_agreements',
        );
        const restricted = clientShowProps();
        const { rerender } = render(<ClientShow {...restricted} />);

        expect(await screen.findByText('No Service Agreements')).toBeVisible();
        expect(
            screen.queryByRole('link', { name: 'New Agreement' }),
        ).toBeNull();

        (
            clientShowHarness.pageProps.auth as {
                can: Record<string, unknown>;
            }
        ).can.service_agreements = { create: true };
        rerender(<ClientShow {...clientShowProps()} />);

        expect(
            screen.getByRole('link', { name: 'New Agreement' }),
        ).toHaveAttribute(
            'href',
            '/operations/service-agreements/create?client_id=1',
        );
    });

    it('fails restored dialogs closed and exposes only exact hero capture actions', async () => {
        window.history.replaceState(
            {},
            '',
            '/operations/clients/1?dialog=family_chat',
        );
        const restricted = clientShowProps();
        const first = render(<ClientShow {...restricted} />);

        expect(screen.queryByTestId('profile-dialog')).toBeNull();
        expect(screen.queryByText('Daily capture')).toBeNull();
        expect(screen.queryByText('Quick capture')).toBeNull();
        expect(screen.queryByText('Communication capture')).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'Family chat action' }),
        ).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'Edit profile action' }),
        ).toBeNull();

        first.unmount();
        window.history.replaceState(
            {},
            '',
            '/operations/clients/1?dialog=family_chat',
        );
        const exact = clientShowProps();
        exact.can.view_family_chat = true;
        exact.can.create_daily_note = true;
        exact.can.create_communication_note = true;
        render(<ClientShow {...exact} />);

        expect(await screen.findByTestId('profile-dialog')).toHaveTextContent(
            'family_chat',
        );
        expect(screen.getByText('Daily capture')).toBeVisible();
        expect(screen.queryByText('Quick capture')).toBeNull();
        expect(screen.getByText('Communication capture')).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Family chat action' }),
        ).toBeVisible();
        expect(
            screen.queryByRole('button', { name: 'Edit profile action' }),
        ).toBeNull();
    });

    it('opens N and Shift+N capture dialogs only with their matching capabilities', async () => {
        window.history.replaceState({}, '', '/operations/clients/1');
        const quickOnly = clientShowProps();
        quickOnly.can.create_quick_note = true;
        const first = render(<ClientShow {...quickOnly} />);

        fireEvent.keyDown(window, { key: 'n' });
        expect(await screen.findByTestId('quick-note-dialog')).toBeVisible();
        expect(screen.queryByTestId('daily-note-dialog')).toBeNull();

        first.unmount();
        window.history.replaceState({}, '', '/operations/clients/1');
        const dailyOnly = clientShowProps();
        dailyOnly.can.create_daily_note = true;
        render(<ClientShow {...dailyOnly} />);

        fireEvent.keyDown(window, { key: 'N', shiftKey: true });
        expect(await screen.findByTestId('daily-note-dialog')).toBeVisible();
        expect(screen.queryByTestId('quick-note-dialog')).toBeNull();
    });

    it('opens an editable draft in the daily note wizard and preserves its record URL', async () => {
        window.history.replaceState(
            {},
            '',
            '/operations/clients/1?tab=progress_notes',
        );
        clientShowHarness.pageProps.client_daily_notes = [
            {
                id: 73,
                type: 'daily_note',
                category: 'activity',
                subject: 'Pool visit',
                body: 'Draft detail',
                is_draft: true,
                can: { update: true, delete: true },
                author: { id: 7, name: 'Support Worker' },
            },
        ];
        clientShowHarness.pageProps.daily_notes_summary = {
            total: 1,
            drafts: 1,
        };
        const props = clientShowProps();
        props.can.create_daily_note = true;

        render(<ClientShow {...props} />);
        fireEvent.click(
            (
                await screen.findAllByRole('button', {
                    name: 'Resume draft',
                })
            )[0],
        );

        expect(
            await screen.findByTestId('daily-note-dialog'),
        ).toHaveTextContent('note-73');
        expect(window.location.search).toContain('dialog=daily_note');
        expect(window.location.search).toContain('record=73');
    });
});
