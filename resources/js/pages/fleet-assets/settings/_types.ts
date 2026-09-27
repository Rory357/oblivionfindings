export type Channel = 'inapp' | 'email';
export type Overrides = Record<string, Partial<Record<Channel, boolean>>>;
export type NotificationEvent = {
    key: string;
    title: string;
    description: string;
    owner: string;
    href: string;
    defaults: Record<Channel, boolean>;
    defaultSource: string;
    effective: Record<Channel, boolean>;
};
export type NotificationSnapshot = {
    revision: string;
    overrides: Overrides;
    events: NotificationEvent[];
    channels: Record<
        Channel,
        { available: boolean; label: string; detail: string }
    >;
};
export type Check = {
    id?: number;
    event: string;
    revision: string;
    checked_at: string;
    mode: 'dry_run';
    source: string;
    inapp: string;
    email: string;
};
export type MapValues = {
    google: boolean;
    project: string;
    display: boolean;
    places: boolean;
    geocoding: boolean;
    routes: boolean;
    restrictions_reviewed: boolean;
    terms_reviewed: boolean;
};
export type MapSnapshot = {
    revision: string;
    values: MapValues;
    credentials: { browser: boolean; server: boolean };
    capabilities: {
        key: string;
        title: string;
        enabled: boolean;
        status: string;
    }[];
};
export function mergeMapDraft(
    base: MapValues,
    draft: MapValues,
    latest: MapValues,
): MapValues {
    return Object.fromEntries(
        Object.keys(latest).map((key) => [
            key,
            draft[key as keyof MapValues] !== base[key as keyof MapValues]
                ? draft[key as keyof MapValues]
                : latest[key as keyof MapValues],
        ]),
    ) as MapValues;
}
export type Policy = {
    key: string;
    title: string;
    value: string;
    detail: string;
    owner: string;
};
export type Page<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
};
export const channels: Channel[] = ['inapp', 'email'];
export const channelName = (channel: Channel) =>
    channel === 'inapp' ? 'In-app' : 'Email';
export const effective = (
    event: NotificationEvent,
    overrides: Overrides,
    channel: Channel,
) => overrides[event.key]?.[channel] ?? event.defaults[channel];
export function clean(input: Overrides): Overrides {
    return Object.fromEntries(
        Object.entries(input)
            .sort(([a], [b]) => a.localeCompare(b))
            .map(([key, values]) => [
                key,
                Object.fromEntries(
                    channels
                        .filter(
                            (channel) => typeof values[channel] === 'boolean',
                        )
                        .map((channel) => [channel, values[channel]]),
                ),
            ])
            .filter(([, values]) => Object.keys(values).length),
    );
}
export function changedChannels(before: Overrides, after: Overrides) {
    return [
        ...new Set([...Object.keys(before), ...Object.keys(after)]),
    ].flatMap((key) =>
        channels
            .filter(
                (channel) => before[key]?.[channel] !== after[key]?.[channel],
            )
            .map((channel) => ({ key, channel })),
    );
}
/** Preserve touched channels, adopt current values for untouched channels. */
export function mergeDraft(
    base: Overrides,
    draft: Overrides,
    latest: Overrides,
): Overrides {
    const result = structuredClone(latest);
    changedChannels(base, draft).forEach(({ key, channel }) => {
        result[key] ??= {};
        if (draft[key]?.[channel] === undefined) delete result[key][channel];
        else result[key][channel] = draft[key][channel];
    });
    return clean(result);
}
