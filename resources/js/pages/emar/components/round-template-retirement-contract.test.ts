import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const readSource = (path: string) =>
    readFileSync(resolve(process.cwd(), path), 'utf8');

const roundsSource = readSource('resources/js/pages/emar/Rounds.tsx');
const typesSource = readSource('resources/js/components/emar/rounds/types.ts');
const routesSource = readSource('routes/emar.php');
const controllerSource = readSource(
    'app/Http/Controllers/Emar/EmarController.php',
);
const templatesTableSource = roundsSource.slice(
    roundsSource.indexOf("{activeTab === 'templates'"),
    roundsSource.indexOf("{activeTab === 'activity'"),
);

describe('round template retirement contract', () => {
    it('uses a dedicated retirement transition that preserves provenance', () => {
        expect(roundsSource).toContain(
            'Retire this round template? Existing rounds will be kept and no new rounds will be generated.',
        );
        expect(roundsSource).toMatch(
            /router\.post\(\s*`\/emar\/rounds\/templates\/\$\{id\}\/retire`/,
        );
        expect(roundsSource).not.toContain(
            'router.delete(`/emar/rounds/templates/${id}`',
        );
        expect(routesSource).toContain(
            "Route::post('/rounds/templates/{template}/retire'",
        );
        expect(routesSource).toContain("name('emar.rounds.templates.retire')");
        expect(routesSource).not.toContain(
            "Route::delete('/rounds/templates/{template}'",
        );
        expect(controllerSource).toContain(
            'public function retireRoundTemplate(',
        );
        expect(controllerSource).not.toContain(
            'public function destroyRoundTemplate(',
        );
        expect(templatesTableSource).toContain('aria-label="Retire template"');
        expect(templatesTableSource).toContain(
            'title="Retire template and keep existing rounds"',
        );
        expect(templatesTableSource).toContain('<Archive');
        expect(templatesTableSource).not.toContain('Delete template');
    });

    it('renders retirement evidence and removes all edit and activation affordances', () => {
        expect(typesSource).toContain('retired_at: string | null;');
        expect(typesSource).toContain('retired_by: string | null;');
        expect(templatesTableSource).toContain('t.retired_at !== null');
        expect(templatesTableSource).toContain('Retired');
        expect(templatesTableSource).toMatch(
            /canManage\s*&&\s*t\.retired_at\s*===\s*null\s*&&/,
        );
        expect(roundsSource).toContain('if (t.retired_at !== null) return;');
    });
});
