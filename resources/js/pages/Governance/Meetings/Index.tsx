import { Head, Link } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { create as createMeeting, show as showMeeting } from '@/routes/governance/meetings';
import { PageHero, PageLayout } from '@/components/page';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Calendar, Clock, MapPin, CalendarDays, Users } from 'lucide-react';
import { cn } from '@/lib/utils';

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
  const getStatusColor = (status: string) => {
    return {
      scheduled: 'bg-status-info-bg text-status-info',
      agenda_draft: 'bg-status-warning-bg text-status-warning',
      agenda_final: 'bg-status-success-bg text-status-success',
      in_progress: 'bg-primary/10 text-primary',
      minutes_draft: 'bg-status-warning-bg text-status-warning',
      minutes_approved: 'bg-status-success-bg text-status-success',
      archived: 'bg-muted text-foreground',
    }[status] || 'bg-muted text-foreground';
  };

  const getMeetingTypeLabel = (type: string) => {
    return {
      full_board: 'Full Board',
      audit_risk: 'Audit & Risk',
      people: 'People Committee',
      finance: 'Finance Committee',
      special_general: 'Special General',
      executive_session: 'Executive Session',
    }[type] || type;
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
              { label: 'Quorum met', value: meetings.data.filter((m) => m.quorum_met).length },
            ]}
            actions={
              <div className="flex items-center gap-2">
                <Button variant="outline" asChild className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground">
                  <Link href="/governance/meetings/calendar">
                    <CalendarDays className="w-4 h-4 mr-1" />
                    Calendar View
                  </Link>
                </Button>
                <Button asChild>
                  <Link href={createMeeting.url()}>Schedule Meeting</Link>
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
                    className="flex items-start justify-between p-4 rounded-lg border hover:bg-muted transition-colors"
                  >
                    <div className="flex-1">
                      <div className="flex items-center gap-3 mb-2">
                        <h3 className="font-semibold text-lg text-foreground">
                          <Link 
                            href={showMeeting.url({ meeting: meeting.id })}
                            className="hover:text-status-info"
                          >
                            {meeting.title}
                          </Link>
                        </h3>
                        <Badge className={cn(getStatusColor(meeting.status))}>
                          {meeting.status.replace('_', ' ')}
                        </Badge>
                        <Badge variant="outline">
                          {getMeetingTypeLabel(meeting.meeting_type)}
                        </Badge>
                      </div>
                      
                      <div className="flex flex-wrap items-center gap-4 text-sm text-muted-foreground">
                        <span className="flex items-center gap-1">
                          <Calendar className="w-4 h-4" />
                          {formatDate(meeting.scheduled_at)}
                        </span>
                        <span className="flex items-center gap-1">
                          <Clock className="w-4 h-4" />
                          {formatTime(meeting.scheduled_at)} ({meeting.duration_minutes} mins)
                        </span>
                        {meeting.location && (
                          <span className="flex items-center gap-1">
                            <MapPin className="w-4 h-4" />
                            {meeting.location}
                          </span>
                        )}
                      </div>

                      <div className="flex items-center gap-4 mt-2 text-sm">
                        {meeting.chair && (
                          <span className="text-muted-foreground">
                            Chair: {meeting.chair.user.name}
                          </span>
                        )}
                        {meeting.secretary && (
                          <span className="text-muted-foreground">
                            Secretary: {meeting.secretary.user.name}
                          </span>
                        )}
                      </div>
                    </div>

                    <div className="flex items-center gap-2">
                      {meeting.quorum_met && (
                        <Badge variant="outline" className="text-status-success border-status-success/30">
                          Quorum Met
                        </Badge>
                      )}
                      <Button variant="ghost" size="sm" asChild>
                        <Link href={showMeeting.url({ meeting: meeting.id })}>
                          View &rarr;
                        </Link>
                      </Button>
                    </div>
                  </div>
                ))}
              </div>

              {/* Pagination */}
              {meetings.links.length > 3 && (
                <div className="flex justify-center gap-2 mt-6">
                  {meetings.links.map((link, i) => (
                    <Button
                      key={i}
                      variant={link.active ? 'default' : 'outline'}
                      size="sm"
                      disabled={!link.url}
                      asChild={!!link.url}
                    >
                      {link.url ? (
                        <Link href={link.url} dangerouslySetInnerHTML={{ __html: link.label }} />
                      ) : (
                        <span dangerouslySetInnerHTML={{ __html: link.label }} />
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
