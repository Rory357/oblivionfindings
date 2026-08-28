import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const source = readFileSync(
    resolve(
        process.cwd(),
        'resources/js/pages/emar/components/round-template-dialog.tsx',
    ),
    'utf8',
);
const roundsSource = readFileSync(
    resolve(process.cwd(), 'resources/js/pages/emar/Rounds.tsx'),
    'utf8',
);
const templatesTableSource = roundsSource.slice(
    roundsSource.indexOf("{activeTab === 'templates'"),
    roundsSource.indexOf("{activeTab === 'activity'"),
);

describe('round template Site contract', () => {
    it('requires a selectable Site for new and active templates', () => {
        expect(source).toContain(
            'const siteRequired = !editing || template?.active !== false;',
        );
        expect(source).toContain(
            'const siteSelectionIsValid = !siteRequired || selectedSite !== undefined;',
        );
        expect(source).toContain('(step === 2 && !siteSelectionIsValid)');
        expect(source).toContain(
            'disabled={form.processing || !siteSelectionIsValid}',
        );
        expect(source).toContain(
            "'Choose a site before activating this template.'",
        );
    });

    it('serializes only positive integer Site identifiers', () => {
        expect(source).toContain(
            'return Number.isInteger(parsed) && parsed > 0 ? parsed : null;',
        );
        expect(source).toContain(
            'site_id: positiveIntegerOrNull(data.site_id),',
        );
        expect(source).toContain('Number.isInteger(site.id) &&');
        expect(source).toContain('site.id > 0');
        expect(source).not.toContain(
            'site_id: data.site_id ? Number(data.site_id) : null',
        );
    });

    it('never presents a missing Site as all-Site coverage', () => {
        expect(source).not.toContain('All sites');
        expect(source).toContain('placeholder="Choose a site"');
        expect(source).toContain("'Site required before activation'");
        expect(source).toContain("'Not assigned (inactive legacy template)'");
    });

    it('keeps inactive legacy rows repairable without implying they can generate', () => {
        expect(source).toContain('This inactive legacy template has no site.');
        expect(source).toContain('Choose one before turning auto-generation');
        expect(source).toContain('!siteRequired && selectedSite === undefined');
    });

    it('labels missing Site scope explicitly in the templates table', () => {
        expect(roundsSource).toContain(
            "return 'No site assigned (legacy template)';",
        );
        expect(roundsSource).toContain(
            "return template.site_name ?? 'Assigned site unavailable';",
        );
        expect(templatesTableSource).toContain('{templateSiteLabel(t)}');
        expect(templatesTableSource).not.toContain('All sites');
    });

    it('blocks quick activation without a concrete Site but still permits deactivation', () => {
        expect(roundsSource).toContain(
            'if (!t.active && !hasConcreteTemplateSite(t)) return;',
        );
        expect(templatesTableSource).toContain('disabled={');
        expect(
            templatesTableSource.match(
                /!t\.active &&\s*!hasConcreteTemplateSite\(\s*t,?\s*\)/g,
            )?.length ?? 0,
        ).toBeGreaterThanOrEqual(2);
        expect(templatesTableSource).toContain(
            'Assign a site before enabling auto-generation',
        );
        expect(roundsSource).toContain('{ active: !t.active }');
    });
});
