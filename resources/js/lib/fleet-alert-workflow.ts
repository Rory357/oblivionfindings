export type FleetAlertAction = 'acknowledge' | 'triage' | 'resolve';

type AlertWithStatus = {
    status: string;
};

const NEXT_ACTION_BY_STATUS: Record<string, FleetAlertAction | undefined> = {
    open: 'acknowledge',
    ack: 'triage',
    triaging: 'resolve',
    confirmed: 'resolve',
};

export function fleetAlertNextAction(status: string): FleetAlertAction | null {
    return NEXT_ACTION_BY_STATUS[status] ?? null;
}

export function isFleetAlertActionEligible(
    status: string,
    action: FleetAlertAction,
): boolean {
    return fleetAlertNextAction(status) === action;
}

export function countFleetAlertActions(
    alerts: AlertWithStatus[],
): Record<FleetAlertAction, number> {
    const counts: Record<FleetAlertAction, number> = {
        acknowledge: 0,
        triage: 0,
        resolve: 0,
    };

    for (const alert of alerts) {
        const action = fleetAlertNextAction(alert.status);
        if (action) counts[action]++;
    }

    return counts;
}
