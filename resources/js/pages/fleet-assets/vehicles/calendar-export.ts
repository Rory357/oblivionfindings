import type { FleetEvent } from './fleet-calendar';

/** Export only the permitted visible projection, never hidden source-record fields. */
export function calendarCsv(
    events: FleetEvent[],
    vehicleName: (id: number) => string,
): string {
    const cell = (value: string | null) => {
        const text = value ?? '';
        return `"${(/^[=+\-@\t\r]/.test(text) ? `'${text}` : text).replaceAll('"', '""')}"`;
    };
    return [
        [
            'Vehicle',
            'Entry',
            'Start (ISO offset)',
            'End (ISO offset)',
            'Source',
            'State',
        ],
        ...events.map((event) => [
            vehicleName(event.vehicleId),
            event.kind === 'busy' ? 'Busy' : event.title,
            event.start,
            event.end,
            event.kind === 'busy' ? 'Busy only' : event.kind,
            event.statusLabel,
        ]),
    ]
        .map((row) => row.map(cell).join(','))
        .join('\r\n');
}
