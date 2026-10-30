import { describe, expect, it } from 'vitest';
import {
    googleConsoleUrl,
    mapConfigurationErrors,
    mapErrorStep,
} from './_map-configuration';
import type { MapValues } from './_types';

const values: MapValues = {
    google: true,
    project: 'example-project',
    display: true,
    places: false,
    geocoding: false,
    routes: false,
    restrictions_reviewed: true,
    terms_reviewed: true,
};

describe('Google configuration prerequisites', () => {
    it('allows display alone without server credentials', () => {
        expect(
            mapConfigurationErrors(values, { browser: true, server: false }),
        ).toEqual({});
    });
    it.each(['places', 'geocoding', 'routes'])(
        'requires a server credential only when %s is selected',
        (key) => {
            const errors = mapConfigurationErrors(
                { ...values, [key]: true },
                { browser: true, server: false },
            );
            expect(errors.places).toContain('separate server key');
            expect(mapErrorStep(errors)).toBe(1);
        },
    );
    it('allows Google to be switched off after credentials are removed', () => {
        expect(
            mapConfigurationErrors(
                { ...values, google: false, places: true },
                { browser: false, server: false },
            ),
        ).toEqual({});
    });
    it('returns missing project and browser credentials to the connection step', () => {
        const errors = mapConfigurationErrors(
            { ...values, project: '' },
            { browser: false, server: false },
        );
        expect(errors.project).toBeTruthy();
        expect(errors.google).toBeTruthy();
        expect(mapErrorStep(errors)).toBe(0);
    });
    it('opens only Google console links with an encoded project parameter', () => {
        expect(googleConsoleUrl('/google/maps-apis/quotas', 'a & b')).toBe(
            'https://console.cloud.google.com/google/maps-apis/quotas?project=a+%26+b',
        );
    });
});
