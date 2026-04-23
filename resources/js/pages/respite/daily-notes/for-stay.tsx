import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import RespiteSubnav from '@/components/respite-subnav';
import { formatDateTime } from '@/lib/date-format';
import { Head, Link } from '@inertiajs/react';

type Props = {
    stay: any;
    notes: any[];
    wellbeingTrend: any[];
};

export default function DailyNotesForStay({ stay, notes, wellbeingTrend }: Props) {
    return (
        <AppLayout breadcrumbs={[
            { title: 'Respite', href: '/respite' },
            { title: 'Stays', href: '/respite/stays' },
            { title: `${stay.client?.first_name} ${stay.client?.last_name}`, href: `/respite/stays/${stay.id}` },
            { title: 'Daily Notes', href: `/respite/stays/${stay.id}/daily-notes` },
        ]}>
            <Head title="Daily Notes for Stay" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">
                            Daily Notes for {stay.client?.first_name} {stay.client?.last_name}
                        </h1>
                        <div className="mt-1 text-sm text-muted-foreground">
                            {formatDateTime(stay.start_date)} &mdash; {formatDateTime(stay.end_date)}
                        </div>
                    </div>
                    <Link
                        href={`/respite/daily-notes/create?stay_id=${stay.id}`}
                        className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                    >
                        New Note
                    </Link>
                </div>
                <RespiteSubnav />

                {wellbeingTrend.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Wellbeing Trend</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-left text-xs text-muted-foreground">
                                            <th className="pb-2 pr-4">Date</th>
                                            <th className="pb-2 pr-4">Shift</th>
                                            <th className="pb-2 pr-4">Score</th>
                                            <th className="pb-2">Mood</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {wellbeingTrend.map((entry: any, i: number) => (
                                            <tr key={i} className="border-b last:border-0">
                                                <td className="py-2 pr-4">{formatDateTime(entry.date)}</td>
                                                <td className="py-2 pr-4"><Badge variant="outline">{entry.shift}</Badge></td>
                                                <td className="py-2 pr-4">{entry.score}</td>
                                                <td className="py-2"><Badge variant="outline">{entry.mood}</Badge></td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>
                )}

                <div className="space-y-2">
                    {notes.map((note: any) => (
                        <Card key={note.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="flex flex-wrap gap-2">
                                                <Badge variant="outline">{note.shift_period}</Badge>
                                                {note.mood && <Badge variant="outline">{note.mood}</Badge>}
                                                {note.has_concerns && <Badge variant="outline">Concern</Badge>}
                                                {note.incident_occurred && <Badge variant="outline">Incident</Badge>}
                                            </div>
                                            <div className="mt-2 text-xs text-muted-foreground">
                                                {formatDateTime(note.note_date)}
                                            </div>
                                        </div>
                                        <Link href={`/respite/daily-notes/${note.id}`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                            View
                                        </Link>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                        </Card>
                    ))}
                    {!notes.length && (
                        <div className="py-8 text-center text-sm text-muted-foreground">
                            No items found.
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
