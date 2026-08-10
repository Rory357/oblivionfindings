import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const root = process.cwd();
const source = (path: string) => readFileSync(resolve(root, path), 'utf8');

describe('service agreement related-record boundaries', () => {
    const controller = source(
        'app/Http/Controllers/Operations/ServiceAgreementController.php',
    );
    const page = source(
        'resources/js/pages/operations/service-agreements/Show.tsx',
    );

    it('projects only the exact permissions for funding and invoice handoffs', () => {
        expect(controller).toContain("$auth->canDo('funding.viewAny')");
        expect(controller).toContain("$auth->canDo('funding.claims.create')");
        expect(controller).toContain("$auth->canDo('finance.ar.view')");
        expect(controller).toContain("'related_record_permissions' => [");

        expect(page).toContain(
            'relatedRecordPermissions.view_funding_claims &&',
        );
        expect(page).toContain(
            'relatedRecordPermissions.create_funding_claims &&',
        );
        expect(page).toContain('relatedRecordPermissions.view_invoices &&');
        expect(page).toContain('{showRelatedRecords && (');
    });

    it('does not serialize unused funding detail or advertise a mock shift integration', () => {
        expect(controller).not.toContain(
            "withCount(['lineItems', 'fundingClaims'])",
        );
        expect(controller).not.toContain("'fundingClaims' => fn");
        expect(controller).not.toContain('fundingClaims.submitter');
        expect(controller).not.toContain("'funding_claims_summary' =>");

        expect(page).not.toContain('Shift integration coming soon');
        expect(page).not.toContain('Linked Shifts');
        expect(page).not.toContain('<Timer');
    });
});
