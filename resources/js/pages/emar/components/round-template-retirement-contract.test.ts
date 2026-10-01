import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const readSource = (path: string) =>
    readFileSync(resolve(process.cwd(), path), 'utf8');

// Round templates are managed in Medication › Settings › Rounds & timing
// (eMAR P11); Meds today › Rounds links there.
const templatesSource = readSource(
    'resources/js/pages/emar/settings/_templates.tsx',
);
const roundsSource = readSource('resources/js/pages/emar/Rounds.tsx');
const routesSource = readSource('routes/emar.php');
const controllerSource = readSource(
    'app/Http/Controllers/Emar/EmarController.php',
);

describe('round template retirement contract', () => {
    it('uses a dedicated retirement transition that preserves provenance', () => {
        expect(templatesSource).toMatch(
            /router\.post\(\s*`\/emar\/rounds\/templates\/\$\{t\.id\}\/retire`/,
        );
        expect(templatesSource).not.toContain('router.delete(');
        expect(templatesSource).toContain(
            'No new rounds are created from it. Past rounds keep it on their record.',
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
    });

    it('keeps no template editor on Meds today › Rounds', () => {
        expect(roundsSource).not.toContain('/emar/rounds/templates/');
        expect(roundsSource).toContain('/emar/settings#rounds/templates');
    });
});
