import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CollectorInstallationDialog } from './collector-installation-dialog';
import {
    issueCollectorSetup,
    loadCollectorSetup,
} from './collector-installation-profile';

vi.mock('./collector-installation-profile', () => ({
    issueCollectorSetup: vi.fn(),
    loadCollectorSetup: vi.fn(),
}));
const review = {
    platform: 'windows' as const,
    site_id: 9,
    site_label: 'Main office',
    deployment_url: 'https://care.example.test',
    server_url: 'https://collectors.example.test',
    organisation: 'Example Care',
    enrolment_minutes: 15,
    configured: true,
    context_hash: 'a'.repeat(64),
};
const profile = () => ({
    version: 1 as const,
    component: 'site' as const,
    platform: 'windows' as const,
    site_id: 9,
    site_label: 'Main office',
    deployment_url: review.deployment_url,
    server_url: review.server_url,
    organisation: 'Example Care',
    server_public_key: 'A'.repeat(43) + '=',
    tls_public_key_pin: 'sha256//' + 'A'.repeat(43) + '=',
    collector_uuid: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    enrolment_token: 'ofc_enrol_' + 'A'.repeat(64),
    expires_at: new Date(Date.now() + 900000).toISOString(),
});
const props = {
    open: true,
    onClose: vi.fn(),
    actorId: 7,
    sites: [{ id: 9, name: 'Main office' }],
};
async function toReview() {
    fireEvent.click(screen.getByRole('button', { name: 'Review setup' }));
    await screen.findByRole('button', { name: 'Create profile' });
}
beforeEach(() => {
    vi.clearAllMocks();
    Object.defineProperty(URL, 'createObjectURL', {
        configurable: true,
        value: vi.fn(() => 'blob:fixture-profile'),
    });
    Object.defineProperty(URL, 'revokeObjectURL', {
        configurable: true,
        value: vi.fn(),
    });
    vi.mocked(loadCollectorSetup).mockResolvedValue(review);
    vi.mocked(issueCollectorSetup).mockResolvedValue(profile());
});
afterEach(() => {
    cleanup();
    vi.useRealTimers();
});
describe('guided Site profile download', () => {
    it('reviews the Site and both servers before issuing once and keeps secrets out of the form', async () => {
        const mounted = render(<CollectorInstallationDialog {...props} />);
        expect(issueCollectorSetup).not.toHaveBeenCalled();
        await toReview();
        expect(
            screen.getByText('https://care.example.test'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('https://collectors.example.test'),
        ).toBeInTheDocument();
        const create = screen.getByRole('button', {
            name: 'Create profile',
        });
        fireEvent.click(create);
        fireEvent.click(create);
        const download = await screen.findByRole('link', {
            name: 'Save installation profile',
        });
        expect(issueCollectorSetup).toHaveBeenCalledTimes(1);
        expect(issueCollectorSetup).toHaveBeenCalledWith(
            review,
            expect.any(AbortSignal),
        );
        expect(download.getAttribute('download')).toBe(
            'oblivion-collector-aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa.json',
        );
        expect(document.body.textContent).not.toContain('ofc_enrol_');
        mounted.unmount();
        expect(URL.revokeObjectURL).toHaveBeenCalledWith(
            'blob:fixture-profile',
        );
    });
    it('requires a new review after an uncertain issue and disables unconfigured setup', async () => {
        vi.mocked(issueCollectorSetup).mockRejectedValueOnce(
            Error('lost reply'),
        );
        render(<CollectorInstallationDialog {...props} />);
        await toReview();
        fireEvent.click(screen.getByRole('button', { name: 'Create profile' }));
        await screen.findByRole('alert');
        expect(
            screen.getByRole('button', { name: 'Create profile' }),
        ).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Review again' }));
        vi.mocked(loadCollectorSetup).mockResolvedValue({
            ...review,
            configured: false,
            deployment_url: null,
            server_url: null,
            organisation: null,
            enrolment_minutes: null,
        });
        await toReview();
        expect(
            screen.getByRole('button', { name: 'Create profile' }),
        ).toBeDisabled();
        expect(
            screen.getByText(/finish configuring the collector server/),
        ).toBeInTheDocument();
    });
    it('ignores a late secret response after close or account change', async () => {
        let resolve!: (value: ReturnType<typeof profile>) => void;
        vi.mocked(issueCollectorSetup).mockImplementation(
            () =>
                new Promise((r) => {
                    resolve = r;
                }),
        );
        const mounted = render(<CollectorInstallationDialog {...props} />);
        await toReview();
        fireEvent.click(screen.getByRole('button', { name: 'Create profile' }));
        mounted.rerender(
            <CollectorInstallationDialog {...props} actorId={8} />,
        );
        await act(async () => resolve(profile()));
        expect(URL.createObjectURL).not.toHaveBeenCalled();
        expect(
            screen.queryByRole('link', { name: 'Save installation profile' }),
        ).not.toBeInTheDocument();
    });
    it('clears a download when its profile expires', async () => {
        render(<CollectorInstallationDialog {...props} />);
        await toReview();
        vi.useFakeTimers();
        vi.mocked(issueCollectorSetup).mockResolvedValue({
            ...profile(),
            expires_at: new Date(Date.now() + 1000).toISOString(),
        });
        await act(async () =>
            fireEvent.click(
                screen.getByRole('button', {
                    name: 'Create profile',
                }),
            ),
        );
        expect(
            screen.getByRole('link', { name: 'Save installation profile' }),
        ).toBeInTheDocument();
        await act(async () => vi.advanceTimersByTimeAsync(1001));
        expect(
            screen.queryByRole('link', { name: 'Save installation profile' }),
        ).not.toBeInTheDocument();
        expect(screen.getByRole('alert')).toHaveTextContent('expired');
        expect(URL.revokeObjectURL).toHaveBeenCalledWith(
            'blob:fixture-profile',
        );
    });
    it('clears the prepared file when hidden and allows an explicit fresh review', async () => {
        render(<CollectorInstallationDialog {...props} />);
        await toReview();
        fireEvent.click(screen.getByRole('button', { name: 'Create profile' }));
        await screen.findByRole('link', { name: 'Save installation profile' });
        const original = Object.getOwnPropertyDescriptor(document, 'hidden');
        Object.defineProperty(document, 'hidden', {
            configurable: true,
            value: true,
        });
        try {
            fireEvent(document, new Event('visibilitychange'));
            await waitFor(() =>
                expect(
                    screen.queryByRole('link', {
                        name: 'Save installation profile',
                    }),
                ).not.toBeInTheDocument(),
            );
            expect(URL.revokeObjectURL).toHaveBeenCalledWith(
                'blob:fixture-profile',
            );
        } finally {
            if (original) Object.defineProperty(document, 'hidden', original);
            else delete (document as unknown as { hidden?: boolean }).hidden;
        }
    });
});
