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

    it('keeps Documents as a bounded summary of the canonical Site workspace', () => {
        const documents = readFileSync(resolve(tabs, 'documents.tsx'), 'utf8');
        const backend = readFileSync(
            resolve(root, 'app/Services/Sites/SiteProfileData.php'),
            'utf8',
        );

        expect(documents).toContain('SiteProfileModuleSummary');
        expect(documents).not.toContain('useForm');
        expect(documents).not.toContain('router.post');
        expect(documents).not.toContain('<form');
        expect(backend).toContain("route('sites.documents.index'");
        expect(backend).toContain('SiteDocument::PROFILE_LIMIT');
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

        expect(financials).toContain('Finance Site Dashboard');
        expect(financials).toContain('Secondary Site workflow');
        expect(financials).not.toContain('useForm');
        expect(backend).toContain('finance.sites.financial-dashboard');
        expect(backend).toContain("canDo('sites.ledger.view')");
        expect(backend).toContain("route('sites.ledger.index'");
    });

    it('keeps vendors, credentials, and secret handling in the unified owner', () => {
        const vendors = readFileSync(resolve(tabs, 'vendors.tsx'), 'utf8');
        const backend = readFileSync(
            resolve(root, 'app/Services/Sites/SiteProfileData.php'),
            'utf8',
        );

        expect(vendors).toContain('SiteProfileModuleSummary');
        expect(vendors).not.toMatch(/Credential.*Dialog|Vendor.*Dialog/);
        expect(vendors).not.toContain('useForm');
        expect(vendors).not.toContain('reveal');
        expect(vendors).not.toContain('secret');
        expect(backend).toContain("route('sites.vendors.global'");
        expect(backend).not.toContain("'encrypted_value'");
        expect(backend).not.toContain("'totp_secret_encrypted'");
    });

    it('bounds Service rows and links authorised management to Settings', () => {
        const services = readFileSync(resolve(tabs, 'services.tsx'), 'utf8');
        const backend = readFileSync(
            resolve(root, 'app/Services/Sites/SiteProfileData.php'),
            'utf8',
        );

        expect(services).toContain('SiteProfileModuleSummary');
        expect(services).not.toContain('useForm');
        expect(backend).toContain('ServiceContext::PROFILE_LIMIT');
        expect(backend).toContain("canDo('settings.service_contexts.manage')");
        expect(backend).toContain("route('settings.service_contexts')");
    });
});
