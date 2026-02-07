import { Head, Link } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { index as meetingsIndex, create as createMeeting, show as showMeeting } from '@/routes/governance/meetings';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Calendar, Clock, MapPin, Users } from 'lucide-react';
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
      scheduled: 'bg-blue-100 text-blue-800',
      agenda_draft: 'bg-yellow-100 text-yellow-800',
      agenda_final: 'bg-green-100 text-green-800',
      in_progress: 'bg-purple-100 text-purple-800',
      minutes_draft: 'bg-orange-100 text-orange-800',
      minutes_approved: 'bg-green-100 text-green-800',
      archived: 'bg-gray-100 text-gray-800',
    }[status] || 'bg-gray-100 text-gray-800';
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

      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          {/* Header */}
          <div className="flex items-center justify-between mb-6">
            <div>
              <h1 className="text-3xl font-bold text-gray-900">Board Meetings</h1>
              <p className="text-gray-500 mt-1">Schedule and manage governance meetings</p>
            </div>
            <Button asChild>
              <Link href={createMeeting.url()}>Schedule Meeting</Link>
            </Button>
          </div>

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
                    className="flex items-start justify-between p-4 rounded-lg border hover:bg-gray-50 transition-colors"
                  >
                    <div className="flex-1">
                      <div className="flex items-center gap-3 mb-2">
                        <h3 className="font-semibold text-lg text-gray-900">
                          <Link 
                            href={showMeeting.url({ meeting: meeting.id })}
                            className="hover:text-blue-600"
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
                      
                      <div className="flex flex-wrap items-center gap-4 text-sm text-gray-500">
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
                          <span className="text-gray-500">
                            Chair: {meeting.chair.user.name}
                          </span>
                        )}
                        {meeting.secretary && (
                          <span className="text-gray-500">
                            Secretary: {meeting.secretary.user.name}
                          </span>
                        )}
                      </div>
                    </div>

                    <div className="flex items-center gap-2">
                      {meeting.quorum_met && (
                        <Badge variant="outline" className="text-green-600 border-green-200">
                          Quorum Met
                        </Badge>
                      )}
                      <Button variant="ghost" size="sm" asChild>
                        <Link href={showMeeting.url({ meeting: meeting.id })}>
                          View ->
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
      </div>
    </AppLayout>
  );
}
