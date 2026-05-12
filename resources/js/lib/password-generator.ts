// Cryptographically-strong password generator using Web Crypto.
// Used by the credential Add/Edit dialogs to fill the password field.

const UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
const LOWER = 'abcdefghijklmnopqrstuvwxyz';
const DIGIT = '0123456789';
const SYMBOL = '!@#$%^&*()-_=+[]{};:,.<>/?';

export type PasswordGeneratorOptions = {
    length?: number;
    upper?: boolean;
    lower?: boolean;
    digit?: boolean;
    symbol?: boolean;
};

export function generatePassword(options: PasswordGeneratorOptions = {}): string {
    const length = Math.max(8, Math.min(128, options.length ?? 20));
    const pools: string[] = [];
    if (options.upper !== false) pools.push(UPPER);
    if (options.lower !== false) pools.push(LOWER);
    if (options.digit !== false) pools.push(DIGIT);
    if (options.symbol !== false) pools.push(SYMBOL);

    if (pools.length === 0) {
        throw new Error('At least one character class must be enabled.');
    }

    const alphabet = pools.join('');
    const out: string[] = [];

    // Guarantee at least one character from each enabled pool.
    for (const pool of pools) {
        out.push(pickRandomChar(pool));
    }

    while (out.length < length) {
        out.push(pickRandomChar(alphabet));
    }

    return shuffle(out).join('');
}

function pickRandomChar(pool: string): string {
    const max = Math.floor(0x100000000 / pool.length) * pool.length;
    const buf = new Uint32Array(1);
    let value: number;
    do {
        crypto.getRandomValues(buf);
        value = buf[0];
    } while (value >= max);
    return pool[value % pool.length];
}

function shuffle<T>(arr: T[]): T[] {
    for (let i = arr.length - 1; i > 0; i--) {
        const buf = new Uint32Array(1);
        crypto.getRandomValues(buf);
        const j = buf[0] % (i + 1);
        [arr[i], arr[j]] = [arr[j], arr[i]];
    }
    return arr;
}
