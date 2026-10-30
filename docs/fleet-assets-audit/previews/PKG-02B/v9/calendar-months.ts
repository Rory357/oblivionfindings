/** Calendar-month recurrence, clamped to the final day of the target month. */
export function addMonths(value: string, months: number): string {
    const [year, month, day] = value.split('-').map(Number);
    const target = new Date(Date.UTC(year, month - 1 + months, 1));
    const last = new Date(
        Date.UTC(target.getUTCFullYear(), target.getUTCMonth() + 1, 0),
    ).getUTCDate();
    target.setUTCDate(Math.min(day, last));
    return target.toISOString().slice(0, 10);
}
