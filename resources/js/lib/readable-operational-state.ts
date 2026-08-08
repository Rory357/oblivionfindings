function readableToken(value: string): string {
    const normalized = value
        .replace(/[._-]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
    return normalized || 'Not recorded';
}

function readableLabel(value: string): string {
    const label = readableToken(value);
    return label.charAt(0).toUpperCase() + label.slice(1);
}

export function formatReadableOperationalValue(
    value: unknown,
    depth = 0,
): string {
    if (value === null || value === undefined || value === '') {
        return 'Not recorded';
    }
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    if (typeof value === 'number') return new Intl.NumberFormat().format(value);
    if (typeof value === 'string') {
        return /^[a-z0-9]+(?:[._-][a-z0-9]+)+$/i.test(value)
            ? readableToken(value)
            : value;
    }
    if (Array.isArray(value)) {
        if (value.length === 0) return 'None recorded';
        return value
            .map((entry) => formatReadableOperationalValue(entry, depth + 1))
            .join(', ');
    }
    if (typeof value !== 'object') return String(value);

    const entries = Object.entries(value as Record<string, unknown>);
    if (entries.length === 0) return 'None recorded';
    if (depth >= 2) {
        return `${entries.length} recorded ${entries.length === 1 ? 'field' : 'fields'}`;
    }

    return entries
        .map(
            ([key, entry]) =>
                `${readableLabel(key)}: ${formatReadableOperationalValue(entry, depth + 1)}`,
        )
        .join('; ');
}

export function formatReadableOperationalState(
    state: Record<string, unknown>,
): string {
    const entries = Object.entries(state);
    if (entries.length === 0) return 'Not recorded';

    return entries
        .map(
            ([key, value]) =>
                `${readableLabel(key)}: ${formatReadableOperationalValue(value, 1)}`,
        )
        .join(' · ');
}
