import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';

const sections: Array<{
    title: string;
    items: Array<{ label: string; why: string; expected: string }>;
}> = [
    {
        title: 'Access control (data leakage tests)',
        items: [
            {
                label: 'Support worker tries to open an unassigned client (direct URL)',
                why: 'Ensure client scoping is enforced server-side (not just UI).',
                expected: '403 + no client data rendered.',
            },
            {
                label: 'Next-of-kin tries to view staff-only notes / internal timeline',
                why: 'Portal must be strictly limited to approved data.',
                expected: '403 or sanitized view.',
            },
            {
                label: 'Support worker tries to edit a closed incident',
                why: 'Immutability after closure is required for audit integrity.',
                expected: 'Denied; show a “locked” message.',
            },
        ],
    },
    {
        title: 'Incidents (workflow guardrails)',
        items: [
            {
                label: 'Create incident → submit → review → close',
                why: 'Confirm end-to-end state transitions + timestamps.',
                expected:
                    'Status changes correctly; close available only after review.',
            },
            {
                label: 'Try to close before follow-ups are complete',
                why: 'Prevents premature closure when actions are outstanding.',
                expected: 'Blocked with clear error.',
            },
            {
                label: 'High severity triggers notifications/escalations',
                why: 'Critical events must alert the right roles/groups.',
                expected:
                    'Notifications created + delivered per escalation rules.',
            },
        ],
    },
    {
        title: 'Medication (MAR) safety cases',
        items: [
            {
                label: 'Record dose outside time window without reason',
                why: 'Require clinical rationale for early/late dosing.',
                expected: 'Blocked until reason supplied.',
            },
            {
                label: 'Controlled drug given without witness',
                why: 'Controlled medication handling requires witness.',
                expected:
                    'Blocked until a different authorised witness is selected.',
            },
            {
                label: 'Correction after 30 minutes without correction reason',
                why: 'Corrections must be auditable and justified.',
                expected: 'Blocked until correction reason is provided.',
            },
        ],
    },
    {
        title: 'Shifts (locking and completeness)',
        items: [
            {
                label: 'Complete shift with no progress notes and no summary',
                why: 'NZ-friendly audit trail: every shift needs a narrative.',
                expected:
                    'Blocked until a note exists or a summary is provided.',
            },
            {
                label: 'Try to mark tasks after shift completion',
                why: 'Shift record should be immutable after completion.',
                expected: 'Blocked with “locked” message.',
            },
        ],
    },
];

export default function QualityChecklist() {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'QA Checklist', href: '/quality/checklist' },
            ]}
        >
            <Head title="QA Checklist" />
            <div className="space-y-4 p-4">
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Unhappy-path testing checklist
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="text-sm text-muted-foreground">
                        Use this to deliberately try break the system. If any of
                        these fail, we fix it before adding new features.
                    </CardContent>
                </Card>

                {sections.map((s) => (
                    <Card key={s.title}>
                        <CardHeader>
                            <CardTitle className="text-base">
                                {s.title}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {s.items.map((i) => (
                                <div
                                    key={i.label}
                                    className="rounded-md border p-3"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div className="font-medium">
                                            {i.label}
                                        </div>
                                        <Badge variant="secondary">Test</Badge>
                                    </div>
                                    <div className="mt-2 text-sm">
                                        <div>
                                            <span className="text-muted-foreground">
                                                Why:
                                            </span>{' '}
                                            {i.why}
                                        </div>
                                        <div className="mt-1">
                                            <span className="text-muted-foreground">
                                                Expected:
                                            </span>{' '}
                                            {i.expected}
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                ))}
            </div>
        </AppLayout>
    );
}
