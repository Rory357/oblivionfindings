import { toDatetimeLocal } from '@/lib/datetime';
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
): boolean {
    return (
        Number.isFinite(allocatedHours) &&
        Number.isFinite(totalHours) &&
        Math.round(totalHours * 100) === Math.round(allocatedHours * 100)
    );
}

/** Resolve NZ wall time without using the desktop's configured timezone.
 * Two results mean the clock repeats; the worker must choose the occurrence. */
export function allocationTimeChoices(wall: string): string[] {
    if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(wall)) return [];
    const nominal = Date.parse(`${wall}:00Z`);
    if (!Number.isFinite(nominal)) return [];
    const offsets = new Set(
        [-2, -1, 0, 1, 2].map((days) => {
            const at = nominal + days * 86_400_000;
            return Date.parse(`${toDatetimeLocal(at)}:00Z`) - at;
        }),
    );
    return [...offsets]
        .map((offset) => new Date(nominal - offset).toISOString())
        .filter((iso) => toDatetimeLocal(iso) === wall)
        .sort();
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
