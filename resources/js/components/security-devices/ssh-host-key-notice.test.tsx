import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    router: { post: mocks.post },
}));

import { SshHostKeyNotice } from './ssh-host-key-notice';

const KEY_ONE = 'SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
const KEY_TWO = 'SHA256:BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';

afterEach(() => {
    cleanup();
    mocks.post.mockReset();
});

describe('SshHostKeyNotice (Phase A, item A6)', () => {
    it('shows nothing when there is no key to check or trust', () => {
        const { container } = render(
            <SshHostKeyNotice
                state={{ trusted: null, offered: null, can_trust: false }}
            />,
        );
        expect(container.textContent).toBe('');
    });

    it('asks to trust a new key, then posts exactly the offered fingerprint', () => {
        render(
            <SshHostKeyNotice
                state={{
                    trusted: null,
                    offered: {
                        fingerprint: KEY_ONE,
                        seen_at: '2026-10-30T09:00:00Z',
                        changed: false,
                    },
                    can_trust: true,
                    trust_url: '/security-devices/devices/7/ssh-host-key/trust',
                }}
            />,
        );

        expect(
            screen.getByText('This device’s SSH key isn’t trusted yet'),
        ).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Trust this key' }));

        const dialog = screen.getByRole('alertdialog');
        expect(dialog.textContent).toContain('Trust this device’s SSH key?');
        expect(dialog.textContent).toContain(KEY_ONE);

        const buttons = Array.from(dialog.querySelectorAll('button')).map((b) =>
            b.textContent?.trim(),
        );
        expect(buttons.indexOf('Cancel')).toBeLessThan(
            buttons.indexOf('Trust this key'),
        );

        fireEvent.click(
            screen.getAllByRole('button', { name: 'Trust this key' }).at(-1)!,
        );
        expect(mocks.post).toHaveBeenCalledWith(
            '/security-devices/devices/7/ssh-host-key/trust',
            { fingerprint: KEY_ONE },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('warns about a changed key and lets only an IT admin replace it', () => {
        const changed = {
            trusted: KEY_ONE,
            offered: {
                fingerprint: KEY_TWO,
                seen_at: '2026-10-30T09:00:00Z',
                changed: true,
            },
            trust_url: '/security-devices/devices/7/ssh-host-key/trust',
        };

        const { rerender } = render(
            <SshHostKeyNotice state={{ ...changed, can_trust: false }} />,
        );
        expect(
            screen.getByText('This device’s SSH key has changed'),
        ).toBeTruthy();
        expect(
            screen.getByText('Only an IT admin can replace a trusted key.'),
        ).toBeTruthy();
        expect(
            screen.queryByRole('button', { name: 'Replace trusted key' }),
        ).toBeNull();

        rerender(<SshHostKeyNotice state={{ ...changed, can_trust: true }} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Replace trusted key' }),
        );
        expect(screen.getByRole('alertdialog').textContent).toContain(
            'Replace the trusted SSH key?',
        );
    });

    it('shows the trusted key quietly when nothing is waiting', () => {
        render(
            <SshHostKeyNotice
                state={{ trusted: KEY_ONE, offered: null, can_trust: false }}
            />,
        );
        expect(screen.getByTestId('ssh-key-trusted').textContent).toContain(
            KEY_ONE,
        );
    });
});
