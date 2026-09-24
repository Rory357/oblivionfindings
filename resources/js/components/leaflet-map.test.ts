import { describe, expect, it } from 'vitest';

import {
    escapeMapHtml,
    getMapMarkerColor,
    mapMarkerPopupHtml,
    mapMarkerStatsHtml,
    type MapMarker,
} from './leaflet-map';

const marker = (attributes: Partial<MapMarker> = {}): MapMarker => ({
    id: 1,
    lat: -41.2866,
    lng: 174.7756,
    ...attributes,
});

describe('Leaflet map presentation safety', () => {
    it('escapes provider and user supplied marker content before binding HTML', () => {
        const html = mapMarkerPopupHtml(
            marker({
                title: '<img src=x onerror=alert(1)>',
                popup: '<script>throw new Error("unsafe")</script>',
            }),
        );

        expect(html).not.toContain('<img');
        expect(html).not.toContain('<script>');
        expect(html).toContain('&lt;img src=x onerror=alert(1)&gt;');
        expect(html).toContain(
            '&lt;script&gt;throw new Error(&quot;unsafe&quot;)&lt;/script&gt;',
        );
        expect(escapeMapHtml("A&B's <zone>")).toBe(
            'A&amp;B&#039;s &lt;zone&gt;',
        );
    });

    it('escapes every label and value in the hover stats card', () => {
        const html = mapMarkerStatsHtml(
            marker({
                title: 'Point <b>3</b>',
                popup: '<img src=x onerror=alert(1)>',
                stats: [
                    ['Speed', '<script>1</script>'],
                    ['<i>Other</i>', 'Not reported'],
                ],
            }),
        );

        expect(html).not.toContain('<script>');
        expect(html).not.toContain('<img');
        expect(html).not.toContain('<i>');
        expect(html).toContain('Point &lt;b&gt;3&lt;/b&gt;');
        expect(html).toContain('&lt;script&gt;1&lt;/script&gt;');
        expect(html).toContain('<span>&lt;i&gt;Other&lt;/i&gt;</span>');
        expect(html.match(/<section>/g)).toHaveLength(2);
    });

    it('uses actual Device state before category colour and rejects CSS injection', () => {
        expect(
            getMapMarkerColor(marker({ type: 'asset', status: 'offline' })),
        ).toBe('#ef4444');
        expect(getMapMarkerColor(marker({ status: 'historical' }))).toBe(
            '#3b82f6',
        );
        expect(
            getMapMarkerColor(
                marker({
                    status: 'active',
                    color: 'red; background-image: url(https://example.test)',
                }),
            ),
        ).toBe('#22c55e');
    });
});
