/**
 * Stable hash → hue (0–360) for resident avatar tints.
 *
 * Mirrors `app/Support/ResidentHue.php` so the server and client agree on the
 * colour a given resident gets. Do not change the algorithm without updating
 * the PHP side; otherwise residents' avatars will flip colour mid-page.
 */
export function residentHue(clientId: number | string): number {
    const s = String(clientId);
    let h = 2166136261 >>> 0; // FNV-1a 32-bit offset basis (unsigned)
    for (let i = 0; i < s.length; i++) {
        h = (h ^ s.charCodeAt(i)) >>> 0;
        // 16777619 = FNV-1a prime. Math.imul handles 32-bit multiply; >>> 0 keeps it unsigned
        // so the result matches PHP's `($h * 16777619) & 0xFFFFFFFF` byte-for-byte.
        h = Math.imul(h, 16777619) >>> 0;
    }
    return h % 360;
}

/**
 * Compose a resident's initials from first/last name.
 */
export function residentInitials(
    firstName: string,
    lastName?: string | null,
): string {
    const a = firstName?.charAt(0) ?? '';
    const b = lastName?.charAt(0) ?? '';
    return `${a}${b}`.toUpperCase();
}
