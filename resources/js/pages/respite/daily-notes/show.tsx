import { PageHero, PageLayout } from '@/components/page';
import RespiteSubnav from '@/components/respite-subnav';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { formatDateTimeLong } from '@/lib/datetime';
import { Head, Link } from '@inertiajs/react';

type Props = {
    note: any;
    wellbeingSummary: any;
    wellbeingScore: number;
};

export default function DailyNoteShow({
    note,
    wellbeingSummary,
    wellbeingScore,
}: Props) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Respite', href: '/respite' },
                { title: 'Daily Notes', href: '/respite/daily-notes' },
                {
                    title: `Note #${note.id}`,
                    href: `/respite/daily-notes/${note.id}`,
                },
            ]}
        >
            <Head title="Daily Note" />

            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref="/respite/daily-notes"
                        title={`Daily Note — ${note.stay?.client?.first_name ?? ''} ${note.stay?.client?.last_name ?? ''}`.trim()}
                        actions={
                            <div className="flex flex-wrap gap-2">
                                <Badge variant="outline">
                                    {note.shift_period}
                                </Badge>
                                {note.has_concerns && (
                                    <Badge variant="outline">Concern</Badge>
                                )}
                                {note.incident_occurred && (
                                    <Badge variant="outline">Incident</Badge>
                                )}
                                {note.sensitive_flag && (
                                    <Badge variant="outline">Sensitive</Badge>
                                )}
                            </div>
                        }
                    />
                }
            >
                <RespiteSubnav />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Wellbeing</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="flex flex-wrap gap-3">
                            {note.mood && (
                                <div className="text-sm">
                                    <span className="text-muted-foreground">
                                        Mood:
                                    </span>{' '}
                                    <Badge variant="outline">{note.mood}</Badge>
                                </div>
                            )}
                            {note.appetite && (
                                <div className="text-sm">
                                    <span className="text-muted-foreground">
                                        Appetite:
                                    </span>{' '}
                                    <Badge variant="outline">
                                        {note.appetite}
                                    </Badge>
                                </div>
                            )}
                            {note.sleep_quality && (
                                <div className="text-sm">
                                    <span className="text-muted-foreground">
                                        Sleep:
                                    </span>{' '}
                                    <Badge variant="outline">
                                        {note.sleep_quality}
                                    </Badge>
                                </div>
                            )}
                            {note.engagement && (
                                <div className="text-sm">
                                    <span className="text-muted-foreground">
                                        Engagement:
                                    </span>{' '}
                                    <Badge variant="outline">
                                        {note.engagement}
                                    </Badge>
                                </div>
                            )}
                            {note.mobility && (
                                <div className="text-sm">
                                    <span className="text-muted-foreground">
                                        Mobility:
                                    </span>{' '}
                                    <Badge variant="outline">
                                        {note.mobility}
                                    </Badge>
                                </div>
                            )}
                        </div>
                        <div className="text-sm text-muted-foreground">
                            Wellbeing Score:{' '}
                            <span className="font-semibold">
                                {wellbeingScore}
                            </span>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Note Content
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4 text-sm text-muted-foreground">
                        {note.activities && (
                            <div>
                                <div className="font-medium text-foreground">
                                    Activities
                                </div>
                                <div className="mt-1 whitespace-pre-wrap">
                                    {note.activities}
                                </div>
                            </div>
                        )}
                        {note.observations && (
                            <div>
                                <div className="font-medium text-foreground">
                                    Observations
                                </div>
                                <div className="mt-1 whitespace-pre-wrap">
                                    {note.observations}
                                </div>
                            </div>
                        )}
                        {note.concerns && (
                            <div>
                                <div className="font-medium text-foreground">
                                    Concerns
                                </div>
                                <div className="mt-1 whitespace-pre-wrap">
                                    {note.concerns}
                                </div>
                            </div>
                        )}
                        {note.goals_progress && (
                            <div>
                                <div className="font-medium text-foreground">
                                    Goals Progress
                                </div>
                                <div className="mt-1 whitespace-pre-wrap">
                                    {note.goals_progress}
                                </div>
                            </div>
                        )}
                        {!note.activities &&
                            !note.observations &&
                            !note.concerns &&
                            !note.goals_progress && (
                                <div className="py-4 text-center text-sm text-muted-foreground">
                                    No content recorded.
                                </div>
                            )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Meta</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2 text-sm text-muted-foreground">
                        <div>
                            Shift Period:{' '}
                            <Badge variant="outline">{note.shift_period}</Badge>
                        </div>
                        <div>
                            Note Date: {formatDateTimeLong(note.note_date)}
                        </div>
                        {note.created_by && (
                            <div>
                                Created by:{' '}
                                {note.created_by.name || note.created_by}
                            </div>
                        )}
                        {note.incident_occurred && note.linked_incident && (
                            <div>
                                Linked Incident:{' '}
                                <Link
                                    href={`/incidents/${note.linked_incident.id}`}
                                    className="text-primary hover:text-primary"
                                >
                                    #{note.linked_incident.id}
                                </Link>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
