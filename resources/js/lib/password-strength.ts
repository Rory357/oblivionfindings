// Lightweight credential-strength hint. This intentionally avoids bundling the
// full zxcvbn English dictionary into Sites dialogs.

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

function fallbackStrength(): StrengthResult {
    return {
        score: 0,
        label: LEVELS[0].label,
        level: LEVELS[0].level,
        feedback: null,
    };
}

export const emptyPasswordStrength: StrengthResult = fallbackStrength();

export async function checkPasswordStrength(
    password: string,
): Promise<StrengthResult> {
    if (!password) {
        return fallbackStrength();
    }

    const result = estimatePasswordStrength(password);
    const score = result.score;

    return {
        score,
        label: LEVELS[score].label,
        level: LEVELS[score].level,
        feedback: result.feedback,
    };
}

function estimatePasswordStrength(password: string): {
    score: 0 | 1 | 2 | 3 | 4;
    feedback: string | null;
} {
    const length = password.length;
    const classes = [
        /[a-z]/.test(password),
        /[A-Z]/.test(password),
        /\d/.test(password),
        /[^A-Za-z0-9]/.test(password),
    ].filter(Boolean).length;
    const lower = password.toLowerCase();
    const hasCommonWord = [
        'password',
        'welcome',
        'admin',
        'qwerty',
        'letmein',
        'oblivion',
    ].some((word) => lower.includes(word));
    const hasLongRepeat = /(.)\1{2,}/.test(password);
    const hasSimpleSequence =
        /(?:0123|1234|2345|3456|4567|5678|6789|abcd|bcde|cdef|qwer|asdf)/i.test(
            password,
        );

    let points = 0;
    if (length >= 8) points += 1;
    if (length >= 12) points += 1;
    if (length >= 16) points += 1;
    if (classes >= 3) points += 1;
    if (classes === 4 && length >= 14) points += 1;
    if (hasCommonWord) points -= 2;
    if (hasLongRepeat || hasSimpleSequence) points -= 1;

    const score = Math.max(0, Math.min(4, points)) as 0 | 1 | 2 | 3 | 4;

    if (hasCommonWord) {
        return {
            score,
            feedback: 'Avoid common words or service names.',
        };
    }

    if (hasLongRepeat || hasSimpleSequence) {
        return {
            score,
            feedback: 'Avoid repeated characters or simple sequences.',
        };
    }

    if (length < 12) {
        return {
            score,
            feedback: 'Use at least 12 characters.',
        };
    }

    if (classes < 3) {
        return {
            score,
            feedback: 'Mix upper, lower, numbers, or symbols.',
        };
    }

    return {
        score,
        feedback: null,
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
