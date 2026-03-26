import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import RespiteSubnav from '@/components/respite-subnav';
import { formatDateTime } from '@/lib/date-format';
import { Head, Link } from '@inertiajs/react';

type Props = {
    note: any;
    wellbeingSummary: any;
    wellbeingScore: number;
};

export default function DailyNoteShow({ note, wellbeingSummary, wellbeingScore }: Props) {
    return (
        <AppLayout breadcrumbs={[
            { title: 'Respite', href: '/respite' },
            { title: 'Daily Notes', href: '/respite/daily-notes' },
            { title: `Note #${note.id}`, href: `/respite/daily-notes/${note.id}` },
        ]}>
            <Head title="Daily Note" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">
                            Daily Note &mdash; {note.stay?.client?.first_name} {note.stay?.client?.last_name}
                        </h1>
                        <div className="mt-2 flex flex-wrap gap-2">
                            <Badge variant="outline">{note.shift_period}</Badge>
                            {note.has_concerns && <Badge variant="outline">Concern</Badge>}
                            {note.incident_occurred && <Badge variant="outline">Incident</Badge>}
                            {note.sensitive_flag && <Badge variant="outline">Sensitive</Badge>}
                        </div>
                    </div>
                    <Link href="/respite/daily-notes" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                        Back to list
                    </Link>
                </div>
                <RespiteSubnav />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Wellbeing</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="flex flex-wrap gap-3">
                            {note.mood && (
                                <div className="text-sm">
                                    <span className="text-slate-500">Mood:</span> <Badge variant="outline">{note.mood}</Badge>
                                </div>
                            )}
                            {note.appetite && (
                                <div className="text-sm">
                                    <span className="text-slate-500">Appetite:</span> <Badge variant="outline">{note.appetite}</Badge>
                                </div>
                            )}
                            {note.sleep_quality && (
                                <div className="text-sm">
                                    <span className="text-slate-500">Sleep:</span> <Badge variant="outline">{note.sleep_quality}</Badge>
                                </div>
                            )}
                            {note.engagement && (
                                <div className="text-sm">
                                    <span className="text-slate-500">Engagement:</span> <Badge variant="outline">{note.engagement}</Badge>
                                </div>
                            )}
                            {note.mobility && (
                                <div className="text-sm">
                                    <span className="text-slate-500">Mobility:</span> <Badge variant="outline">{note.mobility}</Badge>
                                </div>
                            )}
                        </div>
                        <div className="text-sm text-slate-600">
                            Wellbeing Score: <span className="font-semibold">{wellbeingScore}</span>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Note Content</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4 text-sm text-slate-600">
                        {note.activities && (
                            <div>
                                <div className="font-medium text-slate-700">Activities</div>
                                <div className="mt-1 whitespace-pre-wrap">{note.activities}</div>
                            </div>
                        )}
                        {note.observations && (
                            <div>
                                <div className="font-medium text-slate-700">Observations</div>
                                <div className="mt-1 whitespace-pre-wrap">{note.observations}</div>
                            </div>
                        )}
                        {note.concerns && (
                            <div>
                                <div className="font-medium text-slate-700">Concerns</div>
                                <div className="mt-1 whitespace-pre-wrap">{note.concerns}</div>
                            </div>
                        )}
                        {note.goals_progress && (
                            <div>
                                <div className="font-medium text-slate-700">Goals Progress</div>
                                <div className="mt-1 whitespace-pre-wrap">{note.goals_progress}</div>
                            </div>
                        )}
                        {!note.activities && !note.observations && !note.concerns && !note.goals_progress && (
                            <div className="py-4 text-center text-sm text-slate-500">No content recorded.</div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Meta</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2 text-sm text-slate-600">
                        <div>Shift Period: <Badge variant="outline">{note.shift_period}</Badge></div>
                        <div>Note Date: {formatDateTime(note.note_date)}</div>
                        {note.created_by && <div>Created by: {note.created_by.name || note.created_by}</div>}
                        {note.incident_occurred && note.linked_incident && (
                            <div>
                                Linked Incident:{' '}
                                <Link href={`/incidents/${note.linked_incident.id}`} className="text-indigo-500 hover:text-indigo-400">
                                    #{note.linked_incident.id}
                                </Link>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
