import { readFileSync, statSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const showPage = resolve(process.cwd(), 'resources/js/pages/sites/show.tsx');
const source = readFileSync(showPage, 'utf8');

describe('site profile shell ownership', () => {
    it('is a slim dedicated Site Profile orchestrator', () => {
        expect(statSync(showPage).size).toBeLessThan(60_000);
        expect(source).toContain('SiteProfileHero');
        expect(source).toContain('SiteProfileAlertRibbon');
        expect(source).toContain('SiteProfileDialogHost');
        expect(source).toContain('testIdPrefix="site-profile"');
        expect(source).not.toContain('import { PageHero');
    });

    it('loads one optional tab prop without scroll-driven navigation', () => {
        expect(source).toContain('router.reload({');
        expect(source).toContain('only: [dataProp]');
        expect(source).not.toContain('scrollIntoView');
    });

    it('settles failed deferred loads instead of restarting them', () => {
        expect(source).toContain('const loadingPropsRef = useRef');
        expect(source).toContain('loadingPropsRef.current[dataProp]');
        expect(source).toContain("router.on('exception'");
        expect(source).toContain('exceptionTargetsProp(');
        expect(source).not.toContain('[loadingProps, props]');
    });

    it('does not host duplicate cross-module workspaces or credential dialogs', () => {
        expect(source).not.toContain('AddVendorDialog');
        expect(source).not.toContain('ShowCredentialDialog');
        expect(source).not.toContain('AddCredentialDialog');
    });
});
