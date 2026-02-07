import { Head, Link, router } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { generate as generatePackRoute, show as showPack } from '@/routes/governance/packs';
import { create as createResolution, show as showResolution } from '@/routes/governance/resolutions';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { 
  Calendar, 
  Clock, 
  MapPin, 
  Users, 
  FileText, 
  CheckCircle,
  AlertCircle,
  FileDown,
  Vote
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { useState } from 'react';
import axios from 'axios';

interface Meeting {
  id: number;
  title: string;
  meeting_type: string;
  scheduled_at: string;
  duration_minutes: number;
  location: string | null;
  virtual_link: string | null;
  notes: string | null;
  status: string;
  quorum_met: boolean;
  quorum_required: number;
  pack_distributed_at: string | null;
  chair: { user: { name: string }; id: number } | null;
  secretary: { user: { name: string }; id: number } | null;
  agendaItems: Array<{
    id: number;
    order: number;
    title: string;
    description: string | null;
    presenter: { name: string } | null;
    duration_minutes: number;
    item_type: string;
    is_confidential: boolean;
  }>;
  attendances: Array<{
    id: number;
    board_member_id: number;
    board_member: { user: { name: string } };
    status: string;
    apology_reason: string | null;
  }>;
  minutes: {
    id: number;
    status: string;
    version_number: number;
  } | null;
  boardPack: {
    id: number;
    generated_at: string;
    distributed_at: string | null;
  } | null;
  resolutions: Array<{
    id: number;
    resolution_reference: string;
    title: string;
    status: string;
  }>;
}

interface Props extends PageProps {
  meeting: Meeting;
  quorum: {
    present: number;
    required: number;
    met: boolean;
  };
  canEdit: boolean;
  canManageMinutes: boolean;
}

export default function MeetingShow({ auth, meeting, quorum, canEdit, canManageMinutes }: Props) {
  const [generatingPack, setGeneratingPack] = useState(false);
  const [packMessage, setPackMessage] = useState<string | null>(null);

  const agendaItems = meeting.agendaItems ?? [];
  const attendances = meeting.attendances ?? [];
  const resolutions = meeting.resolutions ?? [];

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

  const getItemTypeIcon = (type: string) => {
    switch (type) {
      case 'decision': return <Vote className="w-4 h-4 text-purple-500" />;
      case 'consent': return <CheckCircle className="w-4 h-4 text-green-500" />;
      default: return <FileText className="w-4 h-4 text-gray-400" />;
    }
  };

  const generatePack = async () => {
    setGeneratingPack(true);
    setPackMessage(null);
    try {
      const response = await axios.post(generatePackRoute.url({ meeting: meeting.id }));
      const status = response?.data?.status ?? null;
      if (status === 'generated') {
        router.reload();
      } else {
        setPackMessage('Board pack generation started. Refresh in a moment to see it.');
      }
    } catch (error) {
      console.error('Failed to generate pack:', error);
      setPackMessage('Failed to generate the board pack. Please try again.');
    } finally {
      setGeneratingPack(false);
    }
  };

  const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('en-NZ', {
      weekday: 'long',
      year: 'numeric',
      month: 'long',
      day: 'numeric',
    });
  };

  const formatTime = (dateString: string) => {
    return new Date(dateString).toLocaleTimeString('en-NZ', {
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
        { title: 'Meeting', href: `/governance/meetings/${meeting.id}` },
      ]}
    >
      <Head title={meeting.title} />

      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          {/* Header */}
          <div className="flex items-start justify-between mb-6">
            <div>
              <div className="flex items-center gap-3 mb-2">
                <h1 className="text-3xl font-bold text-gray-900">{meeting.title}</h1>
                <Badge className={cn(getStatusColor(meeting.status))}>
                  {meeting.status.replace('_', ' ')}
                </Badge>
              </div>
              <div className="flex items-center gap-4 text-gray-500">
                <span className="flex items-center gap-1">
                  <Calendar className="w-4 h-4" />
                  {formatDate(meeting.scheduled_at)}
                </span>
                <span className="flex items-center gap-1">
                  <Clock className="w-4 h-4" />
                  {formatTime(meeting.scheduled_at)} ({meeting.duration_minutes} mins)
                </span>
              </div>
            </div>
            <div className="flex gap-2">
              {canEdit && (
                <Button variant="outline" asChild>
                  <Link href={`/governance/meetings/${meeting.id}/edit`}>Edit</Link>
                </Button>
              )}
              {meeting.boardPack ? (
                <Button asChild>
                  <Link href={showPack.url({ pack: meeting.boardPack.id })}>
                    <FileDown className="w-4 h-4 mr-2" />
                    View Pack
                  </Link>
                </Button>
              ) : canEdit ? (
                <Button onClick={generatePack} disabled={generatingPack}>
                  {generatingPack ? 'Generating...' : 'Generate Pack'}
                </Button>
              ) : null}
            </div>
          </div>
          {packMessage && (
            <div className="mb-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-2 text-sm text-blue-800">
              {packMessage}
            </div>
          )}

          {/* Info Cards */}
          <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <Card>
              <CardContent className="pt-6">
                <p className="text-sm text-gray-500">Chair</p>
                <p className="font-semibold">{meeting.chair?.user.name || 'Not assigned'}</p>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="pt-6">
                <p className="text-sm text-gray-500">Secretary</p>
                <p className="font-semibold">{meeting.secretary?.user.name || 'Not assigned'}</p>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="pt-6">
                <p className="text-sm text-gray-500">Quorum</p>
                <div className="flex items-center gap-2">
                  <p className="font-semibold">{quorum.present} / {quorum.required}</p>
                  {quorum.met ? (
                    <CheckCircle className="w-5 h-5 text-green-500" />
                  ) : (
                    <AlertCircle className="w-5 h-5 text-yellow-500" />
                  )}
                </div>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="pt-6">
                <p className="text-sm text-gray-500">Board Pack</p>
                <p className="font-semibold">
                  {meeting.boardPack?.distributed_at ? 'Distributed' : meeting.boardPack ? 'Generated' : 'Not generated'}
                </p>
              </CardContent>
            </Card>
          </div>

          {/* Tabs */}
          <Tabs defaultValue="agenda" className="space-y-6">
            <TabsList>
              <TabsTrigger value="agenda">Agenda</TabsTrigger>
              <TabsTrigger value="attendance">Attendance</TabsTrigger>
              <TabsTrigger value="minutes">Minutes</TabsTrigger>
              <TabsTrigger value="resolutions">Resolutions</TabsTrigger>
            </TabsList>

            <TabsContent value="agenda">
              <Card>
                <CardHeader className="flex flex-row items-center justify-between">
                  <div>
                    <CardTitle>Agenda Items</CardTitle>
                    <CardDescription>{agendaItems.length} items</CardDescription>
                  </div>
                  {canEdit && (
                    <Button size="sm">Add Item</Button>
                  )}
                </CardHeader>
                <CardContent>
                  <div className="space-y-4">
                    {agendaItems.map((item) => (
                      <div
                        key={item.id}
                        className={cn(
                          "flex items-start gap-4 p-4 rounded-lg border",
                          item.is_confidential && "bg-purple-50 border-purple-200"
                        )}
                      >
                        <div className="flex-shrink-0 w-8 h-8 rounded-full bg-gray-100 flex items-center justify-center font-semibold text-gray-600">
                          {item.order}
                        </div>
                        <div className="flex-1">
                          <div className="flex items-center gap-2 mb-1">
                            {getItemTypeIcon(item.item_type)}
                            <h4 className="font-semibold text-gray-900">{item.title}</h4>
                            {item.is_confidential && (
                              <Badge variant="outline" className="text-purple-600 border-purple-200">
                                Confidential
                              </Badge>
                            )}
                            <Badge variant="outline">{item.item_type}</Badge>
                          </div>
                          {item.description && (
                            <p className="text-sm text-gray-600 mb-2">{item.description}</p>
                          )}
                          <div className="flex items-center gap-4 text-sm text-gray-500">
                            {item.presenter && <span>Presenter: {item.presenter.name}</span>}
                            <span>{item.duration_minutes} minutes</span>
                          </div>
                        </div>
                      </div>
                    ))}
                  </div>
                </CardContent>
              </Card>
            </TabsContent>

            <TabsContent value="attendance">
              <Card>
                <CardHeader className="flex flex-row items-center justify-between">
                  <CardTitle>Attendance Record</CardTitle>
                  {canEdit && (
                    <Button size="sm">Record Attendance</Button>
                  )}
                </CardHeader>
                <CardContent>
                  <div className="space-y-2">
                    {attendances.map((attendance) => (
                      <div
                        key={attendance.id}
                        className="flex items-center justify-between p-3 rounded-lg border"
                      >
                        <span className="font-medium">{attendance.board_member.user.name}</span>
                        <div className="flex items-center gap-2">
                          <Badge className={cn(
                            attendance.status === 'present' && 'bg-green-100 text-green-800',
                            attendance.status === 'apology' && 'bg-yellow-100 text-yellow-800',
                            attendance.status === 'no_show' && 'bg-red-100 text-red-800',
                          )}>
                            {attendance.status}
                          </Badge>
                          {attendance.apology_reason && (
                            <span className="text-sm text-gray-500">({attendance.apology_reason})</span>
                          )}
                        </div>
                      </div>
                    ))}
                  </div>
                </CardContent>
              </Card>
            </TabsContent>

            <TabsContent value="minutes">
              <Card>
                <CardHeader className="flex flex-row items-center justify-between">
                  <div>
                    <CardTitle>Meeting Minutes</CardTitle>
                    <CardDescription>
                      {meeting.minutes ? `Version ${meeting.minutes.version_number}` : 'No minutes recorded'}
                    </CardDescription>
                  </div>
                  {canManageMinutes && (
                    <div className="flex gap-2">
                      {meeting.minutes ? (
                        <>
                          <Button variant="outline" size="sm">Edit</Button>
                          {meeting.minutes.status === 'draft' && (
                            <Button size="sm">Submit for Approval</Button>
                          )}
                        </>
                      ) : (
                        <Button size="sm">Create Minutes</Button>
                      )}
                    </div>
                  )}
                </CardHeader>
                <CardContent>
                  {meeting.minutes ? (
                    <div className="prose max-w-none">
                      <p>Minutes status: <Badge>{meeting.minutes.status}</Badge></p>
                    </div>
                  ) : (
                    <p className="text-gray-500">No minutes have been recorded for this meeting yet.</p>
                  )}
                </CardContent>
              </Card>
            </TabsContent>

            <TabsContent value="resolutions">
              <Card>
                <CardHeader className="flex flex-row items-center justify-between">
                  <CardTitle>Resolutions</CardTitle>
                  <Button size="sm" asChild>
                    <Link href={createResolution.url({ query: { meeting_id: meeting.id } })}>
                      Add Resolution
                    </Link>
                  </Button>
                </CardHeader>
                <CardContent>
                  <div className="space-y-2">
                    {resolutions.map((resolution) => (
                      <div
                        key={resolution.id}
                        className="flex items-center justify-between p-3 rounded-lg border hover:bg-gray-50"
                      >
                        <div>
                          <p className="font-medium">{resolution.title}</p>
                          <p className="text-sm text-gray-500">{resolution.resolution_reference}</p>
                        </div>
                        <div className="flex items-center gap-2">
                          <Badge>{resolution.status}</Badge>
                          <Button variant="ghost" size="sm" asChild>
                            <Link href={showResolution.url({ resolution: resolution.id })}>
                              View →
                            </Link>
                          </Button>
                        </div>
                      </div>
                    ))}
                  </div>
                </CardContent>
              </Card>
            </TabsContent>
          </Tabs>
      </div>
    </AppLayout>
  );
}
