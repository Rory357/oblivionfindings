/**
 * The H&S board reports: one button name, one name per report, and links to the readable report pages
 * (`/health-safety/reports?view=…`). The `/health-safety/reports/{name}` routes answer JSON only, so no screen
 * links to them. Every board-report menu (Overview, Duties, Trends, Safety reviews, Chemicals, Lone workers,
 * Hazards, the Reports hub) uses this list. Viewing a report needs `governance.view`.
 */
export const BOARD_REPORTS_LABEL = 'Board reports';

export type BoardReportKey =
    | 'board'
    | 'notifications'
    | 'investigation-outcomes'
    | 'action-traceability'
    | 'risk-register';

export type BoardReport = {
    key: BoardReportKey;
    label: string;
    description: string;
};

export const BOARD_REPORTS: readonly BoardReport[] = [
    {
        key: 'board',
        label: 'Board safety summary',
        description: 'How we’re doing on health and safety',
    },
    {
        key: 'notifications',
        label: 'WorkSafe notifications',
        description:
            'What we must tell WorkSafe about, and where each is up to',
    },
    {
        key: 'investigation-outcomes',
        label: 'Investigation outcomes',
        description: 'What investigations found and what we learnt',
    },
    {
        key: 'action-traceability',
        label: 'Actions and their safety reviews',
        description: 'Each action and the safety review it came from',
    },
    {
        key: 'risk-register',
        label: 'Risk assessment report',
        description: 'Current risk assessments and their risk levels',
    },
] as const;

export type BoardReportFilters = {
    from?: string | null;
    to?: string | null;
    site_id?: number | string | null;
};

/** The readable report page, keeping only the filters the page accepts. */
export function boardReportHref(
    key: BoardReportKey,
    filters: BoardReportFilters = {},
): string {
    const query = new URLSearchParams({ view: key });
    if (filters.from) query.set('from', String(filters.from));
    if (filters.to) query.set('to', String(filters.to));
    if (
        filters.site_id !== null &&
        filters.site_id !== undefined &&
        filters.site_id !== ''
    ) {
        query.set('site_id', String(filters.site_id));
    }
    return `/health-safety/reports?${query.toString()}`;
}

/** The menu items (label + href) for a board-report dropdown. */
export function boardReportLinks(
    filters: BoardReportFilters = {},
): Array<{
    key: BoardReportKey;
    label: string;
    description: string;
    href: string;
}> {
    return BOARD_REPORTS.map((report) => ({
        ...report,
        href: boardReportHref(report.key, filters),
    }));
}
