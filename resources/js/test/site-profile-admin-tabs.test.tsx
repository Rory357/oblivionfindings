import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const root = process.cwd();
const tabs = resolve(root, 'resources/js/pages/sites/tabs');
const adminFiles = [
    'documents.tsx',
    'financials.tsx',
    'vendors.tsx',
    'services.tsx',
];

describe('site profile admin ownership', () => {
    it('extracts each Admin tab into a focused component', () => {
        for (const file of adminFiles) {
            expect(existsSync(resolve(tabs, file)), file).toBe(true);
        }
    });

    it('keeps the complete Site-owned Documents workspace in profile', () => {
        const documents = readFileSync(resolve(tabs, 'documents.tsx'), 'utf8');

        expect(documents).not.toContain('SiteProfileModuleSummary');
        expect(documents).toContain('download');
        expect(documents).toContain('upload');
        expect(documents).toContain('expiry');
    });

    it('makes Finance the primary owner and the house ledger secondary', () => {
        const financials = readFileSync(
            resolve(tabs, 'financials.tsx'),
            'utf8',
        );
        const backend = readFileSync(
            resolve(root, 'app/Services/Sites/SiteProfileData.php'),
            'utf8',
        );

        expect(financials).toContain('SiteLedgerSurface');
        expect(financials).toContain('Finance');
        expect(backend).toContain("canDo('sites.ledger.view')");
    });

    it('restores vendor and credential registers while secrets stay separate', () => {
        const vendors = readFileSync(resolve(tabs, 'vendors.tsx'), 'utf8');
        const backend = readFileSync(
            resolve(root, 'app/Services/Sites/SiteProfileData.php'),
            'utf8',
        );

        expect(vendors).not.toContain('SiteProfileModuleSummary');
        expect(vendors).toMatch(/Credential.*Dialog/);
        expect(vendors).toMatch(/Vendor.*Dialog/);
        expect(vendors).toContain('audit');
        expect(backend).not.toContain("'encrypted_value'");
        expect(backend).not.toContain("'totp_secret_encrypted'");
    });

    it('keeps the complete Site-context Services register', () => {
        const services = readFileSync(resolve(tabs, 'services.tsx'), 'utf8');
        const backend = readFileSync(
            resolve(root, 'app/Services/Sites/SiteProfileData.php'),
            'utf8',
        );

        expect(services).not.toContain('SiteProfileModuleSummary');
        expect(services).toContain('description');
        expect(services).toContain('status');
        expect(backend).toContain("canDo('settings.service_contexts.manage')");
        expect(backend).toContain("route('settings.service_contexts')");
    });
});
