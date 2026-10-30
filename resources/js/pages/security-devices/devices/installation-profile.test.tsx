import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { StrictMode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { InstallationProfileDialog } from './_installation-dialog';
import {
    encodeComputerSetupCode,
    installationJSON,
    installationOptions,
    installationProfile,
    type InstallationOptions,
} from './installation-profile';
vi.mock('./installation-profile', async (importOriginal) => ({
    ...(await importOriginal<typeof import('./installation-profile')>()),
    installationJSON: vi.fn(),
}));
const options: InstallationOptions = {
    actor_id: 12,
    device_id: 34,
    device_label: 'Reception PC',
    device_uid: 'RECEPTION-01',
    site: { id: 56, name: 'Main office' },
    platform: 'windows',
    configured: true,
    server_url: 'https://care.example.nz',
    organisation: 'Pilot Care',
    enrolment_minutes: 15,
    context_hash: 'a'.repeat(64),
    enrolment: { state: 'none', uuid: null, expires_at: null },
};
const profile = () => ({
    version: 1,
    platform: 'windows',
    server_url: options.server_url,
    organisation: options.organisation,
    device_label: options.device_label,
    device_uid: options.device_uid,
    agent_uuid: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    server_public_key: 'A'.repeat(43) + '=',
    enrolment_token: 'b'.repeat(64),
    expires_at: new Date(Date.now() + 30000).toISOString(),
});
const props = { open: true, onClose: vi.fn(), deviceId: 34, actorId: 12 };
beforeEach(() => {
    vi.clearAllMocks();
    vi.spyOn(document, 'hidden', 'get').mockReturnValue(false);
    vi.stubGlobal(
        'URL',
        class extends URL {
            static createObjectURL = vi.fn(() => 'blob:installation-fixture');
            static revokeObjectURL = vi.fn();
        },
    );
    vi.mocked(installationJSON).mockResolvedValue(options);
});
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    vi.useRealTimers();
});
describe('installation profile review', () => {
    it('checks actor device HTTPS and exact issued profile fields', () => {
        expect(installationOptions(options, 34, 12)).toEqual(options);
        for (const value of [
            { ...options, actor_id: 99 },
            { ...options, device_id: 99 },
            { ...options, server_url: 'http://care.example.nz' },
            { ...options, server_url: 'https://care.example.nz/path' },
        ])
            expect(() => installationOptions(value, 34, 12)).toThrow();
        expect(
            installationOptions(
                { ...options, server_url: 'https://CARE.example.nz:443' },
                34,
                12,
            ).configured,
        ).toBe(true);
        const valid = profile();
        expect(installationProfile(valid, options)).toEqual(valid);
        for (const value of [
            { ...valid, device_uid: 'OTHER-PC' },
            { ...valid, server_url: 'https://elsewhere.example.nz' },
            { ...valid, api_key: 'unexpected' },
            { ...valid, expires_at: new Date(Date.now() - 1).toISOString() },
        ])
            expect(() => installationProfile(value, options)).toThrow();
    });
    it('creates only after review and keeps the secret out of rendered text', async () => {
        const issued = profile();
        vi.mocked(installationJSON)
            .mockResolvedValueOnce(options)
            .mockResolvedValueOnce(issued);
        const view = render(<InstallationProfileDialog {...props} />);
        const create = await screen.findByRole('button', {
            name: 'Create installation profile',
        });
        expect(installationJSON).toHaveBeenCalledTimes(1);
        expect(screen.getByText('https://care.example.nz')).toBeVisible();
        fireEvent.click(create);
        fireEvent.click(create);
        const save = await screen.findByRole('link', {
            name: 'Save installation profile',
        });
        expect(save).toHaveAttribute('download', 'oblivion-computer-34.json');
        expect(installationJSON).toHaveBeenCalledTimes(2);
        expect(vi.mocked(installationJSON).mock.calls[1][3]).toEqual({
            expected_context: options.context_hash,
        });
        expect(document.body.textContent).not.toContain(issued.enrolment_token);
        view.unmount();
        expect(URL.revokeObjectURL).toHaveBeenCalledWith(
            'blob:installation-fixture',
        );
    });
    it('clears the offered download when the tab is hidden', async () => {
        vi.mocked(installationJSON)
            .mockResolvedValueOnce(options)
            .mockResolvedValueOnce(profile());
        render(<InstallationProfileDialog {...props} />);
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Create installation profile',
            }),
        );
        await screen.findByRole('link', { name: 'Save installation profile' });
        vi.spyOn(document, 'hidden', 'get').mockReturnValue(true);
        fireEvent(document, new Event('visibilitychange'));
        expect(
            screen.queryByRole('link', { name: 'Save installation profile' }),
        ).not.toBeInTheDocument();
        expect(URL.revokeObjectURL).toHaveBeenCalled();
        expect(
            screen.queryByRole('button', {
                name: 'Create installation profile',
            }),
        ).not.toBeInTheDocument();
    });
    it('does not create a downloadable secret from a late response after close', async () => {
        let resolve!: (v: unknown) => void;
        vi.mocked(installationJSON)
            .mockResolvedValueOnce(options)
            .mockImplementationOnce(
                () =>
                    new Promise((r) => {
                        resolve = r;
                    }),
            );
        const view = render(<InstallationProfileDialog {...props} />);
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Create installation profile',
            }),
        );
        view.unmount();
        await act(async () => resolve(profile()));
        expect(URL.createObjectURL).not.toHaveBeenCalled();
        expect(vi.mocked(installationJSON).mock.calls[1][2].aborted).toBe(true);
    });
    it('requires a status check after an uncertain issue and revokes only the reviewed identity', async () => {
        const issued = profile(),
            pending = {
                ...options,
                enrolment: {
                    state: 'pending' as const,
                    uuid: issued.agent_uuid,
                    expires_at: issued.expires_at,
                },
            };
        vi.mocked(installationJSON)
            .mockResolvedValueOnce(options)
            .mockRejectedValueOnce(Error('Lost response'))
            .mockResolvedValueOnce(pending)
            .mockResolvedValueOnce({ state: 'revoked' })
            .mockResolvedValueOnce({
                ...options,
                enrolment: { ...pending.enrolment, state: 'revoked' },
            });
        render(<InstallationProfileDialog {...props} />);
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Create installation profile',
            }),
        );
        await screen.findByText(/The result could not be confirmed/);
        expect(
            screen.queryByRole('button', {
                name: 'Create installation profile',
            }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Check installation status' }),
        );
        fireEvent.click(
            await screen.findByRole('button', { name: 'Revoke enrolment…' }),
        );
        expect(
            screen.getByRole('button', { name: 'Revoke this enrolment' }),
        ).toBeDisabled();
        fireEvent.change(
            screen.getByLabelText('Reason for revoking this enrolment'),
            { target: { value: 'Profile lost before installation.' } },
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Revoke this enrolment' }),
        );
        await screen.findByRole('button', {
            name: 'Create installation profile',
        });
        expect(vi.mocked(installationJSON).mock.calls[3][3]).toEqual({
            reason: 'Profile lost before installation.',
            expected_agent_uuid: issued.agent_uuid,
        });
    });
    it('discards an aborted initial load under StrictMode and rejects a switched actor', async () => {
        let stale!: (v: unknown) => void;
        vi.mocked(installationJSON)
            .mockImplementationOnce(
                () =>
                    new Promise((r) => {
                        stale = r;
                    }),
            )
            .mockResolvedValueOnce(options);
        const view = render(
            <StrictMode>
                <InstallationProfileDialog {...props} />
            </StrictMode>,
        );
        await screen.findByRole('button', {
            name: 'Create installation profile',
        });
        await act(async () => stale({ ...options, actor_id: 99 }));
        expect(
            screen.getByRole('button', { name: 'Create installation profile' }),
        ).toBeEnabled();
        view.unmount();
        vi.mocked(installationJSON).mockResolvedValue({
            ...options,
            actor_id: 99,
        });
        render(<InstallationProfileDialog {...props} />);
        await waitFor(() =>
            expect(
                screen.getByText(/Installation details could not be loaded/),
            ).toBeVisible(),
        );
        expect(
            screen.queryByRole('button', {
                name: 'Create installation profile',
            }),
        ).not.toBeInTheDocument();
    });
    it('expires the in-memory download without automatically issuing another profile', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        vi.mocked(installationJSON)
            .mockResolvedValueOnce(options)
            .mockResolvedValueOnce(profile());
        render(<InstallationProfileDialog {...props} />);
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Create installation profile',
            }),
        );
        await screen.findByRole('link', { name: 'Save installation profile' });
        act(() => vi.advanceTimersByTime(31000));
        expect(
            screen.queryByRole('link', { name: 'Save installation profile' }),
        ).not.toBeInTheDocument();
        expect(screen.getByText(/This profile has expired/)).toBeVisible();
        expect(installationJSON).toHaveBeenCalledTimes(2);
    });
    it('encodes and copies an ofc1_ one-time setup code without rendering secrets in DOM text', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', {
            value: { writeText },
            configurable: true,
        });
        const issued = {
            ...profile(),
            organisation: 'Kauri Care — Ōtautahi',
            server_url: options.server_url!,
        };
        const expectedCode = encodeComputerSetupCode(issued);
        expect(expectedCode.startsWith('ofc1_')).toBe(true);

        const base64 = expectedCode
            .slice(5)
            .replace(/-/gu, '+')
            .replace(/_/gu, '/');
        const padded = base64 + '='.repeat((4 - (base64.length % 4)) % 4);
        const binary = atob(padded);
        const bytes = Uint8Array.from(binary, (ch) => ch.charCodeAt(0));
        expect(JSON.parse(new TextDecoder().decode(bytes))).toEqual(issued);

        vi.mocked(installationJSON)
            .mockResolvedValueOnce({
                ...options,
                organisation: issued.organisation,
            })
            .mockResolvedValueOnce(issued);
        render(<InstallationProfileDialog {...props} />);
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Create installation profile',
            }),
        );
        const copyButton = await screen.findByRole('button', {
            name: 'Copy one-time setup code',
        });
        expect(document.body.textContent).not.toContain(issued.enrolment_token);
        expect(document.body.textContent).not.toContain(expectedCode);

        fireEvent.click(copyButton);
        await waitFor(() =>
            expect(writeText).toHaveBeenCalledWith(expectedCode),
        );
        expect(
            screen.getByRole('button', { name: 'Setup code copied' }),
        ).toBeVisible();
    });
});
