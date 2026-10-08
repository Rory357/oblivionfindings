import type { ReactNode } from 'react';
import { renderToString } from 'react-dom/server';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        url: '/sites/3?tab=calendar&calendar_view=week&calendar_date=2020-02-03&calendar_q=Review+medicine&calendar_house=3#calendar',
        props: { auth: { user: { id: 1 }, can: {} } },
    }),
    useForm: (data: unknown) => ({ data, processing: false, errors: {} }),
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    router: {},
}));

import SiteCalendar from './SiteCalendar';

afterEach(() => vi.unstubAllGlobals());

describe('calendar medication return context during server rendering', () => {
    it.each(['page', 'profile'] as const)(
        'renders the %s calendar without browser globals and retains its historical query state',
        (context) => {
            vi.stubGlobal('window', undefined);
            const html = renderToString(
                <SiteCalendar
                    context={context}
                    scope="site"
                    site={{ id: 3, name: 'Synthetic house', type: 'residential' }}
                    canCreate={false}
                />,
            );
            if (context === 'page') {
                expect(html).toContain('Review medicine');
            }
            expect(html).toContain('2 Feb – 8 Feb 2020');
            expect(html).not.toContain('Invalid Date');
        },
    );
});
