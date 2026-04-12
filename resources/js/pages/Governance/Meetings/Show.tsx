import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { generate as generatePackRoute, show as showPack } from '@/routes/governance/packs';
import { create as createResolution, show as showResolution } from '@/routes/governance/resolutions';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { TabsRoot as Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
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
} from 'lucide-react';
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
  const validTabs = ['agenda', 'attendance', 'minutes', 'resolutions'];
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

  const getChecklistStatusColor = (status: 'done' | 'in_progress' | 'todo' | 'blocked') => {
    return {
      done: 'bg-green-100 text-green-800 border-green-200',
      in_progress: 'bg-blue-100 text-blue-800 border-blue-200',
      todo: 'bg-amber-100 text-amber-800 border-amber-200',
      blocked: 'bg-red-100 text-red-800 border-red-200',
    }[status];
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

      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          {/* Header */}
          <div className="flex items-start justify-between mb-6">
            <div>
              <div className="flex items-center gap-3 mb-2">
                <h1 className="text-3xl font-bold text-gray-900" dusk="meeting-title">{meeting.title}</h1>
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
                {meeting.location && (
                  <span className="flex items-center gap-1">
                    <MapPin className="w-4 h-4" />
                    {meeting.location}
                  </span>
                )}
              </div>
            </div>
            <div className="flex gap-2">
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
            </div>
          </div>
          {packMessage && (
            <div className="mb-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-2 text-sm text-blue-800">
              {packMessage}
            </div>
          )}

          <Card className="mb-6">
            <CardHeader className="pb-3">
              <CardTitle>Meeting Workflow</CardTitle>
              <CardDescription>Step-by-step checklist for this meeting cycle.</CardDescription>
            </CardHeader>
            <CardContent>
              <div className="mb-3 flex flex-wrap gap-2">
                <Badge variant="outline">{workflowChecklist.counts.done} complete</Badge>
                {workflowChecklist.counts.remaining > 0 && (
                  <Badge className="bg-amber-100 text-amber-800 border-amber-200">
                    {workflowChecklist.counts.remaining} remaining
                  </Badge>
                )}
                {workflowChecklist.counts.blocked > 0 && (
                  <Badge className="bg-red-100 text-red-800 border-red-200">
                    {workflowChecklist.counts.blocked} blocked
                  </Badge>
                )}
              </div>

              {workflowChecklist.next_step && (
                <div className="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-3">
                  <p className="text-xs font-medium uppercase tracking-wide text-blue-700">Next Step</p>
                  <p className="font-semibold text-blue-900">{workflowChecklist.next_step.label}</p>
                  <p className="text-sm text-blue-800">{workflowChecklist.next_step.detail}</p>
                </div>
              )}

              <div className="space-y-2">
                {workflowChecklist.items.map((item) => (
                  <div key={item.key} className="flex flex-col gap-2 rounded-lg border p-3 md:flex-row md:items-start md:justify-between" dusk={`workflow-item-${item.key}`}>
                    <div className="space-y-1">
                      <div className="flex items-center gap-2">
                        <p className="font-medium text-gray-900">{item.label}</p>
                        <Badge className={getChecklistStatusColor(item.status)} dusk={`workflow-status-${item.key}`}>{item.status.replace('_', ' ')}</Badge>
                      </div>
                      <p className="text-sm text-gray-600">{item.detail}</p>
                      {item.blocked_by && (
                        <p className="text-xs text-red-700">Blocked by: {item.blocked_by}</p>
                      )}
                    </div>
                    <div>
                      <Button size="sm" variant="outline" asChild>
                        <Link href={item.action_url}>{item.action_label}</Link>
                      </Button>
                    </div>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>

          {/* Info Cards */}
          <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 mb-6">
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
            {meetingCockpit.cards.map((card) => (
              <Card key={card.key}>
                <CardContent className="pt-6 space-y-2">
                  <div className="flex items-center justify-between gap-2">
                    <p className="text-sm text-gray-500">{card.title}</p>
                    <Badge className={getChecklistStatusColor(card.status === 'warning' ? 'blocked' : card.status)}>
                      {card.status.replace('_', ' ')}
                    </Badge>
                  </div>
                  <p className="font-semibold text-gray-900">{card.value}</p>
                  <p className="text-sm text-gray-600">{card.detail}</p>
                  <Button variant="ghost" size="sm" className="px-0" asChild>
                    <Link href={card.href}>Open</Link>
                  </Button>
                </CardContent>
              </Card>
            ))}
          </div>

          {/* Tabs */}
          <Tabs value={activeTab} onValueChange={setActiveTab} className="space-y-6">
            <TabsList>
              <TabsTrigger value="agenda">Agenda</TabsTrigger>
              <TabsTrigger value="attendance" dusk="meeting-tab-attendance">Attendance</TabsTrigger>
              <TabsTrigger value="minutes" dusk="meeting-tab-minutes">Minutes</TabsTrigger>
              <TabsTrigger value="resolutions">Resolutions</TabsTrigger>
            </TabsList>

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
                            {agendaForm.errors.title && <p className="text-sm text-red-500 mt-1">{agendaForm.errors.title}</p>}
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
                      <p className="text-gray-500 text-center py-8">No agenda items yet. Add items to build the meeting agenda.</p>
                    )}
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
                        {canEdit && (
                          <AlertDialog>
                            <AlertDialogTrigger asChild>
                              <Button
                                variant="ghost"
                                size="sm"
                                className="text-red-500 hover:text-red-700"
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
                                  className="bg-red-600 hover:bg-red-700"
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
                            <p className="text-gray-500 text-center py-4">No active board members found.</p>
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
                      <p className="text-gray-500 text-center py-8">No attendance recorded yet.</p>
                    )}
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
                            attendance.status === 'late' && 'bg-blue-100 text-blue-800',
                          )}>
                            {attendance.status.replace('_', ' ')}
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
                                          className="text-red-500 hover:text-red-700 shrink-0"
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
                        <span className="text-sm text-gray-500">Status:</span>
                        <Badge className={cn(
                          meeting.minutes.status === 'draft' && 'bg-yellow-100 text-yellow-800',
                          meeting.minutes.status === 'approved' && 'bg-green-100 text-green-800',
                        )}>
                          {meeting.minutes.status}
                        </Badge>
                      </div>
                      {meeting.minutes.content_blocks && Array.isArray(meeting.minutes.content_blocks) && (
                        <div className="prose max-w-none">
                          {meeting.minutes.content_blocks.map((block: { heading: string; content: string }, idx: number) => (
                            <div key={idx} className="mb-4">
                              <h3 className="font-semibold text-gray-900">{block.heading}</h3>
                              <p className="text-gray-700 whitespace-pre-wrap">{block.content || <span className="text-gray-400 italic">No content yet</span>}</p>
                            </div>
                          ))}
                        </div>
                      )}
                    </div>
                  ) : (
                    <p className="text-gray-500 text-center py-8">No minutes have been recorded for this meeting yet.</p>
                  )}
                </CardContent>
              </Card>
            </TabsContent>

            {/* ========== RESOLUTIONS TAB ========== */}
            <TabsContent value="resolutions">
              <Card>
                <CardHeader className="flex flex-row items-center justify-between">
                  <CardTitle>Resolutions</CardTitle>
                  <Button size="sm" asChild>
                    <Link href={createResolution.url({ query: { meeting_id: meeting.id } })}>
                      <Plus className="w-4 h-4 mr-1" />
                      New Resolution
                    </Link>
                  </Button>
                </CardHeader>
                <CardContent>
                  <div className="space-y-2">
                    {resolutions.length === 0 && (
                      <p className="text-gray-500 text-center py-8">No resolutions for this meeting.</p>
                    )}
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
          </Tabs>
      </div>
    </AppLayout>
  );
}
