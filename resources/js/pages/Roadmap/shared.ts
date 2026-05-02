export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type PaginationPayload<T> = {
    data: T[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    total: number;
};

export type RoadmapCan = {
    viewDashboard: boolean;
    viewRoadmap: boolean;
    manageRoadmap: boolean;
    approveRoadmap: boolean;
    manageBudget: boolean;
    viewDecisions: boolean;
    manageDecisions: boolean;
    exportReports: boolean;
};

export type ManagerOption = {
    id: number;
    name: string;
    email: string;
    role_label?: string | null;
};

export function statusLabel(value?: string | number | null): string {
    if (value === null || value === undefined || value === '') {
        return '-';
    }

    return String(value)
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (char) => char.toUpperCase());
}

export function formatDate(value?: string | null): string {
    if (!value) {
        return '-';
    }

    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) {
        return value;
    }

    return parsed.toLocaleDateString('en-NZ', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

export function formatCurrency(value?: number | string | null): string {
    const numeric =
        typeof value === 'string' ? Number.parseFloat(value) : (value ?? 0);

    if (Number.isNaN(numeric)) {
        return '-';
    }

    return numeric.toLocaleString('en-NZ', {
        style: 'currency',
        currency: 'NZD',
        maximumFractionDigits: 0,
    });
}

export function extractErrorMessage(error: unknown, fallback: string): string {
    if (
        typeof error === 'object' &&
        error !== null &&
        'response' in error &&
        typeof error.response === 'object' &&
        error.response !== null &&
        'data' in error.response &&
        typeof error.response.data === 'object' &&
        error.response.data !== null &&
        'message' in error.response.data &&
        typeof error.response.data.message === 'string'
    ) {
        return error.response.data.message;
    }

    return fallback;
}
