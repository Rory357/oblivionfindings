import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { generate as generatePackRoute, show as showPack } from '@/routes/governance/packs';
import { show as showResolution } from '@/routes/governance/resolutions';
import { NewResolutionDialog } from '../Resolutions/_dialogs';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { TabsContent } from '@/components/ui/tabs';
import { PageTabs, type PageTabItem } from '@/components/page/page-tabs';
import { governanceStatusColor } from '@/lib/governance-status';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Checkbox } from '@/components/ui/checkbox';
import {
  Calendar,
  Clock,
  MapPin,
  Users,
  FileText,
  CheckCircle,
  AlertCircle,
  FileDown,
  Vote,
  Plus,
  Pencil,
  Send,
  ClipboardList,
  ListChecks,
} from 'lucide-react';
import { PageHero, PageLayout } from '@/components/page';
import { cn } from '@/lib/utils';
import { useEffect, useState, FormEvent } from 'react';
import axios from 'axios';

interface BoardMemberItem {
  id: number;
  user: { id: number; name: string };
}

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
  agenda_items: Array<{
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
    content_blocks: Array<{ heading: string; content: string }>;
  } | null;
  board_pack: {
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
  boardMembers: BoardMemberItem[];
  quorum: {
    present: number;
    required: number;
    met: boolean;
  };
  canEdit: boolean;
  canManageMinutes: boolean;
  canApproveMinutes: boolean;
  workflowChecklist: {
    counts: {
      done: number;
      remaining: number;
      blocked: number;
    };
    next_step: {
      label: string;
      status: string;
      detail: string;
      action_label: string;
      action_url: string;
      blocked_by: string | null;
    } | null;
    items: Array<{
      key: string;
      label: string;
      status: 'done' | 'in_progress' | 'todo' | 'blocked';
      detail: string;
      action_label: string;
      action_url: string;
      blocked_by: string | null;
    }>;
  };
  meetingCockpit: {
    cards: Array<{
      key: string;
      title: string;
      status: 'done' | 'in_progress' | 'todo' | 'warning';
      value: string | number;
      detail: string;
      href: string;
    }>;
  };
}

export default function MeetingShow({ auth, meeting, boardMembers, quorum, canEdit, canManageMinutes, canApproveMinutes, workflowChecklist, meetingCockpit }: Props) {
  const page = usePage();
  const [generatingPack, setGeneratingPack] = useState(false);
  const [packMessage, setPackMessage] = useState<string | null>(null);
  const [agendaDialogOpen, setAgendaDialogOpen] = useState(false);
  const [attendanceDialogOpen, setAttendanceDialogOpen] = useState(false);
  const [minutesDialogOpen, setMinutesDialogOpen] = useState(false);
  const [newResolutionOpen, setNewResolutionOpen] = useState(false);
  const validTabs = ['agenda', 'attendance', 'minutes', 'resolutions', 'workflow'];
  const parsedTab = new URLSearchParams(page.url.split('?')[1] ?? '').get('tab');
  const defaultTab = parsedTab && validTabs.includes(parsedTab) ? parsedTab : 'agenda';
  const [activeTab, setActiveTab] = useState(defaultTab);

  const agendaItems = meeting.agenda_items ?? [];
  const attendances = meeting.attendances ?? [];
  const resolutions = meeting.resolutions ?? [];
  const allBoardMembers = boardMembers ?? [];

  useEffect(() => {
    setActiveTab(defaultTab);
  }, [defaultTab]);

  // Agenda Item Form
  const agendaForm = useForm({
    title: '',
    description: '',
    presenter_id: '',
    duration_minutes: '15',
    item_type: 'standard',
    is_confidential: false,
  });

  // Attendance Form - track status for each board member
  const [attendanceRecords, setAttendanceRecords] = useState<Record<number, { status: string; apology_reason: string }>>(() => {
    const initial: Record<number, { status: string; apology_reason: string }> = {};
    for (const member of allBoardMembers) {
      const existing = attendances.find(a => a.board_member_id === member.id);
      initial[member.id] = {
        status: existing?.status || 'present',
        apology_reason: existing?.apology_reason || '',
      };
    }
    return initial;
  });
  const [attendanceSubmitting, setAttendanceSubmitting] = useState(false);

  // Minutes Form - structured blocks
  const defaultBlocks = [
    { heading: 'Welcome & Apologies', content: '' },
    { heading: 'Minutes of Previous Meeting', content: '' },
    { heading: 'Matters Arising', content: '' },
    { heading: 'General Business', content: '' },
    { heading: 'Next Meeting', content: '' },
  ];
  const [minutesBlocks, setMinutesBlocks] = useState<Array<{ heading: string; content: string }>>(
    meeting.minutes?.content_blocks && Array.isArray(meeting.minutes.content_blocks)
      ? meeting.minutes.content_blocks
      : defaultBlocks
  );
  const [minutesSubmitting, setMinutesSubmitting] = useState(false);

  const updateMinutesBlock = (index: number, field: 'heading' | 'content', value: string) => {
    setMinutesBlocks(prev => prev.map((block, i) => i === index ? { ...block, [field]: value } : block));
  };

  const addMinutesBlock = () => {
    setMinutesBlocks(prev => [...prev, { heading: '', content: '' }]);
  };

  const removeMinutesBlock = (index: number) => {
    setMinutesBlocks(prev => prev.filter((_, i) => i !== index));
  };

  const getStatusColor = (status: string) => governanceStatusColor(status);

  const getChecklistStatusColor = (status: 'done' | 'in_progress' | 'todo' | 'blocked') => {
    return {
      done: 'bg-status-success-bg text-status-success border-status-success/30',
      in_progress: 'bg-status-info-bg text-status-info border-status-info/30',
      todo: 'bg-status-warning-bg text-status-warning border-status-warning/30',
      blocked: 'bg-status-critical-bg text-status-critical border-status-critical/30',
    }[status];
  };

  const getItemTypeIcon = (type: string) => {
    switch (type) {
      case 'decision': return <Vote className="w-4 h-4 text-primary" />;
      case 'consent': return <CheckCircle className="w-4 h-4 text-status-success" />;
      default: return <FileText className="w-4 h-4 text-muted-foreground" />;
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

  const submitAgendaItem = (e: FormEvent) => {
    e.preventDefault();
    agendaForm.post(`/governance/meetings/${meeting.id}/agenda`, {
      preserveScroll: true,
      onSuccess: () => {
        setAgendaDialogOpen(false);
        agendaForm.reset();
      },
    });
  };

  const submitAttendance = async () => {
    setAttendanceSubmitting(true);
    const attendance = Object.entries(attendanceRecords).map(([id, record]) => ({
      board_member_id: Number(id),
      status: record.status,
      apology_reason: record.apology_reason || null,
    }));

    router.post(`/governance/meetings/${meeting.id}/attendance`, { attendance }, {
      preserveScroll: true,
      onSuccess: () => {
        setAttendanceDialogOpen(false);
        setAttendanceSubmitting(false);
      },
      onError: () => {
        setAttendanceSubmitting(false);
      },
    });
  };

  const submitMinutes = (e: FormEvent) => {
    e.preventDefault();
    setMinutesSubmitting(true);
    const method = meeting.minutes ? 'put' : 'post';

    router[method](`/governance/meetings/${meeting.id}/minutes`, { content_blocks: minutesBlocks }, {
      preserveScroll: true,
      onSuccess: () => {
        setMinutesDialogOpen(false);
        setMinutesSubmitting(false);
      },
      onError: () => {
        setMinutesSubmitting(false);
      },
    });
  };

  const submitForApproval = () => {
    router.post(`/governance/meetings/${meeting.id}/minutes/approve`, {}, {
      preserveScroll: true,
    });
  };

  const removeAgendaItem = (itemId: number) => {
    router.delete(`/governance/meetings/${meeting.id}/agenda/${itemId}`, {
      preserveScroll: true,
    });
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

      <PageLayout
        hero={
          <PageHero
            category="governance"
            backHref="/governance/meetings"
            icon={Calendar}
            title={
              <span className="flex flex-wrap items-center gap-3" dusk="meeting-title">
                {meeting.title}
                <Badge className={cn(getStatusColor(meeting.status))}>
                  {meeting.status.replace('_', ' ')}
                </Badge>
              </span>
            }
            description={
              <div className="flex flex-wrap items-center gap-4 text-sm">
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
            }
            meta={[
              { icon: Users, label: `Chair: ${meeting.chair?.user.name ?? 'Unassigned'}` },
              { icon: Users, label: `Secretary: ${meeting.secretary?.user.name ?? 'Unassigned'}` },
            ]}
            stats={[
              { label: 'Workflow', value: `${workflowChecklist.counts.done}/${workflowChecklist.counts.done + workflowChecklist.counts.remaining + workflowChecklist.counts.blocked}` },
              { label: 'Quorum', value: `${quorum.present}/${quorum.required}` },
              { label: 'Agenda', value: agendaItems.length },
              { label: 'Resolutions', value: resolutions.length },
            ]}
            actions={
              <>
                {canEdit && (
                  <Button variant="outline" asChild>
                    <Link href={`/governance/meetings/${meeting.id}/edit`}>Edit</Link>
                  </Button>
                )}
                {meeting.board_pack ? (
                  <Button asChild>
                    <Link href={showPack.url({ pack: meeting.board_pack.id })} dusk="view-pack">
                      <FileDown className="w-4 h-4 mr-2" />
                      View Pack
                    </Link>
                  </Button>
                ) : canEdit ? (
                  <Button onClick={generatePack} disabled={generatingPack} dusk="generate-pack">
                    {generatingPack ? 'Generating...' : 'Generate Pack'}
                  </Button>
                ) : null}
              </>
            }
          />
        }
      >
          {packMessage && (
            <div className="mb-4 rounded-lg border border-status-info/30 bg-status-info-bg px-4 py-2 text-sm text-status-info">
              {packMessage}
            </div>
          )}

          <NewResolutionDialog
            isOpen={newResolutionOpen}
            onClose={() => setNewResolutionOpen(false)}
            meetings={[
              {
                id: meeting.id,
                title: meeting.title,
                scheduled_at: meeting.scheduled_at,
              },
            ]}
            meetingId={meeting.id}
            lockMeeting
          />

          {/* Meeting Status Strip — replaces the right-rail info cards. */}
          <MeetingStatusStrip
            meeting={meeting}
            quorum={quorum}
            workflowChecklist={workflowChecklist}
            resolutions={resolutions}
            attendances={attendances}
          />

          <div className="space-y-6">
            {/* Tabs (Sites-style PageTabs) */}
            <PageTabs
              value={activeTab}
              onValueChange={setActiveTab}
              items={[
                { value: 'agenda', label: `Agenda (${agendaItems.length})`, icon: FileText, 'data-test': 'meeting-tab-agenda' },
                { value: 'attendance', label: 'Attendance', icon: Users, 'data-test': 'meeting-tab-attendance' },
                { value: 'minutes', label: 'Minutes', icon: Pencil, 'data-test': 'meeting-tab-minutes' },
                { value: 'resolutions', label: `Resolutions (${resolutions.length})`, icon: Vote, 'data-test': 'meeting-tab-resolutions' },
                { value: 'workflow', label: 'Workflow', icon: ListChecks, 'data-test': 'meeting-tab-workflow' },
              ] as PageTabItem[]}
            >

                {/* ========== AGENDA TAB ========== */}
                <TabsContent value="agenda">
                  <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                      <div>
                        <CardTitle>Agenda Items</CardTitle>
                        <CardDescription>{agendaItems.length} items</CardDescription>
                      </div>
                      {canEdit && (
                        <Dialog open={agendaDialogOpen} onOpenChange={setAgendaDialogOpen}>
                          <DialogTrigger asChild>
                            <Button size="sm">
                              <Plus className="w-4 h-4 mr-1" />
                              Add Item
                            </Button>
                          </DialogTrigger>
                          <DialogContent className="max-w-lg" aria-describedby={undefined}>
                            <DialogHeader>
                              <DialogTitle>Add Agenda Item</DialogTitle>
                            </DialogHeader>
                            <form onSubmit={submitAgendaItem} className="space-y-4">
                              <div>
                                <Label htmlFor="agenda-title">Title</Label>
                                <Input
                                  id="agenda-title"
                                  value={agendaForm.data.title}
                                  onChange={e => agendaForm.setData('title', e.target.value)}
                                  required
                                />
                                {agendaForm.errors.title && <p className="text-sm text-status-critical mt-1">{agendaForm.errors.title}</p>}
                              </div>
                              <div>
                                <Label htmlFor="agenda-description">Description</Label>
                                <Textarea
                                  id="agenda-description"
                                  value={agendaForm.data.description}
                                  onChange={e => agendaForm.setData('description', e.target.value)}
                                  rows={3}
                                />
                              </div>
                              <div className="grid grid-cols-2 gap-4">
                                <div>
                                  <Label>Item Type</Label>
                                  <Select
                                    value={agendaForm.data.item_type}
                                    onValueChange={v => agendaForm.setData('item_type', v)}
                                  >
                                    <SelectTrigger>
                                      <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                      <SelectItem value="standard">Standard</SelectItem>
                                      <SelectItem value="decision">Decision Required</SelectItem>
                                      <SelectItem value="consent">Consent</SelectItem>
                                      <SelectItem value="for_info">For Information</SelectItem>
                                    </SelectContent>
                                  </Select>
                                </div>
                                <div>
                                  <Label htmlFor="agenda-duration">Duration (mins)</Label>
                                  <Input
                                    id="agenda-duration"
                                    type="number"
                                    min={5}
                                    max={120}
                                    value={agendaForm.data.duration_minutes}
                                    onChange={e => agendaForm.setData('duration_minutes', e.target.value)}
                                    required
                                  />
                                </div>
                              </div>
                              <div>
                                <Label>Presenter</Label>
                                <Select
                                  value={agendaForm.data.presenter_id || undefined}
                                  onValueChange={v => agendaForm.setData('presenter_id', v)}
                                >
                                  <SelectTrigger>
                                    <SelectValue placeholder="Select presenter (optional)" />
                                  </SelectTrigger>
                                  <SelectContent>
                                    {allBoardMembers.map(m => (
                                      <SelectItem key={m.user.id} value={String(m.user.id)}>{m.user.name}</SelectItem>
                                    ))}
                                  </SelectContent>
                                </Select>
                              </div>
                              <div className="flex items-center gap-2">
                                <Checkbox
                                  id="agenda-confidential"
                                  checked={agendaForm.data.is_confidential}
                                  onCheckedChange={v => agendaForm.setData('is_confidential', !!v)}
                                />
                                <Label htmlFor="agenda-confidential">Confidential item</Label>
                              </div>
                              <div className="flex justify-end gap-2">
                                <Button type="button" variant="outline" onClick={() => setAgendaDialogOpen(false)}>
                                  Cancel
                                </Button>
                                <Button type="submit" disabled={agendaForm.processing}>
                                  {agendaForm.processing ? 'Adding...' : 'Add Item'}
                                </Button>
                              </div>
                            </form>
                          </DialogContent>
                        </Dialog>
                      )}
                    </CardHeader>
                    <CardContent>
                      <div className="space-y-4">
                        {agendaItems.length === 0 && (
                          <p className="text-muted-foreground text-center py-8">No agenda items yet. Add items to build the meeting agenda.</p>
                        )}
                        {agendaItems.map((item) => (
                          <div
                            key={item.id}
                            className={cn(
                              "flex items-start gap-4 p-4 rounded-lg border",
                              item.is_confidential && "bg-primary/10 border-primary"
                            )}
                          >
                            <div className="flex-shrink-0 w-8 h-8 rounded-full bg-muted flex items-center justify-center font-semibold text-muted-foreground">
                              {item.order}
                            </div>
                            <div className="flex-1">
                              <div className="flex items-center gap-2 mb-1">
                                {getItemTypeIcon(item.item_type)}
                                <h4 className="font-semibold text-foreground">{item.title}</h4>
                                {item.is_confidential && (
                                  <Badge variant="outline" className="text-primary border-primary">
                                    Confidential
                                  </Badge>
                                )}
                                <Badge variant="outline">{item.item_type}</Badge>
                              </div>
                              {item.description && (
                                <p className="text-sm text-muted-foreground mb-2">{item.description}</p>
                              )}
                              <div className="flex items-center gap-4 text-sm text-muted-foreground">
                                {item.presenter && <span>Presenter: {item.presenter.name}</span>}
                                <span>{item.duration_minutes} minutes</span>
                              </div>
                            </div>
                            {canEdit && (
                              <AlertDialog>
                                <AlertDialogTrigger asChild>
                                  <Button
                                    variant="ghost"
                                    size="sm"
                                    className="text-status-critical hover:text-status-critical"
                                  >
                                    Remove
                                  </Button>
                                </AlertDialogTrigger>
                                <AlertDialogContent>
                                  <AlertDialogHeader>
                                    <AlertDialogTitle>Remove Agenda Item</AlertDialogTitle>
                                    <AlertDialogDescription>
                                      Are you sure you want to remove "{item.title}" from the agenda? This action cannot be undone.
                                    </AlertDialogDescription>
                                  </AlertDialogHeader>
                                  <AlertDialogFooter>
                                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                                    <AlertDialogAction
                                      onClick={() => removeAgendaItem(item.id)}
                                      className="bg-status-critical hover:bg-status-critical"
                                    >
                                      Remove
                                    </AlertDialogAction>
                                  </AlertDialogFooter>
                                </AlertDialogContent>
                              </AlertDialog>
                            )}
                          </div>
                        ))}
                      </div>
                    </CardContent>
                  </Card>
                </TabsContent>

                {/* ========== ATTENDANCE TAB ========== */}
                <TabsContent value="attendance">
                  <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                      <CardTitle>Attendance Record</CardTitle>
                      {canEdit && (
                        <Dialog open={attendanceDialogOpen} onOpenChange={setAttendanceDialogOpen}>
                          <DialogTrigger asChild>
                            <Button size="sm" dusk="record-attendance">
                              <Users className="w-4 h-4 mr-1" />
                              Record Attendance
                            </Button>
                          </DialogTrigger>
                          <DialogContent className="max-w-lg max-h-[80vh] overflow-y-auto" aria-describedby={undefined}>
                            <DialogHeader>
                              <DialogTitle>Record Attendance</DialogTitle>
                            </DialogHeader>
                            <div className="space-y-3">
                              {allBoardMembers.map(member => (
                                <div key={member.id} className="flex items-center gap-3 p-3 rounded-lg border">
                                  <span className="font-medium flex-1 min-w-0 truncate">{member.user.name}</span>
                                  <Select
                                    value={attendanceRecords[member.id]?.status || 'present'}
                                    onValueChange={v => setAttendanceRecords(prev => ({
                                      ...prev,
                                      [member.id]: { ...prev[member.id], status: v },
                                    }))}
                                  >
                                    <SelectTrigger className="w-32">
                                      <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                      <SelectItem value="present">Present</SelectItem>
                                      <SelectItem value="apology">Apology</SelectItem>
                                      <SelectItem value="no_show">No Show</SelectItem>
                                      <SelectItem value="late">Late</SelectItem>
                                    </SelectContent>
                                  </Select>
                                  {attendanceRecords[member.id]?.status === 'apology' && (
                                    <Input
                                      placeholder="Reason"
                                      className="w-40"
                                      value={attendanceRecords[member.id]?.apology_reason || ''}
                                      onChange={e => setAttendanceRecords(prev => ({
                                        ...prev,
                                        [member.id]: { ...prev[member.id], apology_reason: e.target.value },
                                      }))}
                                    />
                                  )}
                                </div>
                              ))}
                              {allBoardMembers.length === 0 && (
                                <p className="text-muted-foreground text-center py-4">No active board members found.</p>
                              )}
                            </div>
                            <div className="flex justify-end gap-2 mt-4">
                              <Button variant="outline" onClick={() => setAttendanceDialogOpen(false)}>Cancel</Button>
                              <Button onClick={submitAttendance} disabled={attendanceSubmitting} dusk="save-attendance">
                                {attendanceSubmitting ? 'Saving...' : 'Save Attendance'}
                              </Button>
                            </div>
                          </DialogContent>
                        </Dialog>
                      )}
                    </CardHeader>
                    <CardContent>
                      <div className="space-y-2">
                        {attendances.length === 0 && (
                          <p className="text-muted-foreground text-center py-8">No attendance recorded yet.</p>
                        )}
                        {attendances.map((attendance) => (
                          <div
                            key={attendance.id}
                            className="flex items-center justify-between p-3 rounded-lg border"
                          >
                            <span className="font-medium">{attendance.board_member.user.name}</span>
                            <div className="flex items-center gap-2">
                              <Badge className={cn(
                                attendance.status === 'present' && 'bg-status-success-bg text-status-success',
                                attendance.status === 'apology' && 'bg-status-warning-bg text-status-warning',
                                attendance.status === 'no_show' && 'bg-status-critical-bg text-status-critical',
                                attendance.status === 'late' && 'bg-status-info-bg text-status-info',
                              )}>
                                {attendance.status.replace('_', ' ')}
                              </Badge>
                              {attendance.apology_reason && (
                                <span className="text-sm text-muted-foreground">({attendance.apology_reason})</span>
                              )}
                            </div>
                          </div>
                        ))}
                      </div>
                    </CardContent>
                  </Card>
                </TabsContent>

                {/* ========== MINUTES TAB ========== */}
                <TabsContent value="minutes">
                  <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                      <div>
                        <CardTitle>Meeting Minutes</CardTitle>
                        <CardDescription>
                          {meeting.minutes ? `Version ${meeting.minutes.version_number} - ${meeting.minutes.status}` : 'No minutes recorded'}
                        </CardDescription>
                      </div>
                      <div className="flex gap-2">
                        {canManageMinutes && (
                          <>
                            <Dialog open={minutesDialogOpen} onOpenChange={setMinutesDialogOpen}>
                              <DialogTrigger asChild>
                                <Button size="sm" variant={meeting.minutes ? 'outline' : 'default'} dusk="edit-minutes">
                                  {meeting.minutes ? (
                                    <><Pencil className="w-4 h-4 mr-1" /> Edit</>
                                  ) : (
                                    <><Plus className="w-4 h-4 mr-1" /> Create Minutes</>
                                  )}
                                </Button>
                              </DialogTrigger>
                              <DialogContent className="max-w-2xl max-h-[85vh] overflow-y-auto" aria-describedby={undefined}>
                                <DialogHeader>
                                  <DialogTitle>{meeting.minutes ? 'Edit Minutes' : 'Create Minutes'}</DialogTitle>
                                </DialogHeader>
                                <form onSubmit={submitMinutes} className="space-y-4">
                                  <div className="space-y-4">
                                    {minutesBlocks.map((block, idx) => (
                                      <div key={idx} className="rounded-lg border p-4 space-y-2">
                                        <div className="flex items-center justify-between gap-2">
                                          <Input
                                            dusk={idx === 0 ? 'minutes-heading-0' : undefined}
                                            value={block.heading}
                                            onChange={e => updateMinutesBlock(idx, 'heading', e.target.value)}
                                            placeholder="Section heading"
                                            className="font-semibold"
                                          />
                                          {minutesBlocks.length > 1 && (
                                            <Button
                                              type="button"
                                              variant="ghost"
                                              size="sm"
                                              className="text-status-critical hover:text-status-critical shrink-0"
                                              onClick={() => removeMinutesBlock(idx)}
                                            >
                                              Remove
                                            </Button>
                                          )}
                                        </div>
                                        <Textarea
                                          dusk={idx === 0 ? 'minutes-content-0' : undefined}
                                          value={block.content}
                                          onChange={e => updateMinutesBlock(idx, 'content', e.target.value)}
                                          placeholder="Enter minutes for this section..."
                                          rows={4}
                                        />
                                      </div>
                                    ))}
                                  </div>
                                  <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="w-full"
                                    onClick={addMinutesBlock}
                                  >
                                    <Plus className="w-4 h-4 mr-1" />
                                    Add Section
                                  </Button>
                                  <div className="flex justify-end gap-2 pt-2">
                                    <Button type="button" variant="outline" onClick={() => setMinutesDialogOpen(false)}>
                                      Cancel
                                    </Button>
                                    <Button type="submit" disabled={minutesSubmitting} dusk="save-minutes">
                                      {minutesSubmitting ? 'Saving...' : meeting.minutes ? 'Update Minutes' : 'Create Minutes'}
                                    </Button>
                                  </div>
                                </form>
                              </DialogContent>
                            </Dialog>
                            {meeting.minutes && meeting.minutes.status === 'draft' && canApproveMinutes && (
                              <AlertDialog>
                                <AlertDialogTrigger asChild>
                                  <Button size="sm" dusk="approve-minutes">
                                    <Send className="w-4 h-4 mr-1" />
                                    Approve Minutes
                                  </Button>
                                </AlertDialogTrigger>
                                <AlertDialogContent>
                                  <AlertDialogHeader>
                                    <AlertDialogTitle>Submit Minutes for Approval</AlertDialogTitle>
                                    <AlertDialogDescription>
                                      Are you sure you want to submit these minutes for approval? This will notify the Chair for review and sign-off.
                                    </AlertDialogDescription>
                                  </AlertDialogHeader>
                                  <AlertDialogFooter>
                                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                                    <AlertDialogAction onClick={submitForApproval}>
                                      Submit for Approval
                                    </AlertDialogAction>
                                  </AlertDialogFooter>
                                </AlertDialogContent>
                              </AlertDialog>
                            )}
                          </>
                        )}
                      </div>
                    </CardHeader>
                    <CardContent>
                      {meeting.minutes ? (
                        <div className="space-y-4">
                          <div className="flex items-center gap-2 mb-4">
                            <span className="text-sm text-muted-foreground">Status:</span>
                            <Badge className={cn(
                              meeting.minutes.status === 'draft' && 'bg-status-warning-bg text-status-warning',
                              meeting.minutes.status === 'approved' && 'bg-status-success-bg text-status-success',
                            )}>
                              {meeting.minutes.status}
                            </Badge>
                          </div>
                          {meeting.minutes.content_blocks && Array.isArray(meeting.minutes.content_blocks) && (
                            <div className="prose max-w-none">
                              {meeting.minutes.content_blocks.map((block: { heading: string; content: string }, idx: number) => (
                                <div key={idx} className="mb-4">
                                  <h3 className="font-semibold text-foreground">{block.heading}</h3>
                                  <p className="text-foreground whitespace-pre-wrap">{block.content || <span className="text-muted-foreground italic">No content yet</span>}</p>
                                </div>
                              ))}
                            </div>
                          )}
                        </div>
                      ) : (
                        <p className="text-muted-foreground text-center py-8">No minutes have been recorded for this meeting yet.</p>
                      )}
                    </CardContent>
                  </Card>
                </TabsContent>

                {/* ========== RESOLUTIONS TAB ========== */}
                <TabsContent value="resolutions">
                  <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                      <CardTitle>Resolutions</CardTitle>
                      <Button
                        size="sm"
                        onClick={() => setNewResolutionOpen(true)}
                        dusk="new-resolution-button"
                      >
                        <Plus className="w-4 h-4 mr-1" />
                        New Resolution
                      </Button>
                    </CardHeader>
                    <CardContent>
                      <div className="space-y-2">
                        {resolutions.length === 0 && (
                          <p className="text-muted-foreground text-center py-8">No resolutions for this meeting.</p>
                        )}
                        {resolutions.map((resolution) => (
                          <div
                            key={resolution.id}
                            className="flex items-center justify-between p-3 rounded-lg border hover:bg-muted"
                          >
                            <div>
                              <p className="font-medium">{resolution.title}</p>
                              <p className="text-sm text-muted-foreground">{resolution.resolution_reference}</p>
                            </div>
                            <div className="flex items-center gap-2">
                              <Badge>{resolution.status}</Badge>
                              <Button variant="ghost" size="sm" asChild>
                                <Link href={showResolution.url({ resolution: resolution.id })}>
                                  View &rarr;
                                </Link>
                              </Button>
                            </div>
                          </div>
                        ))}
                      </div>
                    </CardContent>
                  </Card>
                </TabsContent>
              {/* ========== WORKFLOW TAB ========== */}
              <TabsContent value="workflow">
                <Card dusk="meeting-workflow-checklist-card">
                  <CardHeader className="pb-3">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <div>
                        <CardTitle>Meeting Workflow</CardTitle>
                        <CardDescription>Step-by-step checklist for this meeting cycle.</CardDescription>
                      </div>
                      <div className="flex flex-wrap gap-2">
                        <Badge variant="outline">{workflowChecklist.counts.done} complete</Badge>
                        {workflowChecklist.counts.remaining > 0 && (
                          <Badge className="bg-status-warning-bg text-status-warning border-status-warning/30">
                            {workflowChecklist.counts.remaining} remaining
                          </Badge>
                        )}
                        {workflowChecklist.counts.blocked > 0 && (
                          <Badge className="bg-status-critical-bg text-status-critical border-status-critical/30">
                            {workflowChecklist.counts.blocked} blocked
                          </Badge>
                        )}
                      </div>
                    </div>
                  </CardHeader>
                  <CardContent className="space-y-4">
                    {workflowChecklist.next_step && (
                      <div className="rounded-lg border border-primary/30 bg-primary/10 p-4">
                        <p className="text-xs font-medium uppercase tracking-wide text-primary">Next Step</p>
                        <p className="mt-1 text-base font-semibold text-foreground">{workflowChecklist.next_step.label}</p>
                        <p className="mt-0.5 text-sm text-muted-foreground">{workflowChecklist.next_step.detail}</p>
                        {workflowChecklist.next_step.action_url && (
                          <Button asChild size="sm" className="mt-3">
                            <Link href={workflowChecklist.next_step.action_url}>{workflowChecklist.next_step.action_label}</Link>
                          </Button>
                        )}
                      </div>
                    )}

                    <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                      {workflowChecklist.items.map((item) => (
                        <div
                          key={item.key}
                          className="flex h-full flex-col gap-2 rounded-lg border p-4"
                          dusk={`workflow-item-${item.key}`}
                        >
                          <div className="flex items-start justify-between gap-2">
                            <p className="font-medium leading-snug text-foreground">{item.label}</p>
                            <Badge className={cn('shrink-0', getChecklistStatusColor(item.status))} dusk={`workflow-status-${item.key}`}>
                              {item.status.replace('_', ' ')}
                            </Badge>
                          </div>
                          <p className="text-sm text-muted-foreground">{item.detail}</p>
                          {item.blocked_by && (
                            <p className="text-xs italic text-status-critical">Blocked by: {item.blocked_by}</p>
                          )}
                          <div className="mt-auto pt-2">
                            <Button
                              size="sm"
                              variant={item.status === 'done' ? 'ghost' : item.status === 'blocked' ? 'outline' : 'outline'}
                              asChild
                              className="w-full"
                              disabled={item.status === 'blocked'}
                            >
                              <Link href={item.action_url}>{item.action_label}</Link>
                            </Button>
                          </div>
                        </div>
                      ))}
                    </div>
                  </CardContent>
                </Card>
              </TabsContent>
            </PageTabs>
          </div>
      </PageLayout>
    </AppLayout>
  );
}

/**
 * Strip of meeting status mini-cards rendered under the hero. Surfaces
 * Chair, Secretary, CEO Report, Board Pack, Quorum, Pending Resolutions,
 * Minutes and Previous Follow-through so the board can scan the meeting
 * state in one row without scrolling.
 */
function MeetingStatusStrip({
  meeting,
  quorum,
  workflowChecklist,
  resolutions,
  attendances,
}: {
  meeting: {
    id: number;
    chair: { user: { name: string } } | null;
    secretary: { user: { name: string } } | null;
    board_pack: { distributed_at: string | null } | null;
    minutes: { status: string } | null;
  };
  quorum: { present: number; required: number; met: boolean };
  workflowChecklist: { items: Array<{ key: string; status: string }> };
  resolutions: Array<{ status: string }>;
  attendances: Array<{ status: string }>;
}) {
  const ceoStep = workflowChecklist.items.find((i) => i.key === 'ceo_report');
  const followStep = workflowChecklist.items.find((i) => i.key === 'follow_through');

  const minuteStatus = meeting.minutes?.status ?? null;
  const minuteValue = minuteStatus
    ? minuteStatus.charAt(0).toUpperCase() + minuteStatus.slice(1)
    : 'Not drafted';

  const packDistributed = Boolean(meeting.board_pack?.distributed_at);
  const packPresent = Boolean(meeting.board_pack);
  const packValue = packDistributed ? 'Distributed' : packPresent ? 'Generated' : 'Not started';
  const pendingResolutions = resolutions.filter((r) => ['draft', 'open'].includes(r.status)).length;

  const presentAttendees = attendances.filter((a) => a.status === 'present').length;

  type Tile = { label: string; value: string; tone: 'success' | 'info' | 'warning' | 'critical' | 'muted' };
  const tiles: Tile[] = [
    {
      label: 'Chair',
      value: meeting.chair?.user.name ?? 'Unassigned',
      tone: meeting.chair ? 'info' : 'warning',
    },
    {
      label: 'Secretary',
      value: meeting.secretary?.user.name ?? 'Unassigned',
      tone: meeting.secretary ? 'info' : 'warning',
    },
    {
      label: 'CEO Report',
      value: ceoStep?.status === 'done' ? 'Submitted' : ceoStep?.status === 'blocked' ? 'Blocked' : 'Pending',
      tone: ceoStep?.status === 'done' ? 'success' : ceoStep?.status === 'blocked' ? 'critical' : 'warning',
    },
    {
      label: 'Board Pack',
      value: packValue,
      tone: packDistributed ? 'success' : packPresent ? 'info' : 'warning',
    },
    {
      label: 'Quorum',
      value: `${quorum.present}/${quorum.required}`,
      tone: quorum.met ? 'success' : presentAttendees > 0 ? 'info' : 'warning',
    },
    {
      label: 'Pending Resolutions',
      value: String(pendingResolutions),
      tone: pendingResolutions > 0 ? 'warning' : 'success',
    },
    {
      label: 'Minutes',
      value: minuteValue,
      tone: ['signed', 'approved', 'archived'].includes(minuteStatus ?? '')
        ? 'success'
        : minuteStatus
          ? 'info'
          : 'warning',
    },
    {
      label: 'Previous Follow-through',
      value: followStep?.status === 'done' ? 'Reviewed' : 'Open items',
      tone: followStep?.status === 'done' ? 'success' : 'warning',
    },
  ];

  const TONE_VALUE: Record<Tile['tone'], string> = {
    success: 'text-status-success',
    info: 'text-foreground',
    warning: 'text-status-warning',
    critical: 'text-status-critical',
    muted: 'text-muted-foreground',
  };

  return (
    <div
      className="grid gap-3 md:grid-cols-2 lg:grid-cols-4 xl:grid-cols-4"
      dusk="meeting-status-strip"
    >
      {tiles.map((t) => (
        <Card key={t.label}>
          <CardContent className="p-4">
            <p className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">{t.label}</p>
            <p
              className={cn(
                'mt-1 truncate text-sm font-semibold leading-snug',
                TONE_VALUE[t.tone],
              )}
              title={t.value}
            >
              {t.value}
            </p>
          </CardContent>
        </Card>
      ))}
    </div>
  );
}
