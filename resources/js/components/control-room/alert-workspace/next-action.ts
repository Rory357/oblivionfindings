export type LinkedIncident = {
    referenceNumber: string;
    href: string | null;
};

export type LinkedHealthSafety = {
    referenceNumber: string;
    handoverStatus: string;
    href: string | null;
};

export type AlertNextActionInput = {
    alertStatus: string;
    sensorConfirmationRequired: boolean;
    incident: LinkedIncident | null;
    healthSafety: LinkedHealthSafety | null;
    can: {
        manage: boolean;
        createIncident: boolean;
        viewIncident: boolean;
        viewHealthSafety: boolean;
    };
};

export type AlertNextAction = {
    key:
        | 'confirm_sensor'
        | 'create_incident'
        | 'open_incident'
        | 'continue_health_safety'
        | 'continue_response';
    label: string;
    href: string | null;
    statusText?: string;
};

export function nextAlertAction(input: AlertNextActionInput): AlertNextAction {
    if (input.sensorConfirmationRequired && input.can.manage) {
        return {
            key: 'confirm_sensor',
            label: 'Confirm detection',
            href: null,
            statusText:
                'Confirm or dismiss the sensor detection before creating a formal record.',
        };
    }

    if (!input.incident && input.can.createIncident) {
        return {
            key: 'create_incident',
            label: 'Create incident and hand over',
            href: null,
            statusText:
                'Creates one official incident and opens its H&S handover.',
        };
    }

    if (
        input.healthSafety?.handoverStatus === 'accepted' &&
        input.can.viewHealthSafety &&
        input.healthSafety.href
    ) {
        return {
            key: 'continue_health_safety',
            label: 'Continue in H&S',
            href: input.healthSafety.href,
            statusText: 'H&S has accepted ownership of the governance work.',
        };
    }

    if (input.incident && input.can.viewIncident) {
        return {
            key: 'open_incident',
            label: 'Open incident',
            href: input.incident.href,
            statusText:
                input.healthSafety?.handoverStatus === 'awaiting_acceptance'
                    ? 'Waiting for H&S acceptance'
                    : 'The incident is the official record of what happened.',
        };
    }

    return {
        key: 'continue_response',
        label: 'Continue response',
        href: null,
        statusText:
            input.alertStatus === 'resolved' || input.alertStatus === 'closed'
                ? 'No further operational action is available.'
                : 'Continue the operational response in Control Room.',
    };
}
