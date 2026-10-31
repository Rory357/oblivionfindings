import { describe, expect, it } from 'vitest';
import {
    incidentRegisterReturn,
    incidentReturnLabel,
    incidentWorkspaceHref,
} from './navigation';

describe('Incident register return navigation', () => {
    it.each(['overview', 'handover', 'investigation', 'actions', 'risk', 'timeline', 'evidence'])(
        'preserves only the Safety review read section %s through incident and Back',
        (section) => {
            const safe = `/health-safety/events/9?section=${section}`;
            const origin = safe + '&action=worksafe-notify&pane=investigation&return_to=%2Ftasks&receipt_key=private';
            const back = new URL(incidentWorkspaceHref(42, origin), 'https://example.invalid').searchParams.get('return_to');
            expect(back).toBe(safe);
            expect(incidentRegisterReturn(back)).toBe(safe);
            expect(incidentReturnLabel(back!)).toBe('Safety reviews');
        },
    );
    it.each([
        '/health-safety/events/9?section=unsupported',
        '/health-safety/events/9?section=overview&section=evidence',
        '/health-safety/events/9?action=close&pane=inv_edit',
    ])('drops invalid or command-only Event context: %s', (origin) => {
        expect(incidentRegisterReturn(origin)).toBe('/health-safety/events/9');
    });
    it('returns to the exact selected Fleet incident with the actual bounded read filters', () => {
        const origin = '/fleet-assets/incidents?incident=12&vehicle_id=2&driver_id=3&site_id=4&page=5&tab=under_investigation&severity=major&incident_type=collision&status=investigating&search=van+damage&date_from=2024-02-29&date_to=2024-03-02';
        expect(incidentRegisterReturn(origin + '&export=csv&report=vehicle&asset_id=8&action=close&return_to=%2Ftasks')).toBe(origin);
        const back = new URL(incidentWorkspaceHref(42, origin), 'https://example.invalid').searchParams.get('return_to');
        expect(back).toBe(origin);
        expect(incidentRegisterReturn(back)).toBe(origin);
        expect(incidentReturnLabel(back!)).toBe('Fleet incidents');
    });
    it.each(['all', 'open', 'under_investigation', 'police_report_due', 'injury_acc', 'insurance_claims', 'off_road', 'near_misses', 'closed'])(
        'retains actual Fleet tab %s', (tab) => {
            expect(incidentRegisterReturn(`/fleet-assets/incidents?incident=12&tab=${tab}`)).toBe(`/fleet-assets/incidents?incident=12&tab=${tab}`);
        },
    );
    it('omits singleton empty optional Fleet identities without losing the selected incident', () => {
        expect(incidentRegisterReturn('/fleet-assets/incidents?vehicle_id=&driver_id=&site_id=&incident=9&page='))
            .toBe('/fleet-assets/incidents?incident=9');
        expect(incidentRegisterReturn('/fleet-assets/incidents?incident=9&site_id=&site_id='))
            .toBe('/incidents');
        expect(incidentRegisterReturn('/fleet-assets/incidents?incident=9&page=&page=2'))
            .toBe('/incidents');
    });
    it.each([
        'tab=unsupported&severity=high&incident_type=injury&status=draft',
        'severity=major&severity=minor&search=one&search=two',
        'date_from=2023-02-29&date_to=2024-04-31',
        'date_from=2024-2-01&date_to=0000-01-01',
        'search=' + 'x'.repeat(201),
        'search=' + encodeURIComponent('é'.repeat(101)),
        'q=unsupported&from=2024-01-01&view=all',
    ])('drops unsupported or malformed Fleet list filters: %s', (query) => {
        expect(incidentRegisterReturn('/fleet-assets/incidents?incident=12&' + query)).toBe('/fleet-assets/incidents?incident=12');
    });
    it.each([
        '/health-safety/events/0',
        '/health-safety/events/01',
        '/health-safety/events/9007199254740992',
        '/health-safety/events/9/close',
        '/health-safety/events/8/../9',
        '/health-safety/events/%39',
        '/health-safety/events/9#evidence',
        '/health-safety/events/9?section=evidence%0A',
        '/health-safety/events/9?private=%E2%80%8Bsecret',
        '/health-safety/events/9?pane=%5Cprivate',
        '/health-safety/events/9?section=evidence%23private',
        '/fleet-assets/incidents',
        '/fleet-assets/incidents?incident=0',
        '/fleet-assets/incidents?incident=01',
        '/fleet-assets/incidents?incident=9007199254740992',
        '/fleet-assets/incidents?incident=12&incident=13',
        '/fleet-assets/incidents?incident=12&inc%69dent=12',
        '/fleet-assets/incidents?incident=12&site_id=0',
        '/fleet-assets/incidents?incident=12&driver_id=9007199254740992',
        '/fleet-assets/incidents?incident=12&vehicle_id=2&vehicle_id=3',
        '/fleet-assets/incidents?incident=12&page=-1',
        '/fleet-assets/incidents?incident=12&search=%00private',
        '/fleet-assets/incidents?incident=12&search=private\\path',
        '/fleet-assets/incidents?incident=12#linked',
        '/fleet-assets/incidents?incident=12&search=' + 'x'.repeat(1500),
        'https://outside.test/health-safety/events/9',
        '//outside.test/fleet-assets/incidents?incident=12',
        '/fleet-assets/incidents/../incidents?incident=12',
    ])('refuses unsafe or ambiguous new source destinations: %s', (origin) => {
        expect(incidentRegisterReturn(origin)).toBe('/incidents');
        expect(incidentWorkspaceHref(42, origin)).toBe('/incidents/42');
    });
    it('accepts the largest safe identity without coercing its bytes', () => {
        for (const origin of ['/health-safety/events/9007199254740991', '/fleet-assets/incidents?incident=9007199254740991']) {
            expect(incidentRegisterReturn(origin)).toBe(origin);
        }
    });
    it.each([
        [
            '/clients/9/incidents?view=drafts&q=fall&type=injury&severity=high',
            'Client incidents',
        ],
        [
            '/operations/clients/9/incidents?view=followup&type=custom_type',
            'Client incidents',
        ],
        ['/clients/9?tab=incidents_accidents', 'Client incidents'],
        ['/operations/clients/9?tab=incidents_accidents', 'Client incidents'],
        [
            '/health-safety/injuries?tab=under_treatment&site_id=3&page=2',
            'Workplace injuries',
        ],
        [
            '/health-safety/restraints?lens=events&tab=unreviewed&site_id=3&events_page=2',
            'Restrictive interventions',
        ],
        [
            '/health-safety/restraints/events/9?section=connections',
            'Restrictive interventions',
        ],
    ])(
        'returns from the dedicated incident to its source: %s',
        (origin, label) => {
            const href = incidentWorkspaceHref(42, origin);
            const back = new URL(
                href,
                'https://example.invalid',
            ).searchParams.get('return_to');
            expect(incidentRegisterReturn(back)).toBe(origin);
            expect(incidentReturnLabel(back!)).toBe(label);
        },
    );
    it('drops client commands and nested returns while retaining the incident list view', () => {
        expect(
            incidentRegisterReturn(
                '/operations/clients/9/incidents?view=high&action=delete&return_to=%2Fsettings&incident=4',
            ),
        ).toBe('/operations/clients/9/incidents?view=high');
        expect(
            incidentRegisterReturn(
                '/operations/clients/9?tab=incidents_accidents&dialog=log_incident&return_to=%2Fsettings',
            ),
        ).toBe('/operations/clients/9?tab=incidents_accidents');
    });
    it('drops unsupported, duplicate and control-bearing client filters', () => {
        expect(
            incidentRegisterReturn(
                '/clients/9/incidents?view=bad&q=fall&q=other&type=injury&severity=invalid',
            ),
        ).toBe('/clients/9/incidents?type=injury');
        expect(
            incidentRegisterReturn(
                '/clients/9/incidents?q=%0Aprivate&type=injury',
            ),
        ).toBe('/clients/9/incidents?type=injury');
    });
    it('removes restraint commands and legacy modal selection from a return', () => {
        expect(
            incidentRegisterReturn(
                '/health-safety/restraints?lens=events&event=9&action=delete',
            ),
        ).toBe('/health-safety/restraints?lens=events');
        expect(
            incidentRegisterReturn(
                '/health-safety/restraints/events/9?section=connections&action=remove-evidence',
            ),
        ).toBe('/health-safety/restraints/events/9?section=connections');
    });
    it.each([
        '//outside.test/clients/9/incidents',
        '/operations/clients/0/incidents',
        '/operations/clients/9007199254740992/incidents',
        '/operations/clients/9/incidents/submit',
        '/operations/clients/9/../10/incidents',
        '/operations/clients/9?tab=profile',
        '/operations/clients/9?tab=incidents_accidents&tab=profile',
        '/operations/clients/9/incidents#private',
        '/operations/clients/9\\incidents',
        '/operations/clients/9/incidents?q=' + 'x'.repeat(1500),
    ])('refuses unrelated or malformed client returns: %s', (value) => {
        expect(incidentRegisterReturn(value)).toBe('/incidents');
    });
    it.each(['all', 'me', 'needs_owner'])(
        'retains the admitted %s ownership lens through the dedicated page and Back',
        (owner) => {
            const origin = `/incidents?owner=${owner}&site_id=3&page=2`;
            const href = incidentWorkspaceHref(42, origin);
            const retained = new URL(
                href,
                'https://incident-return.invalid',
            ).searchParams.get('return_to');
            expect(incidentRegisterReturn(retained)).toBe(origin);
        },
    );
    it.each(['unknown', 'ME', 'me,needs_owner', 'https://outside.test'])(
        'removes an unsupported ownership lens %s while retaining the other register filters',
        (owner) => {
            expect(
                incidentRegisterReturn(
                    `/incidents?owner=${encodeURIComponent(owner)}&site_id=3&page=2`,
                ),
            ).toBe('/incidents?site_id=3&page=2');
        },
    );
    it('returns to a private exposure read section and page without retaining its command or receipt', () => {
        const origin =
            '/health-safety/chemical-exposures/22?section=connections&history_page=2&action=correct&receipt_key=private&return_to=%2Ftasks';
        const safe =
            '/health-safety/chemical-exposures/22?section=connections&history_page=2';
        expect(incidentRegisterReturn(origin)).toBe(safe);
        const href = incidentWorkspaceHref(11, origin);
        expect(
            new URL(href, 'https://example.invalid').searchParams.get(
                'return_to',
            ),
        ).toBe(safe);
    });
    it('returns to the treatment page with a single permitted source origin', () => {
        const source =
            '/health-safety/first-aid/9?section=incident&return_to=%2Fsites%2F2%3Ftab%3Dfirst_aid';
        expect(incidentRegisterReturn(source)).toBe(source);
        expect(incidentRegisterReturn(source + '&action=archive')).toBe(source);
        expect(
            incidentRegisterReturn(
                '/health-safety/first-aid/9?return_to=https://outside.test',
            ),
        ).toBe('/health-safety/first-aid/9');
    });
    it('retains filters and pagination but removes legacy modal and nested return parameters', () => {
        expect(
            incidentRegisterReturn(
                '/incidents?q=fall&page=3&incident=42&return_to=https://example.test&tab=review',
            ),
        ).toBe('/incidents?q=fall&page=3&tab=review');
        expect(incidentWorkspaceHref(42, '/incidents?site_id=3&page=2')).toBe(
            '/incidents/42?return_to=%2Fincidents%3Fsite_id%3D3%26page%3D2',
        );
    });
    it.each([
        'https://example.test/incidents',
        '//example.test/incidents',
        '/incidents/42',
        '/incidents/../settings',
        '/incidents\\..\\settings',
        null,
    ])('rejects an unrelated or unsafe return destination: %s', (value) => {
        expect(incidentRegisterReturn(value)).toBe('/incidents');
    });
    it('keeps the incident-only Site and custom period through the dedicated page and Back', () => {
        const origin =
            '/incidents?from=2026-09-03&to=2026-09-19&kind=incidents&tab=all&site_id=3';
        const href = incidentWorkspaceHref(42, origin);
        const retained = new URL(
            href,
            'https://incident-return.invalid',
        ).searchParams.get('return_to');
        expect(incidentRegisterReturn(retained)).toBe(origin);
        expect(
            incidentRegisterReturn('/incidents?kind=unsupported&site_id=3'),
        ).toBe('/incidents?site_id=3');
    });
});

describe('My Day incident return', () => {
    it('returns to the current authorised day without claiming local filter restoration', () => {
        const href = incidentWorkspaceHref(42, '/my-day');
        expect(new URL(href, 'https://example.invalid').searchParams.get('return_to')).toBe('/my-day');
        expect(incidentReturnLabel('/my-day')).toBe('My Day');
    });
    it.each(['/my-day?date=2026-10-21', '/my-day#person', '/my-day/submit', '//outside.test/my-day'])('rejects unsupported My Day state: %s', (target) => {
        expect(incidentRegisterReturn(target)).toBe('/incidents');
    });
});