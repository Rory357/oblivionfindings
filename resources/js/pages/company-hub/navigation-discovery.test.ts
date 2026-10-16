import { describe, expect, it } from 'vitest';
import { navigationDiscovery } from './_navigation-discovery';
import type { HubUpdate } from './_types';

const update: HubUpdate = {
    key: 'page:42',
    type: 'news',
    title: 'Our story',
    summary: '',
    owner: 'Company team',
    at: '',
    url: '/company-hub/read/our-story',
    page_id: 42,
    topics: [],
    teams: [],
    unread: true,
    following: false,
};
describe('Navigation discovery', () => {
    it('shows new activity only on the matching destination and clears after it is seen', () => {
        expect(
            navigationDiscovery({ type: 'view', target: 'news' }, [update])
                .unread,
        ).toBe(true);
        expect(
            navigationDiscovery({ type: 'view', target: 'events' }, [update])
                .unread,
        ).toBe(false);
        expect(
            navigationDiscovery({ type: 'view', target: 'following' }, [update])
                .unread,
        ).toBe(false);
        expect(
            navigationDiscovery({ type: 'page', target: '42' }, [update])
                .unread,
        ).toBe(true);
        expect(
            navigationDiscovery({ type: 'page', target: '43' }, [update])
                .unread,
        ).toBe(false);
        expect(
            navigationDiscovery({ type: 'view', target: 'news' }, [
                { ...update, unread: false },
            ]).unread,
        ).toBe(false);
    });
    it('keeps custom links and groups independent of activity view names', () => {
        expect(
            navigationDiscovery({ type: 'link', target: 'news' }, [update])
                .unread,
        ).toBe(false);
        expect(
            navigationDiscovery({ type: 'group', target: '' }, [update])
                .description,
        ).toContain('collection');
    });
});
