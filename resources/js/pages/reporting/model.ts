export type Field = {
    label: string;
    type: 'text' | 'number' | 'date';
    unit: string;
    description: string;
};
export type Source = {
    label: string;
    domain: string;
    fields: Record<string, Field>;
    note: string;
};
export type Measure = {
    id: string;
    label: string;
    operation: string;
    field: string | null;
    formula: string | null;
    decimals: number;
    unit: string;
    where?: { field: string; operator: string; value: string | null } | null;
};
export type FilterRule = {
    field: string;
    operator: string;
    value: string | null;
};
export type FilterGroup = { match: 'all' | 'any'; filters: FilterRule[] };
export type Definition = {
    version: 1;
    name: string;
    source: string;
    columns: string[];
    date_from: string;
    date_to: string;
    site_ids: number[];
    resource_ids: number[];
    subject_id: number | null;
    match: 'all' | 'any';
    filters: { field: string; operator: string; value: string | null }[];
    filter_groups?: FilterGroup[];
    date_bucket?: 'day' | 'week' | 'month';
    detail_sort?: string | null;
    detail_direction?: 'asc' | 'desc';
    highlight?: {
        measure: string;
        operator: 'gt' | 'gte' | 'lt' | 'lte';
        value: number;
    } | null;
    groups: string[];
    measures: Measure[];
    layout: 'table' | 'bar' | 'line' | 'donut' | 'summary' | 'pivot';
    sort: 'group' | 'value';
    direction: 'asc' | 'desc';
    limit: number;
    precision: 'exact' | 'approximate' | 'redacted';
    comparison: boolean;
};
export type Saved = {
    id: number;
    name: string;
    source: string;
    version: number;
    definition: Definition;
    archived_at: string | null;
    folder: string | null;
    favourite: boolean;
};
export type Template = { id: string; name: string; source: string };
export type Group = {
    dimensions: (string | number | null)[];
    values: Record<string, number | null>;
    row_count: number;
    timestamp?: number;
};
export type Payload = {
    generated_at: string;
    definition_hash: string;
    source: {
        window: { from: string; to: string; timezone: string };
        watermark: Record<string, string | number>;
        coverage: string;
    };
    result: {
        pivot_totals?: { rows: Group[]; columns: Group[] };
        row_count: number;
        group_count: number;
        missing: Record<string, number>;
        totals: Record<string, number | null>;
        groups: Group[];
        chart: Group[];
        rows: Record<string, string | number | null>[];
    };
    comparison: {
        totals: Record<string, number | null>;
        window: { from: string; to: string };
        coverage: string;
    } | null;
    preview_limit: number;
};

export function initialDefinition(
    source: string,
    sources: Record<string, Source>,
    name?: string,
): Definition {
    const date = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Pacific/Auckland',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date());
    const from = new Date(date + 'T12:00:00Z');
    from.setUTCDate(from.getUTCDate() - 6);
    const preferred: Record<string, string[]> = {
        journeys: ['distance'],
        bookings: ['booked_hours', 'used_hours', 'distance'],
        maintenance: ['age_days'],
        costs: ['cost', 'litres'],
        resource_costs: ['posted_cost', 'distance', 'missing_distance'],
        finance_bills: ['cost', 'paid'],
        downtime: ['restriction_hours'],
        custody: [],
        stocktakes: ['expected', 'found', 'pending', 'exceptions'],
        demand: ['seats'],
        client_signals: ['battery'],
        client_locations: ['gap_minutes'],
        staff_locations: ['gap_minutes'],
        staff_readiness: ['battery'],
        client_alerts: ['ack_minutes', 'resolution_minutes'],
        staff_alerts: ['ack_minutes', 'resolution_minutes'],
        staff_sessions: ['duration', 'check_ins'],
        my_safety: ['duration', 'check_ins'],
    };
    const measures: Measure[] = [
        {
            id: 'm1',
            label: 'Source rows',
            operation: 'count',
            field: null,
            formula: null,
            decimals: 0,
            unit: 'count',
        },
    ];
    for (const field of preferred[source] ?? []) {
        const meta = sources[source].fields[field];
        if (!meta) continue;
        const operation = [
            'battery',
            'gap_minutes',
            'age_days',
            'ack_minutes',
            'resolution_minutes',
        ].includes(field)
            ? 'avg'
            : 'sum';
        measures.push({
            id: 'm' + (measures.length + 1),
            label:
                (operation === 'avg' ? 'Average ' : 'Total ') +
                meta.label.toLowerCase(),
            operation,
            field,
            formula: null,
            decimals: 2,
            unit: [
                'percent',
                'NZD',
                'km',
                'hours',
                'minutes',
                'count',
            ].includes(meta.unit)
                ? meta.unit
                : 'number',
        });
    }
    if (source === 'resource_costs') {
        measures.push({
            id: 'm5',
            label: 'Posted cost with known positive distance',
            operation: 'sum',
            field: 'posted_cost',
            formula: null,
            decimals: 2,
            unit: 'NZD',
            where: { field: 'distance', operator: 'gt', value: '0' },
        });
        measures.push({
            id: 'm6',
            label: 'Matched posted cost per kilometre',
            operation: 'formula',
            field: null,
            formula: 'm5 / m3',
            decimals: 2,
            unit: 'NZD',
        });
    }
    if (source === 'journeys')
        measures.sort((a, b) =>
            a.field === 'distance' ? -1 : b.field === 'distance' ? 1 : 0,
        );
    return {
        version: 1,
        name:
            name ??
            (source === 'journeys'
                ? 'Fleet use by site'
                : sources[source].label),
        source,
        columns: Object.keys(sources[source].fields).slice(0, 8),
        date_from: from.toISOString().slice(0, 10),
        date_to: date,
        site_ids: [],
        resource_ids: [],
        subject_id: null,
        match: 'all',
        filters: [],
        groups: source === 'journeys' ? ['site'] : ['date'],
        measures,
        layout: source === 'journeys' ? 'bar' : 'table',
        sort: 'group',
        direction: 'asc',
        limit: 20,
        precision: 'redacted',
        comparison: false,
    };
}
export function displayNumber(
    value: number | null | undefined,
    decimals = 1,
): string {
    return value == null || !Number.isFinite(value)
        ? 'Unknown'
        : new Intl.NumberFormat('en-NZ', {
              maximumFractionDigits: Math.max(
                  0,
                  Math.min(6, Number.isInteger(decimals) ? decimals : 1),
              ),
          }).format(value);
}
export function safeCsvCell(value: string | number | null): string {
    const text =
        typeof value === 'number' && Number.isFinite(value)
            ? String(value)
            : value === null
              ? ''
              : /^[\s]*[=+@\-\t\r\n]/u.test(String(value))
                ? "'" + value
                : String(value);
    return '"' + text.replaceAll('"', '""') + '"';
}
export function parseDefinitionFile(text: string): unknown {
    if (text.length > 65536)
        throw new Error('Definition files must be smaller than 64 KB.');
    const parsed: unknown = JSON.parse(text);
    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed))
        throw new Error('Choose a report definition JSON file.');
    return 'definition' in parsed ? parsed.definition : parsed;
}
export async function api(
    path: string,
    method = 'GET',
    data?: unknown,
    signal?: AbortSignal,
): Promise<Response> {
    const xsrf = document.cookie
        .split('; ')
        .find((c) => c.startsWith('XSRF-TOKEN='))
        ?.slice(11);
    const csrf = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;
    const response = await fetch(path, {
        method,
        credentials: 'same-origin',
        signal,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...(xsrf
                ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) }
                : csrf
                  ? { 'X-CSRF-TOKEN': csrf }
                  : {}),
        },
        body: data === undefined ? undefined : JSON.stringify(data),
    });
    if (!response.ok) {
        const error = await response.json().catch(() => ({}));
        throw new Error(
            Object.values(error.errors ?? {})
                .flat()
                .join(' ') ||
                error.message ||
                'The request could not be completed. Try again.',
        );
    }
    return response;
}
export function downloadBlob(blob: Blob, filename: string) {
    const href = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = href;
    link.download = filename;
    link.click();
    setTimeout(() => URL.revokeObjectURL(href), 1000);
}

export function definitionKey(value: unknown): string {
    const canonical = (item: unknown): unknown =>
        Array.isArray(item)
            ? item.map(canonical)
            : item && typeof item === 'object'
              ? Object.fromEntries(
                    Object.entries(item)
                        .sort(([a], [b]) => a.localeCompare(b))
                        .map(([key, entry]) => [key, canonical(entry)]),
                )
              : item;
    return JSON.stringify(canonical(value));
}
