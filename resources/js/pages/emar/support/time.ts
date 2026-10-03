/** Possible NZ offsets for an exact local minute; none in a gap, two at the repeat. */
export function supportTimeCandidates(wall: string): string[] {
    if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(wall)) return [];
    return ['+13:00', '+12:00']
        .filter((offset) => {
            const date = new Date(wall + offset);
            if (!Number.isFinite(date.getTime())) return false;
            const parts = new Intl.DateTimeFormat('en-NZ', {
                timeZone: 'Pacific/Auckland',
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                hourCycle: 'h23',
            }).formatToParts(date);
            const value = (key: string) =>
                parts.find((p) => p.type === key)?.value;
            return (
                value('year') +
                    '-' +
                    value('month') +
                    '-' +
                    value('day') +
                    'T' +
                    value('hour') +
                    ':' +
                    value('minute') ===
                wall
            );
        })
        .map((offset) => wall + offset);
}
