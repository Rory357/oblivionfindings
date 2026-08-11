import { afterEach, expect, it, vi } from 'vitest';

afterEach(() => {
    vi.unstubAllGlobals();
});

it('renders a deterministic desktop snapshot without browser globals during SSR', async () => {
    vi.stubGlobal('window', undefined);
    vi.resetModules();

    const React = await import('react');
    const { renderToString } = await import('react-dom/server');
    const { useIsMobile } = await import('@/hooks/use-mobile');

    function MobileStateProbe() {
        return React.createElement(
            'span',
            null,
            useIsMobile() ? 'mobile' : 'desktop',
        );
    }

    expect(renderToString(React.createElement(MobileStateProbe))).toContain(
        'desktop',
    );
});
