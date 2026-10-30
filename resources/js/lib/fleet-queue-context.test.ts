import { describe, expect, it } from 'vitest';
import { fleetQueueReturn, fleetScopeHref } from './fleet-queue-context';
describe('Fleet queue return context', () => {
    it('keeps only supported local queues and their filter vocabulary', () => {
        expect(
            fleetQueueReturn(
                '/fleet-assets/compliance?site_id=3&view=all&kind=wof&page=2&return_to=https://external.test',
            ),
        ).toBe('/fleet-assets/compliance?site_id=3&view=all&kind=wof&page=2');
        expect(
            fleetQueueReturn(
                '/fleet-assets/alerts?status=ack&cr_page=3&search=van',
            ),
        ).toBe('/fleet-assets/alerts?search=van&status=ack&cr_page=3');
        for (const href of [
            'https://external.test',
            '//external.test',
            '/fleet-assets/vehicles/3',
            '/fleet-assets/vehicles\\evil',
            '/fleet-assets/compliance#evil',
        ])
            expect(fleetQueueReturn(href)).toBeNull();
    });
    it('carries site and supported resource scope without rewriting external destinations', () => {
        expect(
            fleetScopeHref(
                '/fleet-assets/alerts',
                '/fleet-assets?site_id=3&entity=vehicle',
            ),
        ).toBe('/fleet-assets/alerts?site_id=3&entity=vehicle');
        expect(fleetScopeHref('/control-room', '/fleet-assets?site_id=3')).toBe(
            '/control-room',
        );
    });
});
