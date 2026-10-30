import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('axios', async (importOriginal) => {
    const actual = await importOriginal<typeof import('axios')>();
    return { default: { ...actual.default, post: vi.fn() } };
});

import {
    CameraCertificateNotice,
    type CameraCertificateTrust,
} from './camera-certificate-notice';

const FINGERPRINT = Array(32).fill('AB').join(':');

function trust(
    overrides: Partial<CameraCertificateTrust> = {},
): CameraCertificateTrust {
    return {
        trusted_fingerprint: null,
        offered: {
            fingerprint: FINGERPRINT,
            subject: 'UniFi Protect',
            valid_to: '2027-10-30T00:00:00Z',
            seen_at: '2026-10-30T09:00:00Z',
            changed: false,
        },
        can_trust: true,
        host: '172.16.0.10',
        port: 443,
        trust_url: '/security-devices/devices/9/camera/certificate/trust',
        ...overrides,
    };
}

afterEach(() => {
    cleanup();
    vi.mocked(axios.post).mockReset();
});

describe('CameraCertificateNotice (Phase A, item A5)', () => {
    it('asks "Trust this camera?" and posts the offered certificate for this address', async () => {
        vi.mocked(axios.post).mockResolvedValueOnce({
            data: { message: 'Camera trusted. Try again to use it.' },
        });
        const onTrusted = vi.fn();
        render(
            <CameraCertificateNotice trust={trust()} onTrusted={onTrusted} />,
        );

        expect(screen.getByText('This camera isn’t trusted yet')).toBeTruthy();
        fireEvent.click(
            screen.getByRole('button', { name: 'Trust this camera' }),
        );

        const dialog = screen.getByRole('alertdialog');
        expect(dialog.textContent).toContain('Trust this camera?');
        expect(dialog.textContent).toContain(FINGERPRINT);
        expect(dialog.textContent).toContain('172.16.0.10:443');
        // The 95-character fingerprint wraps inside the pop-up instead of widening it.
        expect(
            screen.getByTestId('camera-certificate-confirm-fingerprint')
                .className,
        ).toContain('break-all');

        fireEvent.click(
            screen
                .getAllByRole('button', { name: 'Trust this camera' })
                .at(-1)!,
        );

        await waitFor(() =>
            expect(onTrusted).toHaveBeenCalledWith(
                'Camera trusted. Try again to use it.',
            ),
        );
        expect(axios.post).toHaveBeenCalledWith(
            '/security-devices/devices/9/camera/certificate/trust',
            { host: '172.16.0.10', port: 443, fingerprint: FINGERPRINT },
            expect.any(Object),
        );
    });

    it('tells non-admins that only an IT admin can replace a changed certificate', () => {
        render(
            <CameraCertificateNotice
                trust={trust({
                    trusted_fingerprint: Array(32).fill('CD').join(':'),
                    offered: { ...trust().offered!, changed: true },
                    can_trust: false,
                })}
                onTrusted={vi.fn()}
            />,
        );

        expect(
            screen.getByText('This camera’s certificate has changed'),
        ).toBeTruthy();
        expect(
            screen.getByText(
                'Only an IT admin can replace a trusted certificate.',
            ),
        ).toBeTruthy();
        expect(
            screen.queryByRole('button', { name: /Replace trusted/ }),
        ).toBeNull();
    });

    it('shows nothing when no certificate is waiting', () => {
        const { container } = render(
            <CameraCertificateNotice
                trust={trust({ offered: null })}
                onTrusted={vi.fn()}
            />,
        );
        expect(container.textContent).toBe('');
    });
});
