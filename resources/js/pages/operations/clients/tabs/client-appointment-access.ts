export type ClientAppointmentAction = 'create' | 'edit' | 'cancel' | 'delete';

export type CalendarPermissions = {
    create?: boolean;
    manage?: boolean;
};

/**
 * Keep client-profile appointment controls aligned with the route-level split:
 * creation uses calendar.create; lifecycle mutations use calendar.manage.
 */
export function clientAppointmentActionAllowed(
    action: ClientAppointmentAction,
    permissions?: CalendarPermissions,
): boolean {
    return action === 'create'
        ? Boolean(permissions?.create)
        : Boolean(permissions?.manage);
}
