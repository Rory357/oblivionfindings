import type { TimesheetAllocationMethod } from './types';

export function splitHoursEvenly(
    totalHours: number,
    rowCount: number,
): string[] {
    if (rowCount <= 0) {
        return [];
    }

    const safeTotal = Number.isFinite(totalHours) ? Math.max(totalHours, 0) : 0;
    const totalHundredths = Math.round(safeTotal * 100);
    const baseHundredths = Math.floor(totalHundredths / rowCount);
    const remainder = totalHundredths - baseHundredths * rowCount;

    return Array.from({ length: rowCount }, (_, index) => {
        const receivesRemainder = index >= rowCount - remainder;
        const hundredths = baseHundredths + (receivesRemainder ? 1 : 0);

        return (hundredths / 100).toFixed(2);
    });
}

export function isAllocationBalanced(
    _method: TimesheetAllocationMethod,
    allocatedHours: number,
    totalHours: number,
    tolerance = 0.02,
): boolean {
    return Math.abs(totalHours - allocatedHours) <= tolerance;
}

export function allocationErrorForRow(
    errors: Record<string, string>,
    rowIndex: number,
    clientId: number,
    field: string,
): string | undefined {
    return (
        errors[`client_allocations.${rowIndex}.${field}`] ??
        errors[`client_allocations.${clientId}.${field}`]
    );
}
