/** Stable per-person identity hue (0–359) from a numeric id — used for the
 *  avatar gradients on kudos / celebrations / who's-out / teammate tiles, where
 *  the colour is decorative identity (akin to resident-hue), not a theme token.
 *  Uses the golden angle so adjacent ids get well-separated hues. */
export function hueFromId(id: number): number {
    return Math.round(Math.abs(id) * 137.508) % 360;
}

/** Two-letter initials from a name. */
export function initialsOf(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .map((p) => p[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();
}

/** Relative "time ago" label, en-NZ flavoured. */
export function timeAgo(iso: string | null | undefined): string {
    if (!iso) return '';
    const then = new Date(iso).getTime();
    const secs = Math.max(0, Math.floor((Date.now() - then) / 1000));
    if (secs < 60) return 'just now';
    const mins = Math.floor(secs / 60);
    if (mins < 60) return `${mins}m ago`;
    const hours = Math.floor(mins / 60);
    if (hours < 24) return `${hours}h ago`;
    const days = Math.floor(hours / 24);
    if (days < 7) return `${days}d ago`;
    return new Date(iso).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
    });
}
