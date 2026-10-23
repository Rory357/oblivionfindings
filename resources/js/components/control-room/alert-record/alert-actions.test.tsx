import { describe, expect, it } from 'vitest';
import { alertActions, primaryAlertAction } from './alert-actions';
import { alertDetail } from './test-fixtures';

const keys = (d: ReturnType<typeof alertDetail>) => alertActions(d).map((a) => a.key);

describe('alert actions', () => {
    it('offers the one next step for each state', () => {
        expect(primaryAlertAction(alertDetail())?.label).toBe('Acknowledge');
        expect(primaryAlertAction(alertDetail({ alert: { status: 'ack' } }))?.label).toBe(
            'Start triage',
        );
        expect(primaryAlertAction(alertDetail({ alert: { status: 'triaging' } }))?.label).toBe(
            'Resolve',
        );
        expect(primaryAlertAction(alertDetail({ alert: { status: 'resolved' } }))?.label).toBe(
            'Close alert',
        );
        expect(primaryAlertAction(alertDetail({ alert: { status: 'closed' } }))).toBeNull();
        expect(primaryAlertAction(alertDetail({ can: { manage: false } }))).toBeNull();
    });

    it('never offers snooze on a critical alert', () => {
        expect(keys(alertDetail())).toContain('snooze');
        expect(keys(alertDetail({ alert: { severity: 'critical' } }))).not.toContain('snooze');
    });

    it('uses "Report incident" and hides it once an incident exists', () => {
        const items = alertActions(alertDetail());
        expect(items.find((a) => a.key === 'report_incident')?.label).toBe('Report incident');
        expect(
            keys(
                alertDetail({
                    linked_incident: {
                        id: 1,
                        reference_number: 'INC-1',
                        status: 'open',
                        severity: 'high',
                        title: null,
                        href: '/incidents/1',
                    },
                }),
            ),
        ).not.toContain('report_incident');
    });

    it('asks sensor alerts to be confirmed or dismissed instead', () => {
        const sensor = keys(alertDetail({ alert: { source: 'sensor' } }));
        expect(sensor).toContain('confirm_sensor');
        expect(sensor).toContain('dismiss_sensor');
        expect(sensor).not.toContain('report_incident');
        const dismiss = alertActions(alertDetail({ alert: { source: 'sensor' } })).find(
            (a) => a.key === 'dismiss_sensor',
        );
        expect(dismiss?.tone).toBe('critical');
    });

    it('shows only what this person may do', () => {
        const reader = keys(
            alertDetail({
                can: { manage: false, assign: false, escalate: false, create_incident: false, watch: false },
            }),
        );
        expect(reader).toEqual([]);
    });
});
