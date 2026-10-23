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
                'Confirm or dismiss what the sensor picked up before reporting anything.',
        };
    }

    if (!input.incident && input.can.createIncident) {
        return {
            key: 'create_incident',
            label: 'Report incident',
            href: null,
            statusText:
                'Reporting it hands the follow-up to Health & Safety.',
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
            statusText: 'Health & Safety has taken on the follow-up.',
        };
    }

    if (input.incident && input.can.viewIncident) {
        return {
            key: 'open_incident',
            label: 'Open incident',
            href: input.incident.href,
            statusText:
                input.healthSafety?.handoverStatus === 'awaiting_acceptance'
                    ? 'Waiting for Health & Safety to take it on.'
                    : 'The incident report is the record of what happened.',
        };
    }

    return {
        key: 'continue_response',
        label: 'Continue response',
        href: null,
        statusText:
            input.alertStatus === 'resolved' || input.alertStatus === 'closed'
                ? 'Nothing more to do here.'
                : 'Carry on the response here in Control Room.',
    };
}
