import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const readSource = (path: string) =>
    readFileSync(resolve(process.cwd(), path), 'utf8');

const dialogSource = readSource(
    'resources/js/pages/emar/components/guided-round-dialog.tsx',
);
const roundsSource = readSource('resources/js/pages/emar/Rounds.tsx');
const typesSource = readSource('resources/js/components/emar/rounds/types.ts');
const serviceSource = readSource('app/Services/GuidedRoundService.php');

describe('round completion request contract', () => {
    it('uses the server canonical completion decision without exposing hidden item details', () => {
        expect(typesSource.match(/can_complete: boolean;/g)).toHaveLength(2);
        expect(serviceSource).toContain(
            'public function canCompleteCanonicalRound(MedicationRound $round): bool',
        );
        expect(serviceSource).toContain('$this->items($round, true)');
        expect(dialogSource).toContain(
            'const canCompleteRound = guided.can_complete && canRecordRound;',
        );
        expect(dialogSource).toContain(
            'progress.pending === 0 && canCompleteRound',
        );
        expect(roundsSource).toContain('original.can_complete');
        expect(dialogSource).toContain(
            'Round completion is not available yet.',
        );
    });

    it('resumes partial rounds through the explicit start request', () => {
        expect(dialogSource).toContain(
            "round.status === 'pending' || round.status === 'partial'",
        );
        expect(dialogSource).toContain("'Resume round'");
        expect(dialogSource).toContain(
            '`/emar/rounds/${round.id}/guided/start`',
        );
    });
});
