import { render, screen } from '@testing-library/react';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import MilesightIntegration from '@/pages/security-devices/integrations/milesight';

vi.mock('@/components/page', () => ({
    PageHero: () => null,
    PageLayout: ({ children }: { children: React.ReactNode }) => (
        <main>{children}</main>
    ),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    router: {
        delete: vi.fn(),
        post: vi.fn(),
    },
    useForm: (initial: Record<string, unknown>) => ({
        data: initial,
        setData: vi.fn(),
        post: vi.fn(),
        processing: false,
        reset: vi.fn(),
    }),
}));

beforeEach(() => {
    vi.clearAllMocks();
});

describe('Milesight integration', () => {
    it('explains the separate verified real-time monitoring path without exposing either secret', () => {
        render(
            <MilesightIntegration
                providerConnection={{
                    status: 'connected',
                    secret_last4: '1234',
                    endpoint_configured: true,
                    client_id_configured: true,
                    webhook_configured: true,
                    webhook_secret_last4: '9876',
                    webhook_url:
                        'https://oblivion.example.test/webhooks/milesight',
                    last_webhook_received_at: '2026-08-03T00:00:00Z',
                }}
                discoveredApplications={[]}
                siteConfigs={[]}
                sites={[]}
                syncLogs={[]}
                siteCredentials={[]}
                can={{ manage: true }}
            />,
        );

        expect(
            screen.getByRole('heading', {
                name: 'Real-time monitoring webhook',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByDisplayValue(
                'https://oblivion.example.test/webhooks/milesight',
            ),
        ).toHaveAttribute('readonly');
        expect(
            screen.getByText('Signature verification enabled'),
        ).toBeInTheDocument();
        expect(screen.getByText(/Webhook secret ending in/)).toHaveTextContent(
            '•••9876',
        );
        expect(
            screen.getByText(/Signed batches are replay-protected/),
        ).toBeInTheDocument();
        expect(screen.queryByText('RAW-MSC-WEBHOOK-SECRET')).toBeNull();
        expect(
            screen.getByLabelText('Replace webhook secret key'),
        ).toHaveAttribute('type', 'password');
    });
});
