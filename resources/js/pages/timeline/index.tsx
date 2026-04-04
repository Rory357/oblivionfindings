import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { TimelineInteractions, type Comment, type ReactionGroup } from '@/components/timeline-interactions';
import { useInitials } from '@/hooks/use-initials';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Filter, Home, Pencil, Phone, Search } from 'lucide-react';
import { useState } from 'react';

type EventDto = {
  id: number;
  type: string;
  occurred_at: string;
  subject?: string | null;
  body?: string | null;
  visibility?: string | null;
  actor?: { id: number; name: string } | null;
  client?: { id: number; first_name: string; last_name: string } | null;
  site?: { id: number; name: string } | null;
  meta?: any;
  comments?: Comment[];
  reactions?: ReactionGroup[];
};

type ClientInfo = {
  id: number;
  first_name: string;
  last_name: string;
  preferred_name?: string | null;
  nhi_number?: string | null;
  status: string;
  avatar?: string | null;
  profile_photo_url?: string | null;
  funding_type?: string | null;
  site?: { name: string } | null;
  service_context?: { name: string } | null;
};

type Props = {
  scope: { type: 'staff' | 'client' | 'site'; id: number; name: string };
  client?: ClientInfo | null;
  range: { from: string; to: string };
  events: EventDto[];
  filters?: { type?: string; from?: string | null; to?: string | null };
};

const EVENT_TYPE_STYLES: Record<string, { dot: string; bg: string; label: string }> = {
    shift: { dot: 'bg-blue-500', bg: 'bg-blue-50 border-l-blue-400', label: 'Shift' },
    note: { dot: 'bg-violet-500', bg: 'bg-violet-50 border-l-violet-400', label: 'Note' },
    shift_note: { dot: 'bg-violet-500', bg: 'bg-violet-50 border-l-violet-400', label: 'Shift Note' },
    progress_note: { dot: 'bg-violet-500', bg: 'bg-violet-50 border-l-violet-400', label: 'Progress Note' },
    handover: { dot: 'bg-cyan-500', bg: 'bg-cyan-50 border-l-cyan-400', label: 'Handover' },
    incident: { dot: 'bg-red-500', bg: 'bg-red-50 border-l-red-400', label: 'Incident' },
    medication_given: { dot: 'bg-emerald-500', bg: 'bg-emerald-50 border-l-emerald-400', label: 'Medication Given' },
    medication_refused: { dot: 'bg-orange-500', bg: 'bg-orange-50 border-l-orange-400', label: 'Medication Refused' },
    medication_missed: { dot: 'bg-amber-500', bg: 'bg-amber-50 border-l-amber-400', label: 'Medication Missed' },
    medication_prescribed: { dot: 'bg-teal-500', bg: 'bg-teal-50 border-l-teal-400', label: 'Medication Added' },
    medication_correction: { dot: 'bg-pink-500', bg: 'bg-pink-50 border-l-pink-400', label: 'Correction' },
    document_uploaded: { dot: 'bg-indigo-500', bg: 'bg-indigo-50 border-l-indigo-400', label: 'Document' },
    condition_added: { dot: 'bg-rose-500', bg: 'bg-rose-50 border-l-rose-400', label: 'Condition' },
    care_plan_created: { dot: 'bg-purple-500', bg: 'bg-purple-50 border-l-purple-400', label: 'Care Plan' },
    appointment_scheduled: { dot: 'bg-amber-500', bg: 'bg-amber-50 border-l-amber-400', label: 'Appointment' },
    visit_requested: { dot: 'bg-green-500', bg: 'bg-green-50 border-l-green-400', label: 'Visit Request' },
    visit_approved: { dot: 'bg-green-500', bg: 'bg-green-50 border-l-green-400', label: 'Visit Approved' },
    visit_cancelled: { dot: 'bg-gray-500', bg: 'bg-gray-50 border-l-gray-400', label: 'Visit Cancelled' },
    photo_uploaded: { dot: 'bg-sky-500', bg: 'bg-sky-50 border-l-sky-400', label: 'Photo' },
    family_note_created: { dot: 'bg-purple-500', bg: 'bg-purple-50 border-l-purple-400', label: 'Family Note' },
    family_note_completed: { dot: 'bg-emerald-500', bg: 'bg-emerald-50 border-l-emerald-400', label: 'Family Note Done' },
};

const TYPE_FILTER_OPTIONS = [
    { value: 'all', label: 'All Types' },
    { value: 'shift', label: 'Shifts' },
    { value: 'note', label: 'Notes' },
    { value: 'progress_note', label: 'Progress Notes' },
    { value: 'handover', label: 'Handovers' },
    { value: 'incident', label: 'Incidents' },
    { value: 'medication_given', label: 'Medications' },
    { value: 'document_uploaded', label: 'Documents' },
    { value: 'appointment_scheduled', label: 'Appointments' },
    { value: 'family_note_created', label: 'Family Notes' },
    { value: 'visit_requested', label: 'Visit Requests' },
];

function groupByDate(events: EventDto[]): Record<string, EventDto[]> {
    const groups: Record<string, EventDto[]> = {};
    for (const e of events) {
        const date = new Date(e.occurred_at).toLocaleDateString('en-NZ', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
        if (!groups[date]) groups[date] = [];
        groups[date].push(e);
    }
    return groups;
}

export default function TimelineIndex(props: Props) {
  const { auth } = usePage().props as any;
  const canCreate = !!auth?.can?.timeline?.create;
  const getInitials = useInitials();
  const c = props.client;
  const isClient = props.scope.type === 'client';
  const name = c ? `${c.first_name} ${c.last_name}`.trim() : props.scope.name;

  const [search, setSearch] = useState('');
  const [showAddNote, setShowAddNote] = useState(false);

  const noteForm = useForm<{ body: string }>({ body: '' });
  const submitNote = () => {
    if (!isClient) return;
    noteForm.post(`/clients/${props.scope.id}/notes`, {
      preserveScroll: true,
      onSuccess: () => { noteForm.reset('body'); setShowAddNote(false); },
    });
  };

  const updateFilter = (key: string, value: string | null) => {
    const params: any = { ...props.filters };
    if (value && value !== 'all') params[key] = value;
    else delete params[key];
    if (key === 'from' && value) params.from = value;
    if (key === 'to' && value) params.to = value;
    router.get(`/clients/${props.scope.id}/timeline`, params, { preserveState: true, replace: true });
  };

  // Filter by search text client-side
  const filteredEvents = search
    ? props.events.filter(e => {
        const searchable = [e.subject, e.body, e.type, e.actor?.name].filter(Boolean).join(' ').toLowerCase();
        return searchable.includes(search.toLowerCase());
      })
    : props.events;

  const grouped = groupByDate(filteredEvents);

  return (
    <AppLayout breadcrumbs={[
      { title: 'Clients', href: '/clients' },
      ...(isClient ? [{ title: name, href: `/operations/clients/${props.scope.id}` }] : []),
      { title: 'Timeline', href: `/clients/${props.scope.id}/timeline` },
    ]}>
      <Head title={`Timeline - ${name}`} />

      <div className="mx-auto max-w-5xl space-y-6 p-4 md:p-6">
        {/* Hero Header */}
        {isClient && c && (
          <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-primary/90 via-primary to-primary/80 p-6 text-white md:p-8">
            <div className="pointer-events-none absolute -right-16 -top-16 h-64 w-64 rounded-full bg-white/5" />
            <div className="pointer-events-none absolute -bottom-20 -left-20 h-48 w-48 rounded-full bg-white/5" />
            <div className="relative flex flex-col items-center gap-6 md:flex-row md:items-start">
              <Avatar className="h-20 w-20 shrink-0 border-4 border-white/20 shadow-xl md:h-24 md:w-24">
                <AvatarImage src={c.avatar ?? c.profile_photo_url ?? undefined} alt={name} />
                <AvatarFallback className="bg-white/10 text-xl font-bold text-white md:text-2xl">{getInitials(name)}</AvatarFallback>
              </Avatar>
              <div className="flex-1 text-center md:text-left">
                <h1 className="text-2xl font-bold md:text-3xl">{name}</h1>
                {c.preferred_name && c.preferred_name !== name && (
                  <p className="mt-0.5 text-sm text-white/60">Preferred: {c.preferred_name}</p>
                )}
                {c.nhi_number && <p className="mt-0.5 text-sm text-white/60">NHI: {c.nhi_number}</p>}
                <div className="mt-3 flex flex-wrap items-center justify-center gap-2 md:justify-start">
                  <Badge className={c.status === 'active' ? 'bg-emerald-400/20 text-emerald-100 border-emerald-300/30' : 'bg-white/10 text-white/90 border-white/20'}>{c.status}</Badge>
                  {c.funding_type && <Badge className="bg-white/10 text-white/90 border-white/20">{c.funding_type}</Badge>}
                  {c.service_context && <Badge className="bg-white/10 text-white/90 border-white/20">{c.service_context.name}</Badge>}
                  {c.site && <Badge className="bg-white/10 text-white/90 border-white/20"><Home className="mr-1 h-3 w-3" />{c.site.name}</Badge>}
                </div>
              </div>
              <div className="flex flex-wrap gap-2">
                <Button size="sm" variant="outline" className="border-white/20 bg-white/10 text-white hover:bg-white/20" asChild>
                  <Link href={`/operations/clients/${c.id}`}><ArrowLeft className="mr-1.5 h-3.5 w-3.5" />Back</Link>
                </Button>
              </div>
            </div>
          </div>
        )}

        {/* Filters */}
        <div className="space-y-2 rounded-xl border bg-card p-3 shadow-sm">
          <div className="flex flex-wrap items-center gap-2">
            <div className="relative flex-1">
              <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
              <Input placeholder="Search events..." className="h-9 pl-8 text-sm" value={search} onChange={e => setSearch(e.target.value)} />
            </div>
            <Select value={props.filters?.type ?? 'all'} onValueChange={v => updateFilter('type', v)}>
              <SelectTrigger className="h-9 w-[160px] text-xs"><SelectValue placeholder="All Types" /></SelectTrigger>
              <SelectContent>
                {TYPE_FILTER_OPTIONS.map(o => <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>)}
              </SelectContent>
            </Select>
            {isClient && canCreate && (
              <Button size="sm" className="gap-1.5" onClick={() => setShowAddNote(!showAddNote)}>
                {showAddNote ? 'Cancel' : 'Add Note'}
              </Button>
            )}
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <Filter className="h-3.5 w-3.5 text-muted-foreground" />
            <span className="text-xs text-muted-foreground">Date range:</span>
            <Input type="date" className="h-8 w-[140px] text-xs" value={props.filters?.from ?? ''} onChange={e => updateFilter('from', e.target.value || null)} />
            <span className="text-xs text-muted-foreground">to</span>
            <Input type="date" className="h-8 w-[140px] text-xs" value={props.filters?.to ?? ''} onChange={e => updateFilter('to', e.target.value || null)} />
            <span className="ml-2 text-xs text-muted-foreground">{filteredEvents.length} event{filteredEvents.length !== 1 ? 's' : ''}</span>
          </div>
        </div>

        {/* Add Note Form */}
        {showAddNote && (
          <Card className="border-primary/20">
            <CardContent className="p-4 space-y-3">
              <Textarea value={noteForm.data.body} onChange={e => noteForm.setData('body', e.target.value)} placeholder="Write a quick note..." rows={3} />
              <div className="flex items-center gap-2">
                <Button size="sm" onClick={submitNote} disabled={noteForm.processing || !noteForm.data.body.trim()}>Add note</Button>
                {noteForm.errors.body && <span className="text-xs text-destructive">{noteForm.errors.body}</span>}
              </div>
            </CardContent>
          </Card>
        )}

        {/* Timeline */}
        {filteredEvents.length > 0 ? (
          <div className="space-y-6">
            {Object.entries(grouped).map(([date, events]) => (
              <div key={date}>
                <div className="mb-3 flex items-center gap-3">
                  <div className="h-2 w-2 rounded-full bg-primary" />
                  <span className="text-xs font-semibold text-primary">{date}</span>
                </div>
                <div className="space-y-2 pl-4 border-l-2 border-border ml-[3px]">
                  {events.map((e) => {
                    const style = EVENT_TYPE_STYLES[e.type] ?? { dot: 'bg-gray-400', bg: 'bg-card border-l-gray-300', label: e.type };
                    return (
                      <div key={e.id} className={`relative rounded-xl border border-l-4 p-4 transition-all hover:shadow-sm ${style.bg}`}>
                        <div className="flex items-start justify-between gap-3">
                          <div className="min-w-0 flex-1">
                            <div className="flex items-center gap-2 flex-wrap">
                              <span className={`h-2 w-2 rounded-full ${style.dot}`} />
                              <span className="text-sm font-semibold">{e.subject ?? e.type}</span>
                              <Badge variant="outline" className="text-[9px] capitalize">{style.label}</Badge>
                              {e.visibility === 'portal' && <Badge className="border-0 bg-blue-100 text-blue-700 text-[9px]">Family Visible</Badge>}
                            </div>
                            <div className="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground">
                              <span>{new Date(e.occurred_at).toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' })}</span>
                              {e.actor && <><span>·</span><span>{e.actor.name}</span></>}
                              {e.site && <><span>·</span><span>{e.site.name}</span></>}
                            </div>
                          </div>
                        </div>
                        {e.body && (
                          <p className="mt-2 whitespace-pre-wrap text-sm text-slate-700 dark:text-slate-200 leading-relaxed">
                            {e.body.length > 300 ? e.body.slice(0, 300) + '...' : e.body}
                          </p>
                        )}
                        {e.meta?.emotions && (e.meta.emotions as string[]).length > 0 && (
                          <div className="mt-1.5 flex flex-wrap gap-1">
                            {(e.meta.emotions as string[]).map((em: string) => {
                              const emojiMap: Record<string, string> = { happy: '😊', calm: '😌', excited: '🤩', tired: '😴', anxious: '😰', sad: '😢', frustrated: '😤', confused: '😕' };
                              const colorMap: Record<string, string> = { happy: 'bg-emerald-100 text-emerald-700', calm: 'bg-sky-100 text-sky-700', excited: 'bg-amber-100 text-amber-700', tired: 'bg-indigo-100 text-indigo-700', anxious: 'bg-orange-100 text-orange-700', sad: 'bg-blue-100 text-blue-700', frustrated: 'bg-red-100 text-red-700', confused: 'bg-purple-100 text-purple-700' };
                              return <span key={em} className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium ${colorMap[em] ?? 'bg-muted'}`}>{emojiMap[em] ?? em} {em}</span>;
                            })}
                          </div>
                        )}
                        {isClient && (
                          <TimelineInteractions
                            eventId={e.id}
                            comments={e.comments ?? []}
                            reactions={e.reactions ?? []}
                            currentUserId={auth?.user?.id}
                            commentUrl={`/clients/${props.scope.id}/timeline/${e.id}/comments`}
                            deleteCommentUrl={`/clients/${props.scope.id}/timeline/comments`}
                            likeCommentUrl={`/clients/${props.scope.id}/timeline/comments`}
                            reactUrl={`/clients/${props.scope.id}/timeline/${e.id}/react`}
                            canComment={canCreate}
                            canReact={true}
                            showStaffBadge={true}
                          />
                        )}
                      </div>
                    );
                  })}
                </div>
              </div>
            ))}
          </div>
        ) : (
          <Card className="border-dashed">
            <CardContent className="flex flex-col items-center justify-center py-16">
              <span className="mb-3 text-4xl">📋</span>
              <p className="font-medium">No timeline activity</p>
              <p className="mt-1 text-sm text-muted-foreground">
                {search ? 'No events match your search.' : 'No events in this date range. Try adjusting the filters.'}
              </p>
            </CardContent>
          </Card>
        )}
      </div>
    </AppLayout>
  );
}
