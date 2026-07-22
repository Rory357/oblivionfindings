import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const readTab = (name: string) =>
    readFileSync(
        resolve(process.cwd(), 'resources/js/pages/sites/tabs', name + '.tsx'),
        'utf8',
    );

describe('Site Profile People workspaces', () => {
    it('keeps canonical client and contact workflows', () => {
        const clients = readTab('clients');
        const contacts = readTab('contacts');

        expect(clients).toContain('AddClientDialog');
        expect(clients).toContain('LinkClientDialog');
        expect(clients).toContain('Unlink client');
        expect(contacts).toContain('AddContactDialog');
        expect(contacts).toContain('EditContactDialog');
        expect(contacts).toContain('Delete Site contact');
    });

    it('keeps Site-context staff and coverage CRUD', () => {
        const staff = readTab('staff-requirements');
        const coverage = readTab('shift-coverage');

        expect(staff).toContain('/staff-requirements/');
        expect(staff).toContain('Edit staff requirement');
        expect(staff).toContain('Remove requirement');
        expect(coverage).toContain('/coverage-requirements/');
        expect(coverage).toContain('role_requirements');
        expect(coverage).toContain('allow_overstaffing');
        expect(coverage).toContain('preferred_client_id');
        expect(coverage).toContain('service_context_id');
        expect(coverage).toContain('Rostering');
        expect(coverage).toContain('impact');
    });
});
