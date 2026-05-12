// Thin wrapper around zxcvbn-ts so callers don't have to repeat the option
// setup. Returns a 0-4 score and a human-readable label that maps to
// existing theme tokens.

import { zxcvbnOptions, zxcvbn } from '@zxcvbn-ts/core';
import * as zxcvbnEnPackage from '@zxcvbn-ts/language-en';

const en = zxcvbnEnPackage as unknown as {
    translations: any;
    dictionary?: any;
    adjacencyGraphs?: any;
};

let configured = false;

function configure() {
    if (configured) return;
    zxcvbnOptions.setOptions({
        translations: en.translations,
        dictionary: en.dictionary,
        graphs: en.adjacencyGraphs,
    });
    configured = true;
}

export type StrengthLevel = 'very-weak' | 'weak' | 'fair' | 'good' | 'strong';

export type StrengthResult = {
    score: 0 | 1 | 2 | 3 | 4;
    label: string;
    level: StrengthLevel;
    feedback: string | null;
};

const LEVELS: Record<number, { label: string; level: StrengthLevel }> = {
    0: { label: 'Very weak', level: 'very-weak' },
    1: { label: 'Weak', level: 'weak' },
    2: { label: 'Fair', level: 'fair' },
    3: { label: 'Good', level: 'good' },
    4: { label: 'Very strong', level: 'strong' },
};

export function checkPasswordStrength(password: string): StrengthResult {
    configure();
    if (!password) {
        return {
            score: 0,
            label: LEVELS[0].label,
            level: LEVELS[0].level,
            feedback: null,
        };
    }
    const result = zxcvbn(password);
    const score = result.score as 0 | 1 | 2 | 3 | 4;
    return {
        score,
        label: LEVELS[score].label,
        level: LEVELS[score].level,
        feedback: result.feedback?.warning ?? result.feedback?.suggestions?.[0] ?? null,
    };
}

export function strengthBadgeClasses(level: StrengthLevel): string {
    switch (level) {
        case 'strong':
            return 'border-status-success/30 bg-status-success/10 text-status-success';
        case 'good':
            return 'border-status-success/30 bg-status-success/10 text-status-success';
        case 'fair':
            return 'border-status-warning/30 bg-status-warning/10 text-status-warning';
        case 'weak':
            return 'border-status-warning/30 bg-status-warning/10 text-status-warning';
        case 'very-weak':
        default:
            return 'border-status-critical/30 bg-status-critical/10 text-status-critical';
    }
}
