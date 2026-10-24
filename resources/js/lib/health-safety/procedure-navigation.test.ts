import { describe, expect, it } from 'vitest';
import { firstAidReturn } from './first-aid-navigation';
import { safetyReviewReturn } from './navigation';
import {
    PROCEDURE_REGISTER,
    procedureConnectionHref,
    procedureHref,
    procedureLocation,
    procedureReturn,
    withProcedureRequest,
} from './procedure-navigation';
describe('procedure source and revision navigation', () => {
    it('retains the selected request across approved, working and historical copy links without commands', () => {
        for (const selection of [
            '',
            '?revision=working',
            '?version=22&action=archive',
        ]) {
            const target = procedureLocation(
                withProcedureRequest(
                    '/health-safety/procedures/7' + selection,
                    42,
                ),
            );
            expect(target.request).toBe(42);
            expect(target.command).toBeNull();
            if (selection.includes('working'))
                expect(target.revision).toBe('working');
            if (selection.includes('version=')) expect(target.version).toBe(22);
        }
    });
    it('keeps the readable requested version and current source return from a server connection', () => {
        expect(
            procedureLocation(
                procedureConnectionHref(
                    7,
                    '/health-safety/procedures/7?version=22&action=archive&return_to=/tasks',
                    '/health-safety/events/3?section=actions',
                ),
            ),
        ).toMatchObject({
            version: 22,
            command: null,
            section: 'connections',
            returnTo: '/health-safety/events/3?section=actions',
        });
    });
    it('does not import another record or external connection selection', () => {
        for (const href of [
            '/health-safety/procedures/8?version=22',
            'https://evil.test/health-safety/procedures/7?version=22',
        ]) {
            expect(
                procedureLocation(procedureConnectionHref(7, href, '/tasks')),
            ).toMatchObject({ version: null, revision: null });
        }
    });
    it.each([
        'https://evil.test',
        '//evil.test/path',
        '/\\evil.test',
        '/sites/0',
        '/sites/2#private',
        '/sites/2\n',
        '/unsupported/2',
        '/unknown/../sites/2',
    ])('rejects unsafe or unsupported origin %s', (value) =>
        expect(procedureReturn(value)).toBe(PROCEDURE_REGISTER),
    );
    it('keeps supported list filters without a command or legacy modal state', () => {
        const incidentOrigin = '/incidents?kind=incidents&site_id=3&from=2026-09-03&to=2026-09-19';
        // The same page and filters; the order of query keys does not change the link.
        const parts = (href: string) => {
            const url = new URL(href, 'https://test.invalid');
            return [url.pathname, Object.fromEntries(url.searchParams)];
        };
        expect(parts(procedureReturn(incidentOrigin))).toEqual(parts(incidentOrigin));
        expect(parts(firstAidReturn(incidentOrigin))).toEqual(parts(incidentOrigin));
        expect(procedureReturn('/incidents?kind=unsupported&site_id=3')).toBe('/incidents?site_id=3');
        const origin = procedureReturn(
            PROCEDURE_REGISTER +
                '?tab=review_due&review_state=today&site_id=2&q=hoist&procedure=7&action=archive',
        );
        expect(
            new URL(origin, 'https://test.invalid').searchParams.get('q'),
        ).toBe('hoist');
        expect(origin).not.toContain('action=');
        expect(origin).not.toContain('procedure=');
        expect(
            procedureLocation(
                procedureHref(7, {
                    revision: 'working',
                    section: 'documents',
                    returnTo: origin,
                }),
            ),
        ).toEqual({
            section: 'documents',
            command: null,
            revision: 'working',
            version: null,
            returnTo: origin,
            request: null,
        });
    });
    it('preserves exact historical revision through connected safety work', () => {
        const href = procedureHref(7, {
            version: 22,
            section: 'connections',
            returnTo: '/sites/2?tab=hazards',
        });
        const returned = safetyReviewReturn(href);
        expect(procedureLocation(returned)).toMatchObject({
            version: 22,
            section: 'connections',
            returnTo: '/sites/2?tab=hazards',
        });
    });
    it('retains the actual risk, review and HR profile sections', () => {
        expect(
            procedureReturn('/health-safety/events/3?section=investigation'),
        ).toContain('section=investigation');
        expect(
            procedureReturn('/health-safety/risk-assessments/4?section=risk'),
        ).toContain('section=risk');
        expect(procedureReturn('/hr/people/12?tab=training')).toBe(
            '/hr/people/12?tab=training',
        );
        expect(procedureReturn('/hr/employees/12?tab=training')).toBe(
            PROCEDURE_REGISTER,
        );
    });
    it('bounds nested returns and rejects self-return cycles', () => {
        const origin =
            '/health-safety/events/3?section=actions&return_to=' +
            encodeURIComponent(
                '/health-safety/events?tab=open&return_to=/sites/2',
            );
        expect(procedureReturn(origin)).toBe(
            '/health-safety/events/3?section=actions',
        );
        expect(procedureHref(7, { returnTo: procedureHref(7) })).toBe(
            PROCEDURE_REGISTER + '/7',
        );
    });
    it('never restores unknown commands or malformed historical version IDs', () => {
        expect(
            procedureLocation(
                PROCEDURE_REGISTER +
                    '/7?section=secret&action=delete&version=2oops',
            ),
        ).toMatchObject({ section: 'overview', command: null, version: null });
    });
});
