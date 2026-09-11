/** Historical events without a self marker were self-subscriptions. */
export function ticketWatcherActivity(
    type: 'watcher_added' | 'watcher_removed',
    payload: Record<string, unknown> | null,
): string {
    if (payload?.self !== false)
        return type === 'watcher_added'
            ? 'started watching'
            : 'stopped watching';
    const name =
        typeof payload.watcher_name === 'string'
            ? payload.watcher_name.trim()
            : '';
    return type === 'watcher_added'
        ? name
            ? `added ${name} as a watcher`
            : 'added a watcher'
        : name
          ? `removed ${name} as a watcher`
          : 'removed a watcher';
}
