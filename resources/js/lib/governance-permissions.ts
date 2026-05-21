/**
 * Helpers for reading the `auth.can.governance.*` permission map shared via Inertia.
 *
 * All sections and tiles in the cockpit gate themselves on permissions — never
 * on a raw role string — so behaviour stays consistent regardless of how a
 * user's role is configured.
 */

export type GovernancePermissionMap = Record<string, unknown> | null | undefined;

/**
 * Read a `section.action` permission flag from `auth.can.governance`.
 *
 * Defaults to `true` for missing keys so that pages don't blank out when a
 * new permission is introduced before the seeder/Inertia middleware catches up.
 */
export function canDoGovernance(
    can: GovernancePermissionMap,
    section: string,
    action?: string,
): boolean {
    if (can == null) return false;
    const sec = (can as Record<string, unknown>)[section];
    if (sec === undefined || sec === null) return true;
    if (typeof sec === 'boolean') return sec;
    if (action && typeof sec === 'object') {
        const value = (sec as Record<string, unknown>)[action];
        if (value === undefined) return true;
        return Boolean(value);
    }
    return true;
}

/**
 * True when the user has at least one of the given `section.action` permissions.
 * Used by sidebar group filters and role quick action tiles.
 */
export function hasAnyGovernancePermission(
    can: GovernancePermissionMap,
    keys: Array<string | [string, string]>,
): boolean {
    return keys.some((key) => {
        if (Array.isArray(key)) {
            return canDoGovernance(can, key[0], key[1]);
        }
        const [section, action] = key.split('.');
        return canDoGovernance(can, section, action);
    });
}

/**
 * Coarse role detection used to pick layout presets (treasurer pins finance,
 * chair pins meeting readiness, etc). Permission flags still gate every action;
 * this just chooses which preset tile-set to surface first.
 */
export type GovernanceRolePreset =
    | 'chair'
    | 'secretary'
    | 'treasurer'
    | 'board_member'
    | 'observer'
    | 'executive'
    | 'admin';

export function detectRolePreset(
    boardRole: string | null | undefined,
    userRole: string | null | undefined,
): GovernanceRolePreset {
    const board = (boardRole ?? '').toLowerCase();
    const user = (userRole ?? '').toLowerCase();

    if (board === 'treasurer' || user.includes('treasurer')) return 'treasurer';
    if (board === 'chair' || user.includes('chair')) return 'chair';
    if (board === 'secretary' || user.includes('secretary')) return 'secretary';
    if (board === 'observer' || user.includes('observer')) return 'observer';
    if (board === 'member' || board === 'board_member') return 'board_member';
    if (user.includes('admin')) return 'admin';
    if (user.includes('executive') || user.includes('ceo')) return 'executive';

    return 'board_member';
}
