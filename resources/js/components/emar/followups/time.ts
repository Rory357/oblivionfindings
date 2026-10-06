/** Resolve the NZ wall clock without using the browser's timezone. */
export function nzFollowupInstants(
    value: string,
): { value: string; label: string }[] {
    const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(value);
    if (!match) return [];
    const [, y, m, d, h, minute] = match;
    const clock = Date.UTC(+y, +m - 1, +d, +h, +minute);
    const formatter = new Intl.DateTimeFormat('en-NZ', {
        timeZone: 'Pacific/Auckland',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    });
    return [13, 12].flatMap((offset) => {
        const parts = Object.fromEntries(
            formatter
                .formatToParts(new Date(clock - offset * 3600000))
                .map((p) => [p.type, p.value]),
        );
        const actual = `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
        return actual === value
            ? [
                  {
                      value: `${value}:00+${offset}:00`,
                      label:
                          offset === 13
                              ? 'NZ daylight time (+13:00)'
                              : 'NZ standard time (+12:00)',
                  },
              ]
            : [];
    });
}
