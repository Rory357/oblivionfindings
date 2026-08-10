import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import {
    CollectorEnrollmentDialog,
    CollectorRevocationDialog,
    type CollectorLifecycleTarget,
} from '@/components/security-devices/collector-lifecycle-dialogs';

vi.mock('axios');

const site = { id: 14, name: 'Remote Coast Site' };
const revokedCollector: CollectorLifecycleTarget = {
    id: 91,
    name: 'Remote coast collector',
    site,
    revoke_url: null,
    re_enrol_url: '/security-devices/discovery/collectors/91/re-enrolment',
};
const activeCollector: CollectorLifecycleTarget = {
    ...revokedCollector,
    revoke_url: '/security-devices/discovery/collectors/91/revoke',
    re_enrol_url: null,
};

beforeEach(() => {
    vi.clearAllMocks();
    Object.defineProperty(navigator, 'clipboard', {
        configurable: true,
        value: { writeText: vi.fn().mockResolvedValue(undefined) },
    });
});

describe('remote collector lifecycle dialogs', () => {
    it('returns a new Site-scoped token once and clears it on close', async () => {
        vi.mocked(axios.post).mockResolvedValue({
            data: {
                enrollment: {
                    id: 55,
                    purpose: 'new_collector',
                    token: 'ofc_enrol_one-time-sentinel',
                    expires_at: '2026-08-06T10:15:00Z',
                },
            },
        });
        const onOpenChange = vi.fn();
        const onIssued = vi.fn();

        render(
            <CollectorEnrollmentDialog
                open
                onOpenChange={onOpenChange}
                issueUrl="/security-devices/discovery/collectors/enrolments"
                sites={[site]}
                replacement={null}
                onIssued={onIssued}
            />,
        );

        await waitFor(() => {
            expect(
                screen.getByRole('button', {
                    name: 'Issue one-time token',
                }),
            ).toBeEnabled();
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Issue one-time token' }),
        );

        await waitFor(() => {
            expect(
                screen.getByDisplayValue('ofc_enrol_one-time-sentinel'),
            ).toBeInTheDocument();
        });
        expect(axios.post).toHaveBeenCalledWith(
            '/security-devices/discovery/collectors/enrolments',
            { site_id: 14 },
        );
        expect(onIssued).toHaveBeenCalledOnce();
        expect(
            screen.getByText(/not retained after this dialog closes/i),
        ).toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', { name: 'Close and clear token' }),
        );
        expect(
            screen.queryByDisplayValue('ofc_enrol_one-time-sentinel'),
        ).not.toBeInTheDocument();
        expect(onOpenChange).toHaveBeenCalledWith(false);
    });

    it('issues replacement material only through the revoked collector route', async () => {
        vi.mocked(axios.post).mockResolvedValue({
            data: {
                enrollment: {
                    id: 56,
                    purpose: 'collector_re_enrolment',
                    token: 'ofc_enrol_replacement-sentinel',
                    expires_at: '2026-08-06T10:15:00Z',
                },
            },
        });

        render(
            <CollectorEnrollmentDialog
                open
                onOpenChange={vi.fn()}
                issueUrl="/security-devices/discovery/collectors/enrolments"
                sites={[site]}
                replacement={revokedCollector}
                onIssued={vi.fn()}
            />,
        );

        expect(
            screen.getByText(
                /former certificate and signing key remain revoked/i,
            ),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Issue one-time token' }),
        );

        await waitFor(() => {
            expect(axios.post).toHaveBeenCalledWith(
                revokedCollector.re_enrol_url,
                {},
            );
        });
    });

    it('requires an audited operational reason before revocation', async () => {
        vi.mocked(axios.post).mockResolvedValue({ data: {} });
        const onRevoked = vi.fn();

        render(
            <CollectorRevocationDialog
                open
                onOpenChange={vi.fn()}
                collector={activeCollector}
                onRevoked={onRevoked}
            />,
        );

        const submit = screen.getByRole('button', {
            name: 'Revoke collector',
        });
        expect(submit).toBeDisabled();
        fireEvent.change(screen.getByLabelText('Operational reason'), {
            target: {
                value: 'Remote host replacement after confirmed disk failure.',
            },
        });
        expect(submit).toBeEnabled();
        fireEvent.click(submit);

        await waitFor(() => {
            expect(axios.post).toHaveBeenCalledWith(
                activeCollector.revoke_url,
                {
                    reason: 'Remote host replacement after confirmed disk failure.',
                },
            );
        });
        expect(onRevoked).toHaveBeenCalledOnce();
    });
});
