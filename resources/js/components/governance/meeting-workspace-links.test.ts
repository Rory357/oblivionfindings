import { describe, expect, it } from 'vitest';

import {
    actionHrefWithReturn,
    meetingWorkspaceUrl,
    withQueryParams,
    withoutQueryParam,
} from './meeting-workspace-links';

describe('meeting workspace links', () => {
    it('addresses a paper inside the resolutions tab', () => {
        expect(meetingWorkspaceUrl(12, { paperId: 40 })).toBe(
            '/governance/meetings/12?tab=resolutions&paper=40',
        );
        expect(meetingWorkspaceUrl(12)).toBe('/governance/meetings/12');
        expect(meetingWorkspaceUrl(12, { tab: 'minutes' })).toBe(
            '/governance/meetings/12?tab=minutes',
        );
    });

    it('opens an action with a relative return to the same paper follow-ups', () => {
        const href = actionHrefWithReturn('/governance/actions/7', 12, 40);
        const [path, query] = href.split('?');

        expect(path).toBe('/governance/actions/7');
        const returnTo = new URLSearchParams(query).get('return');
        expect(returnTo).toBe(
            '/governance/meetings/12?tab=resolutions&paper=40&focus=follow-ups',
        );
        // Same-origin relative governance path — never absolute.
        expect(returnTo?.startsWith('/governance/')).toBe(true);
        expect(returnTo).not.toMatch(/^\/\//);
    });

    it('keeps an existing query string and hash on the action link', () => {
        const href = actionHrefWithReturn(
            '/governance/actions/7?from=home#evidence',
            3,
            9,
        );

        expect(href.endsWith('#evidence')).toBe(true);
        const params = new URLSearchParams(href.split('#')[0].split('?')[1]);
        expect(params.get('from')).toBe('home');
        expect(params.get('return')).toBe(
            '/governance/meetings/3?tab=resolutions&paper=9&focus=follow-ups',
        );
    });

    it('edits query params without touching the rest of the url', () => {
        expect(
            withoutQueryParam(
                '/governance/meetings/3?tab=resolutions&paper=9&focus=follow-ups',
                'focus',
            ),
        ).toBe('/governance/meetings/3?tab=resolutions&paper=9');
        expect(
            withQueryParams('/governance/meetings/3?tab=agenda', {
                tab: 'resolutions',
                paper: '9',
            }),
        ).toBe('/governance/meetings/3?tab=resolutions&paper=9');
        expect(
            withQueryParams('/governance/meetings/3?tab=resolutions&paper=9', {
                paper: null,
            }),
        ).toBe('/governance/meetings/3?tab=resolutions');
    });
});
