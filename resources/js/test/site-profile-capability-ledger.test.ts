import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const ledger = readFileSync(
    resolve(process.cwd(), 'docs/site-profile-corrective-capability-ledger.md'),
    'utf8',
);

describe('Site Profile corrective capability ledger', () => {
    it('is tied to the authoritative baseline and has no removed outcome', () => {
        expect(ledger).toContain(
            'b5b5df463ce788fbbf988c74f5142b7fcbb52628',
        );
        expect(ledger).not.toMatch(/\| Removed \|/);
    });

    it('tracks every required capability family and closure state', () => {
        for (const heading of [
            'Composition, navigation, payload, and states',
            'Overview and readiness',
            'People',
            'Safety',
            'Operations',
            'Admin',
            'Dialog and destructive-action host',
        ]) {
            expect(ledger).toContain('## ' + heading);
        }

        const rows = ledger
            .split('\n')
            .filter((line) => /^\| (?:C|O|P|S|OP|A|D)-\d+ /.test(line));
        expect(rows).toHaveLength(149);
        for (const row of rows) {
            expect(row).toMatch(
                /\| (?:Restore|Canonical replacement|Improve) \|/,
            );
            expect(row).toMatch(/\| Open \|/);
        }
    });

    it('records endpoint and permission contracts for every required module', () => {
        for (const contract of [
            'sites.clients.link',
            'sites.contacts.store',
            'sites.staff_requirements.store',
            'sites.coverage_requirements.store',
            'sites.hazards.index',
            'health-safety.risk-assessments.index',
            'sites.inspections.index',
            'health-safety.drills.index',
            'health-safety.first-aid.index',
            'health-safety.ppe.index',
            'sites.emergency-plan.show',
            'sites.calendar.index',
            'sites.checklists.index',
            'sites.meals.bootstrap',
            'fleet-assets.assets.index',
            'fleet-assets.dashboard',
            'sites.hardware.index',
            'sites.plan.show',
            'sites.documents.index',
            'sites.ledger.index',
            'sites.vendors.index',
            'sites.credentials.index',
            'settings.service_contexts',
        ]) {
            expect(ledger, contract).toContain(contract);
        }
    });
});
