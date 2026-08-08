import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { governanceStatusColor } from '@/lib/governance-status';
import { cn } from '@/lib/utils';
import {
    create as createMeeting,
    show as showMeeting,
} from '@/routes/governance/meetings';
import { PageProps } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Calendar, CalendarDays, Clock, MapPin, Users } from 'lucide-react';

interface Meeting {
    id: number;
    title: string;
    meeting_type: string;
    scheduled_at: string;
    duration_minutes: number;
    location: string | null;
    status: string;
    quorum_met: boolean;
    chair?: { user: { name: string } };
    secretary?: { user: { name: string } };
}

interface Props extends PageProps {
    meetings: {
        data: Meeting[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
}

export default function MeetingsIndex({ auth, meetings }: Props) {
    const getStatusColor = (status: string) => governanceStatusColor(status);

    const getMeetingTypeLabel = (type: string) => {
        return (
            {
                full_board: 'Full Board',
                audit_risk: 'Audit & Risk',
                people: 'People Committee',
                finance: 'Finance Committee',
                special_general: 'Special General',
                executive_session: 'Executive Session',
            }[type] || type
        );
    };

    const formatDate = (dateString: string) => {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-NZ', {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric',
        });
    };

    const formatTime = (dateString: string) => {
        const date = new Date(dateString);
        return date.toLocaleTimeString('en-NZ', {
            hour: 'numeric',
            minute: '2-digit',
        });
    };

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Meetings', href: '/governance/meetings' },
            ]}
        >
            <Head title="Meetings" />

            <PageLayout
                hero={
                    <PageHero
                        icon={Users}
                        title="Board Meetings"
                        description="Schedule and manage governance meetings."
                        stats={[
                            { label: 'Total', value: meetings.data.length },
                            {
                                label: 'Quorum met',
                                value: meetings.data.filter((m) => m.quorum_met)
                                    .length,
                            },
                        ]}
                        actions={
                            <div className="flex items-center gap-2">
                                <Button
                                    variant="outline"
                                    asChild
                                    className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                >
                                    <Link href="/governance/meetings/calendar">
                                        <CalendarDays className="mr-1 h-4 w-4" />
                                        Calendar View
                                    </Link>
                                </Button>
                                <Button asChild>
                                    <Link href={createMeeting.url()}>
                                        Schedule Meeting
                                    </Link>
                                </Button>
                            </div>
                        }
                    />
                }
            >
                {/* Meetings List */}
                <Card>
                    <CardHeader>
                        <CardTitle>Upcoming and Past Meetings</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="space-y-4">
                            {meetings.data.map((meeting) => (
                                <div
                                    key={meeting.id}
                                    className="flex items-start justify-between rounded-lg border p-4 transition-colors hover:bg-muted"
                                >
                                    <div className="flex-1">
                                        <div className="mb-2 flex items-center gap-3">
                                            <h3 className="text-lg font-semibold text-foreground">
                                                <Link
                                                    href={showMeeting.url({
                                                        meeting: meeting.id,
                                                    })}
                                                    className="hover:text-status-info"
                                                >
                                                    {meeting.title}
                                                </Link>
                                            </h3>
                                            <Badge
                                                className={cn(
                                                    getStatusColor(
                                                        meeting.status,
                                                    ),
                                                )}
                                            >
                                                {meeting.status.replace(
                                                    '_',
                                                    ' ',
                                                )}
                                            </Badge>
                                            <Badge variant="outline">
                                                {getMeetingTypeLabel(
                                                    meeting.meeting_type,
                                                )}
                                            </Badge>
                                        </div>

                                        <div className="flex flex-wrap items-center gap-4 text-sm text-muted-foreground">
                                            <span className="flex items-center gap-1">
                                                <Calendar className="h-4 w-4" />
                                                {formatDate(
                                                    meeting.scheduled_at,
                                                )}
                                            </span>
                                            <span className="flex items-center gap-1">
                                                <Clock className="h-4 w-4" />
                                                {formatTime(
                                                    meeting.scheduled_at,
                                                )}{' '}
                                                ({meeting.duration_minutes}{' '}
                                                mins)
                                            </span>
                                            {meeting.location && (
                                                <span className="flex items-center gap-1">
                                                    <MapPin className="h-4 w-4" />
                                                    {meeting.location}
                                                </span>
                                            )}
                                        </div>

                                        <div className="mt-2 flex items-center gap-4 text-sm">
                                            {meeting.chair && (
                                                <span className="text-muted-foreground">
                                                    Chair:{' '}
                                                    {meeting.chair.user.name}
                                                </span>
                                            )}
                                            {meeting.secretary && (
                                                <span className="text-muted-foreground">
                                                    Secretary:{' '}
                                                    {
                                                        meeting.secretary.user
                                                            .name
                                                    }
                                                </span>
                                            )}
                                        </div>
                                    </div>

                                    <div className="flex items-center gap-2">
                                        {meeting.quorum_met && (
                                            <Badge
                                                variant="outline"
                                                className="border-status-success/30 text-status-success"
                                            >
                                                Quorum Met
                                            </Badge>
                                        )}
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            asChild
                                        >
                                            <Link
                                                href={showMeeting.url({
                                                    meeting: meeting.id,
                                                })}
                                            >
                                                View &rarr;
                                            </Link>
                                        </Button>
                                    </div>
                                </div>
                            ))}
                        </div>

                        {/* Pagination */}
                        {meetings.links.length > 3 && (
                            <div className="mt-6 flex justify-center gap-2">
                                {meetings.links.map((link, i) => (
                                    <Button
                                        key={i}
                                        variant={
                                            link.active ? 'default' : 'outline'
                                        }
                                        size="sm"
                                        disabled={!link.url}
                                        asChild={!!link.url}
                                    >
                                        {link.url ? (
                                            <Link
                                                href={link.url}
                                                dangerouslySetInnerHTML={{
                                                    __html: link.label,
                                                }}
                                            />
                                        ) : (
                                            <span
                                                dangerouslySetInnerHTML={{
                                                    __html: link.label,
                                                }}
                                            />
                                        )}
                                    </Button>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
