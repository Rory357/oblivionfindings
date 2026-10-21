import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import type { PropsWithChildren, ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Api from './api';
import ServiceContextsPage from './service-contexts';
import TwoFactor from './two-factor';
import UserShow from './users/show';

// Revoke key, end sessions, turn off two-factor and archive a service context
// must each ask first: nothing reaches the server until the confirm button.

type VisitOptions = { onStart?: () => void; onFinish?: () => void };

const page = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));
const router = vi.hoisted(() => {
    const settle = (options?: VisitOptions) => {
        options?.onStart?.();
        options?.onFinish?.();
    };
    return {
        delete: vi.fn((_url: string, options?: VisitOptions) =>
            settle(options),
        ),
        put: vi.fn((_url: string, _data: unknown, options?: VisitOptions) =>
            settle(options),
        ),
        post: vi.fn((_url: string, _data: unknown, options?: VisitOptions) =>
            settle(options),
        ),
    };
});

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children }: PropsWithChildren) => <>{children}</>,
    Form: ({
        children,
    }: {
        children: (state: { processing: boolean }) => ReactNode;
    }) => <form>{children({ processing: false })}</form>,
    usePage: () => page,
    useForm: <T extends object>(data: T) => ({
        data,
        setData: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        reset: vi.fn(),
        processing: false,
        errors: {},
    }),
    router,
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: PropsWithChildren) => <>{children}</>,
}));
vi.mock('@/layouts/settings/layout', () => ({
    default: ({ children }: PropsWithChildren) => <>{children}</>,
}));
vi.mock('@/components/page', () => ({
    PageHero: ({ actions }: { actions?: ReactNode }) => <>{actions}</>,
    PageLayout: ({
        hero,
        children,
    }: PropsWithChildren<{ hero?: ReactNode }>) => (
        <>
            {hero}
            {children}
        </>
    ),
}));
vi.mock('@/routes/two-factor', () => {
    const route = (url: string) =>
        Object.assign(() => ({ url, method: 'post' }), { url: () => url });
    return {
        disable: route('/user/two-factor-authentication'),
        enable: route('/user/two-factor-authentication'),
        show: route('/settings/two-factor'),
    };
});
vi.mock('@/hooks/use-two-factor-auth', () => ({
    useTwoFactorAuth: () => ({
        qrCodeSvg: null,
        hasSetupData: false,
        manualSetupKey: null,
        clearSetupData: vi.fn(),
        fetchSetupData: vi.fn(),
        recoveryCodesList: [],
        fetchRecoveryCodes: vi.fn(),
        errors: [],
    }),
}));
vi.mock('@/components/two-factor-recovery-codes', () => ({
    default: () => null,
}));
vi.mock('@/components/two-factor-setup-modal', () => ({
    default: () => null,
}));

const dialog = () => screen.getByRole('alertdialog');

beforeEach(() => {
    router.delete.mockClear();
    router.put.mockClear();
    router.post.mockClear();
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('Settings › API: revoke key', () => {
    it('asks before revoking, then revokes the chosen key', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () =>
                    Promise.resolve({
                        message: 'API key revoked.',
                        apiKey: {
                            id: 'k1',
                            name: 'Payroll export',
                            maskedKey: 'ob_****1234',
                            created: '1 Oct 2026',
                            lastUsed: null,
                            status: 'revoked',
                            scopes: [],
                        },
                    }),
            }),
        );
        vi.stubGlobal('fetch', fetchMock);
        page.props = {
            api_keys: [
                {
                    id: 'k1',
                    name: 'Payroll export',
                    maskedKey: 'ob_****1234',
                    created: '1 Oct 2026',
                    lastUsed: null,
                    status: 'active',
                    scopes: [],
                },
            ],
            webhooks: [],
            available_scopes: [],
            available_events: [],
            stats: {
                active_keys: 1,
                revoked_keys: 0,
                active_webhooks: 0,
                successful_tests: 0,
            },
            can: { manage: true },
        };

        render(<Api />);
        fireEvent.click(screen.getByRole('button', { name: 'Revoke' }));

        expect(fetchMock).not.toHaveBeenCalled();
        expect(
            within(dialog()).getByText('Revoke this API key?'),
        ).toBeInTheDocument();
        expect(within(dialog()).getByText('Payroll export')).toBeInTheDocument();

        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Revoke key' }),
        );

        await waitFor(() =>
            expect(fetchMock).toHaveBeenCalledWith(
                '/settings/api/keys/k1/revoke',
                expect.objectContaining({ method: 'POST' }),
            ),
        );
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
    });
});

describe('System › Users: end sessions', () => {
    const user = {
        id: 7,
        name: 'Mere Tane',
        email: 'mere@example.test',
        is_active: true,
        roles: [],
    };
    const sessions = [
        {
            id: 'sess-chrome',
            user_agent:
                'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/120.0 Safari/537.36',
            last_activity: Math.floor(Date.now() / 1000),
        },
        {
            id: 'sess-other',
            user_agent: 'Mozilla/5.0 (Macintosh) Version/17.0 Safari/605.1.15',
            last_activity: Math.floor(Date.now() / 1000),
        },
    ];

    const openSessionsTab = () => {
        page.props = { auth: { user: { id: 1 } } };
        render(<UserShow user={user} active_sessions={sessions} />);
        fireEvent.mouseDown(screen.getByRole('tab', { name: 'Sessions' }));
    };

    it('asks before ending one session, then ends only that session', () => {
        openSessionsTab();
        fireEvent.click(
            screen.getAllByRole('button', { name: 'Terminate' })[0],
        );

        expect(router.delete).not.toHaveBeenCalled();
        expect(
            within(dialog()).getByText('End this session?'),
        ).toBeInTheDocument();
        expect(dialog()).toHaveTextContent('Mere Tane will be signed out of');

        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'End session' }),
        );

        expect(router.delete).toHaveBeenCalledTimes(1);
        expect(router.delete).toHaveBeenCalledWith(
            '/system/users/7/sessions/sess-chrome',
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('asks before ending all sessions, and Cancel ends nothing', () => {
        openSessionsTab();
        fireEvent.click(
            screen.getByRole('button', {
                name: /Terminate All Other Sessions/,
            }),
        );

        expect(
            within(dialog()).getByText('End all sessions for Mere Tane?'),
        ).toBeInTheDocument();
        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Cancel' }),
        );

        expect(router.delete).not.toHaveBeenCalled();
    });

    it('ends all of the sessions once confirmed', async () => {
        openSessionsTab();
        fireEvent.click(
            screen.getByRole('button', {
                name: /Terminate All Other Sessions/,
            }),
        );

        expect(router.delete).not.toHaveBeenCalled();
        expect(dialog()).toHaveTextContent(
            'Mere Tane will be signed out on every device straight away',
        );

        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'End all sessions' }),
        );

        expect(router.delete).toHaveBeenCalledTimes(1);
        expect(router.delete).toHaveBeenCalledWith(
            '/system/users/7/sessions',
            expect.objectContaining({ preserveScroll: true }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
    });

    it('keeps this device signed in when an admin ends their own other sessions', () => {
        page.props = { auth: { user: { id: 7 } } };
        render(<UserShow user={user} active_sessions={sessions} />);
        fireEvent.mouseDown(screen.getByRole('tab', { name: 'Sessions' }));
        fireEvent.click(
            screen.getByRole('button', {
                name: /Terminate All Other Sessions/,
            }),
        );

        expect(
            within(dialog()).getByText('End all your other sessions?'),
        ).toBeInTheDocument();
        expect(dialog()).toHaveTextContent('This device stays signed in.');
        fireEvent.click(
            within(dialog()).getByRole('button', {
                name: 'End other sessions',
            }),
        );

        expect(router.delete).toHaveBeenCalledWith(
            '/system/users/7/sessions',
            expect.anything(),
        );
    });
});

describe('System › Users: suspend and remove role', () => {
    const roles = [
        { id: 3, name: 'manager', label: 'Manager' },
        { id: 4, name: 'support_worker', label: 'Support worker' },
    ];
    const user = {
        id: 7,
        name: 'Mere Tane',
        email: 'mere@example.test',
        is_active: true,
        roles,
    };

    beforeEach(() => {
        page.props = { auth: { user: { id: 1 } } };
    });

    it('asks before suspending, then suspends that user', async () => {
        render(<UserShow user={user} allRoles={roles} />);
        fireEvent.click(screen.getByRole('button', { name: /Suspend/ }));

        expect(router.post).not.toHaveBeenCalled();
        expect(
            within(dialog()).getByText('Suspend Mere Tane?'),
        ).toBeInTheDocument();
        expect(dialog()).toHaveTextContent(
            "can't sign in again until someone approves their account",
        );

        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Suspend user' }),
        );

        expect(router.post).toHaveBeenCalledWith(
            '/system/users/7/suspend',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
    });

    it('asks before removing a role, then keeps every other role', async () => {
        render(<UserShow user={user} allRoles={roles} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Remove role Manager' }),
        );

        expect(router.put).not.toHaveBeenCalled();
        expect(within(dialog()).getByText('Remove this role?')).toBeInTheDocument();
        expect(dialog()).toHaveTextContent(
            'Mere Tane loses the access that comes with Manager',
        );

        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Remove role' }),
        );

        expect(router.put).toHaveBeenCalledWith(
            '/system/users/7',
            { role_ids: [4] },
            expect.objectContaining({ preserveScroll: true }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
    });

    it('keeps the role when the admin cancels', () => {
        render(<UserShow user={user} allRoles={roles} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Remove role Manager' }),
        );
        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Cancel' }),
        );

        expect(router.put).not.toHaveBeenCalled();
    });
});

describe('Settings › Two-factor: turn off', () => {
    it('asks before turning two-factor off, then sends the disable request', async () => {
        page.props = {};
        render(<TwoFactor twoFactorEnabled />);
        fireEvent.click(screen.getByRole('button', { name: /Disable 2FA/ }));

        expect(router.delete).not.toHaveBeenCalled();
        expect(
            within(dialog()).getByText('Turn off two-factor authentication?'),
        ).toBeInTheDocument();

        fireEvent.click(
            within(dialog()).getByRole('button', {
                name: 'Turn off two-factor',
            }),
        );

        expect(router.delete).toHaveBeenCalledWith(
            '/user/two-factor-authentication',
            expect.objectContaining({ preserveScroll: true }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
    });
});

describe('Settings › Service contexts: archive', () => {
    const types = [
        {
            code: 'residential',
            label: 'Residential',
            description: '',
            category: 'Residential',
            colour: 'violet',
        },
    ];
    const context = {
        id: 3,
        type: 'residential',
        name: 'North Shore Residential',
        description: 'Overnight support',
        site_id: 4,
        is_active: true,
    };

    beforeEach(() => {
        page.props = {
            auth: { can: { settings: { manageServiceContexts: true } } },
        };
    });

    it("asks before archiving, then saves that context's own values", async () => {
        render(
            <ServiceContextsPage
                contexts={[context]}
                types={types}
                sites={[]}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Archive North Shore Residential',
            }),
        );

        expect(router.put).not.toHaveBeenCalled();
        expect(
            within(dialog()).getByText('Archive this service context?'),
        ).toBeInTheDocument();

        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Archive context' }),
        );

        expect(router.put).toHaveBeenCalledWith(
            '/settings/service-contexts/3',
            {
                type: 'residential',
                name: 'North Shore Residential',
                description: 'Overnight support',
                site_id: 4,
                is_active: false,
            },
            expect.objectContaining({ preserveScroll: true }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
    });

    it('restores an archived context straight away', () => {
        render(
            <ServiceContextsPage
                contexts={[{ ...context, is_active: false }]}
                types={types}
                sites={[]}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Restore North Shore Residential',
            }),
        );

        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
        expect(router.put).toHaveBeenCalledWith(
            '/settings/service-contexts/3',
            expect.objectContaining({ is_active: true }),
            expect.anything(),
        );
    });
});
