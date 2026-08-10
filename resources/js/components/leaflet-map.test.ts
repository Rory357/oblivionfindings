import { describe, expect, it } from 'vitest';

import {
    escapeMapHtml,
    getMapMarkerColor,
    mapMarkerPopupHtml,
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
