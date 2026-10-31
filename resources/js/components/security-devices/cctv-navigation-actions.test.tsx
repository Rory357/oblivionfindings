import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { CctvNavigationActions } from './cctv-navigation-actions';

describe('Camera navigation authority', () => {
    it('offers setup independently of viewing and removes it when access changes', () => {
        const { rerender } = render(
            <CctvNavigationActions
                cameraId={42}
                navigation={{
                    live_href: null,
                    setup_href: '/security-devices/devices/42/live/source',
                }}
            />,
        );
        expect(
            screen.queryByRole('link', { name: 'Live view' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Camera setup' }),
        ).toHaveAttribute('href', '/security-devices/devices/42/live/source');
        rerender(
            <CctvNavigationActions
                cameraId={42}
                navigation={{ live_href: null, setup_href: null }}
            />,
        );
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });
    it('rejects observed vendor links, another camera and changed route suffixes', () => {
        const { rerender } = render(
            <CctvNavigationActions
                cameraId={42}
                navigation={{
                    live_href: 'https://camera.example/live',
                    setup_href: '/security-devices/devices/43/live/source',
                }}
            />,
        );
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
        rerender(
            <CctvNavigationActions
                cameraId={42}
                navigation={{
                    live_href: '/security-devices/devices/42/live?start=1',
                    setup_href: '/security-devices/devices/42/live/source#edit',
                }}
            />,
        );
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });
    it('provides a governed live page without granting setup', () => {
        render(
            <CctvNavigationActions
                cameraId={42}
                navigation={{
                    live_href: '/security-devices/devices/42/live',
                    setup_href: null,
                }}
            />,
        );
        expect(screen.getByRole('link', { name: 'Live view' })).toHaveAttribute(
            'href',
            '/security-devices/devices/42/live',
        );
        expect(
            screen.queryByRole('link', { name: 'Camera setup' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('Live', { exact: true }),
        ).not.toBeInTheDocument();
    });
});

describe('Camera navigation inside the page header', () => {
    it('uses the header glass buttons on the dark band', () => {
        render(
            <CctvNavigationActions
                cameraId={42}
                onDark
                navigation={{
                    live_href: '/security-devices/devices/42/live',
                    setup_href: null,
                }}
            />,
        );
        const live = screen.getByRole('link', { name: 'Live view' });
        expect(live).toHaveClass('bg-band-foreground/10');
        expect(live).not.toHaveClass('frontline-hit');
    });
});
