import {
    toDatetimeLocal,
    workerTimeOffset,
    workerTimeOffsets,
} from '@/lib/datetime';

/** Calendar editors use the same NZ wall time as worker-facing event cards. */
export function eventTimeInput(value: string, allDay: boolean): string {
    if (!value) return '';
    if (allDay) return value.slice(0, 10);
    return /(?:Z|[+-]\d{2}:\d{2})$/i.test(value)
        ? toDatetimeLocal(value)
        : value.slice(0, 16);
}

export function eventTimeInstant(
    value: string,
    allDay: boolean,
    original?: string,
): string {
    if (!value || allDay) return value;
    const choices = workerTimeOffsets(value);
    if (!choices.length)
        throw new Error(
            'This New Zealand time does not exist because the clocks change. Choose another time.',
        );
    const originalOffset =
        original && eventTimeInput(original, false) === value
            ? workerTimeOffset(original)
            : '';
    if (choices.length > 1 && !choices.includes(originalOffset))
        throw new Error(
            'This New Zealand time occurs twice when the clocks change. Choose a time outside the repeated hour.',
        );
    return new Date(
        `${value}:00${choices.includes(originalOffset) ? originalOffset : choices[0]}`,
    ).toISOString();
}
