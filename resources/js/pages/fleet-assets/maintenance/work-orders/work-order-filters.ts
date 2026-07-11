export type WorkOrderFilters = {
    status?: string;
    priority?: string;
    asset_id?: string;
    overdue?: string;
};

export function mergeWorkOrderFilters(
    currentFilters: WorkOrderFilters,
    nextFilters: Partial<WorkOrderFilters>,
): WorkOrderFilters {
    const mergedFilters = { ...currentFilters, ...nextFilters };

    if ('status' in nextFilters && !('overdue' in nextFilters)) {
        delete mergedFilters.overdue;
    }

    return mergedFilters;
}

export function workOrderStatusFilterValue(filters: WorkOrderFilters): string {
    return filters.overdue === '1' ? 'overdue' : filters.status || 'all';
}

export function workOrderStatusFilterUpdate(
    value: string,
): Partial<WorkOrderFilters> {
    if (value === 'overdue') {
        return { status: '', overdue: '1' };
    }

    return { status: value === 'all' ? '' : value };
}
