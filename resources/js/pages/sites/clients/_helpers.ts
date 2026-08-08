export function getClientDisplayName(c: {
    first_name: string;
    last_name: string;
    preferred_name?: string | null;
}): string {
    const full = `${c.first_name ?? ''} ${c.last_name ?? ''}`.trim();
    if (
        c.preferred_name &&
        c.preferred_name.trim() &&
        c.preferred_name !== c.first_name
    ) {
        return `${c.preferred_name} (${full})`;
    }
    return full;
}

export function getClientInitials(c: {
    first_name?: string | null;
    last_name?: string | null;
}): string {
    const f = (c.first_name?.[0] ?? '').toUpperCase();
    const l = (c.last_name?.[0] ?? '').toUpperCase();
    return f + l || '?';
}

const STATUS_STYLES: Record<
    string,
    { label: string; cls: string; ring: string }
> = {
    active: {
        label: 'Active',
        cls: 'border-status-success/30 bg-status-success-bg text-status-success',
        ring: 'ring-status-success/40',
    },
    onboarding: {
        label: 'Onboarding',
        cls: 'border-status-warning/30 bg-status-warning-bg text-status-warning',
        ring: 'ring-status-warning/40',
    },
    inactive: {
        label: 'Inactive',
        cls: 'border-border bg-muted/40 text-muted-foreground',
        ring: 'ring-border',
    },
};

export function getClientStatusStyle(status?: string | null) {
    return STATUS_STYLES[status ?? 'inactive'] ?? STATUS_STYLES.inactive;
}

const RISK_STYLES: Record<string, { label: string; cls: string }> = {
    low: {
        label: 'Low risk',
        cls: 'border-status-success/30 text-status-success',
    },
    medium: {
        label: 'Medium risk',
        cls: 'border-status-warning/30 text-status-warning',
    },
    high: {
        label: 'High risk',
        cls: 'border-status-critical/30 text-status-critical',
    },
};

export function getClientRiskStyle(level?: string | null) {
    return RISK_STYLES[level ?? 'low'] ?? RISK_STYLES.low;
}
