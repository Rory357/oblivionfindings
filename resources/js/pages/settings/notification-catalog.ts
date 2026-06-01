export type NotificationMeta = {
    name: string;
    description: string;
};

export type NotificationModule = {
    label: string;
    colour: string;
    keys: string[];
};

export type NotificationModuleGroup = NotificationModule & {
    moduleKey: string;
};

export const NOTIFICATION_META: Record<string, NotificationMeta> = {
    'timesheets.created': {
        name: 'Timesheet Created',
        description: 'When a new timesheet is created',
    },
    'timesheets.updated': {
        name: 'Timesheet Updated',
        description: 'When a timesheet is modified',
    },
    'timesheets.submitted': {
        name: 'Timesheet Submitted',
        description: 'When a timesheet is submitted for approval',
    },
    'timesheets.approved': {
        name: 'Timesheet Approved',
        description: 'When a timesheet is approved by a manager',
    },
    'timesheets.rejected': {
        name: 'Timesheet Rejected',
        description: 'When a timesheet is rejected and needs changes',
    },
    'timesheets.returned': {
        name: 'Timesheet Returned',
        description: 'When a timesheet is returned for corrections',
    },
    'incidents.draft_created': {
        name: 'Incident Draft Created',
        description: 'When a new incident report draft is started',
    },
    'incidents.submitted': {
        name: 'Incident Submitted',
        description: 'When an incident report is submitted for review',
    },
    'incidents.reviewed': {
        name: 'Incident Reviewed',
        description: 'When an incident report review is completed',
    },
    'incidents.high_severity_alert': {
        name: 'High Severity Alert',
        description: 'Immediate alert for high-severity incidents',
    },
    'breakglass.daily_report': {
        name: 'Break Glass Daily Report',
        description: 'Daily summary of break glass events',
    },
    'incidents.high_unreviewed_reminder': {
        name: 'High Severity Unreviewed Reminder',
        description: 'Reminder for unreviewed high-severity incidents',
    },
    'followups.created': {
        name: 'Follow-up Created',
        description: 'When a new follow-up task is created',
    },
    'followups.updated': {
        name: 'Follow-up Updated',
        description: 'When a follow-up task is modified',
    },
    'followups.completed': {
        name: 'Follow-up Completed',
        description: 'When a follow-up task is marked complete',
    },
    'followups.overdue_reminder': {
        name: 'Follow-up Overdue Reminder',
        description: 'Reminder for overdue follow-up tasks',
    },
    shift_task_due: {
        name: 'Shift Task Due',
        description: 'When a time-specific shift task is due now',
    },
};

export const NOTIFICATION_MODULES: Record<string, NotificationModule> = {
    operations: {
        label: 'Operations',
        colour: 'violet',
        keys: [
            'timesheets.created',
            'timesheets.updated',
            'timesheets.submitted',
            'timesheets.approved',
            'timesheets.rejected',
            'timesheets.returned',
        ],
    },
    rostering: {
        label: 'Rostering',
        colour: 'violet',
        keys: ['shift_task_due'],
    },
    incidents: {
        label: 'Incidents & Safety',
        colour: 'red',
        keys: [
            'incidents.draft_created',
            'incidents.submitted',
            'incidents.reviewed',
            'incidents.high_severity_alert',
            'breakglass.daily_report',
            'incidents.high_unreviewed_reminder',
        ],
    },
    followups: {
        label: 'Follow-ups',
        colour: 'emerald',
        keys: [
            'followups.created',
            'followups.updated',
            'followups.completed',
            'followups.overdue_reminder',
        ],
    },
};

export function friendlyNotificationName(key: string): string {
    return (
        NOTIFICATION_META[key]?.name ??
        key
            .replace(/\./g, ' ')
            .replace(/_/g, ' ')
            .replace(/\b\w/g, (c) => c.toUpperCase())
    );
}

export function friendlyNotificationDescription(key: string): string {
    return NOTIFICATION_META[key]?.description ?? '';
}

export function groupNotificationKeysByModule(
    allKeys: string[],
): NotificationModuleGroup[] {
    const assigned = new Set<string>();
    const result: NotificationModuleGroup[] = [];

    for (const [moduleKey, config] of Object.entries(NOTIFICATION_MODULES)) {
        const matched = config.keys.filter((key) => allKeys.includes(key));
        if (matched.length > 0) {
            result.push({
                moduleKey,
                ...config,
                keys: matched,
            });
            matched.forEach((key) => assigned.add(key));
        }
    }

    const remaining = allKeys.filter((key) => !assigned.has(key));
    if (remaining.length > 0) {
        result.push({
            moduleKey: 'other',
            label: 'Other',
            colour: 'slate',
            keys: remaining,
        });
    }

    return result;
}
