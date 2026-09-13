import type {
    ProviderConfiguration,
    ProvisioningConfiguration,
} from '@/components/settings/sso-configuration-form';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { PropsWithChildren, ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import SsoConfig from './sso-config';
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ url: '/settings/sso' }),
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: PropsWithChildren) => <>{children}</>,
}));
vi.mock('@/layouts/settings/layout', () => ({
    default: ({ children }: PropsWithChildren) => <>{children}</>,
}));
vi.mock('@/components/page', () => {
    const passthrough = ({ children }: PropsWithChildren) => <>{children}</>;
    return {
        PageHeader: ({
            title,
            meters,
            rail,
        }: {
            title: string;
            meters: ReactNode;
            rail: ReactNode;
        }) => (
            <header>
                <h1>{title}</h1>
                {meters}
                {rail}
            </header>
        ),
        PageHeaderRail: ({
            items,
            onSelect,
        }: {
            items: { key: string; label: string }[];
            onSelect: (key: string) => void;
        }) => (
            <nav>
                {items.map((item) => (
                    <button key={item.key} onClick={() => onSelect(item.key)}>
                        {item.label}
                    </button>
                ))}
            </nav>
        ),
        PageHeaderFilterSelect: () => null,
        PageHeaderMeterBig: passthrough,
        PageHeaderMeterBlock: passthrough,
        PageHeaderMeterCaption: passthrough,
        PageHeaderMeterDonut: () => null,
        PageHeaderStatusChip: passthrough,
    };
});
vi.mock('@/components/settings/sso-configuration-form', () => ({
    SsoProviderForm: ({
        provider,
        initial,
        onAccessLost,
    }: {
        provider: string;
        initial: { client_id: string };
        onAccessLost: (reason: 'access' | 'session') => void;
    }) => (
        <section>
            {initial.client_id}
            <button onClick={() => onAccessLost('access')}>
                Revoke {provider}
            </button>
            <button onClick={() => onAccessLost('session')}>
                Expire session
            </button>
        </section>
    ),
    SsoProvisioningForm: ({
        onAccessLost,
    }: {
        onAccessLost: (reason: 'access') => void;
    }) => (
        <button onClick={() => onAccessLost('access')}>
            Revoke provisioning
        </button>
    ),
}));
vi.mock('@/components/settings/sso-group-mappings', () => ({
    SsoGroupMappings: ({
        onAccessLost,
    }: {
        onAccessLost: (reason: 'access') => void;
    }) => <button onClick={() => onAccessLost('access')}>Revoke groups</button>,
}));
const provider: ProviderConfiguration = {
    version: 0,
    source: 'deployment',
    saved_at: null,
    client_id: 'synthetic-client',
    directory_id: '',
    domain: 'example.test',
    staff_enabled: false,
    portal_enabled: false,
    secret_source: 'deployment',
    secret_present: false,
    secret_readable: true,
    callback_urls: {
        staff: 'https://app.example.test/auth/google/callback',
        portal: 'https://app.example.test/portal/auth/google/callback',
    },
    checks: {},
    consent_status: 'unverified',
    sign_in_status: 'unverified',
};
const provisioning: ProvisioningConfiguration = {
    version: 0,
    source: 'deployment',
    saved_at: null,
    auto_create_staff: true,
    auto_link_existing: true,
    portal_auto_create: true,
    require_admin_approval: true,
    default_role_name: 'support_worker',
    portal_role_name: 'next_of_kin',
};
function setup() {
    render(
        <SsoConfig
            providers={{
                microsoft: provider,
                google: { ...provider, client_id: 'other-synthetic-client' },
            }}
            provisioning={provisioning}
            roles={[]}
            mappings={[]}
            stats={{ total: 0, microsoft: 0, google: 0 }}
        />,
    );
}
afterEach(cleanup);
describe('whole SSO workspace access loss', () => {
    it.each(['microsoft', 'provisioning', 'groups'])(
        'conceals all tabs and metrics after %s denies current access',
        (section) => {
            setup();
            if (section !== 'microsoft')
                fireEvent.click(
                    screen.getByRole('button', {
                        name:
                            section === 'groups'
                                ? 'Group mapping'
                                : 'Provisioning',
                    }),
                );
            fireEvent.click(
                screen.getByRole('button', { name: `Revoke ${section}` }),
            );
            expect(screen.getByRole('alert')).toHaveTextContent(
                'All settings and mappings are concealed.',
            );
            expect(screen.getByRole('alert')).toHaveFocus();
            expect(
                screen.queryByText('synthetic-client'),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByRole('button', { name: 'Google' }),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByText('Existing role mappings'),
            ).not.toBeInTheDocument();
            expect(
                screen.getByRole('link', {
                    name: 'Reload with current access',
                }),
            ).toHaveAttribute('href', '/settings/sso');
        },
    );
    it('expired session provides a normal separate login and authorized full reload', () => {
        setup();
        fireEvent.click(screen.getByRole('button', { name: 'Expire session' }));
        expect(
            screen.getByRole('link', { name: 'Sign in again' }),
        ).toHaveAttribute('target', '_blank');
        expect(
            screen.queryByRole('button', { name: 'Google' }),
        ).not.toBeInTheDocument();
    });
});
