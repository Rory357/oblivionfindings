export const JOURNEY_ACTIVITY_LABELS: Record<string, string> = {
    'healthSafety.correctiveAction.completed': 'Owner submitted evidence',
    'healthSafety.correctiveAction.returnedForRework':
        'Action returned for rework',
    'healthSafety.correctiveAction.verified': 'Action independently verified',
    'healthSafety.correctiveAction.created': 'Corrective action created',
    'healthSafety.correctiveAction.handedOver':
        'Corrective action handed to its owner',
    'healthSafety.event.handoverAccepted': 'H&S handover accepted',
    'healthSafety.investigation.recommendationDispositioned':
        'Recommendation outcome recorded',
    'controlRoom.shift.handoverPrepared': 'Shift handover prepared',
    'controlRoom.shift.handoverAccepted': 'Incoming lead accepted handover',
    'controlRoom.alert.view': 'Alert opened',
    'controlRoom.alert.created': 'Alert raised',
    'controlRoom.alert.acknowledge': 'Alert acknowledged',
    'controlRoom.alert.triage': 'Alert triage started',
    'controlRoom.alert.snooze': 'Alert snoozed',
    'controlRoom.alert.confirm': 'Sensor alert confirmed',
    'controlRoom.alert.dismiss': 'Sensor alert dismissed',
    'controlRoom.alert.resolve': 'Alert operationally resolved',
    'controlRoom.alert.close': 'Alert closed',
    'controlRoom.alert.reopenForIncident': 'Alert reopened for incident review',
    'controlRoom.alert.reopenFromIncidentSignal':
        'Alert reopened for incident review',
    'controlRoom.alert.addNote': 'Operator note added',
    'controlRoom.task.created': 'Control Room task created',
    'controlRoom.task.updated': 'Control Room task updated',
    'controlRoom.task.statusChanged': 'Control Room task status changed',
    'controlRoom.task.transferredToHealthSafety': 'Task transferred to H&S',
    'controlRoom.watcher.added': 'Follower added',
    'controlRoom.watcher.removed': 'Follower removed',
    'hscorrectiveaction.create': 'Corrective action created',
    'hscorrectiveaction.update': 'Corrective action updated',
    'hsevent.create': 'H&S event created',
    'hsevent.update': 'H&S event updated',
};

export type JourneyTerm =
    | 'status'
    | 'severity'
    | 'priority'
    | 'escalation'
    | 'sla'
    | 'governance_stage';

export const JOURNEY_TERM_DEFINITIONS: Record<
    JourneyTerm,
    { label: string; definition: string }
> = {
    status: {
        label: 'Status',
        definition: 'The current lifecycle state.',
    },
    severity: {
        label: 'Severity',
        definition: 'Potential harm.',
    },
    priority: {
        label: 'Priority',
        definition: 'Work order.',
    },
    escalation: {
        label: 'Escalation',
        definition: 'Management attention level.',
    },
    sla: {
        label: 'SLA',
        definition: 'Required response time.',
    },
    governance_stage: {
        label: 'Governance stage',
        definition: 'Accountable review state.',
    },
};

export function journeyActivityLabel(action: string): string {
    return JOURNEY_ACTIVITY_LABELS[action] ?? 'Activity recorded';
}

export function journeyTermDefinition(term: JourneyTerm): string {
    return JOURNEY_TERM_DEFINITIONS[term].definition;
}
