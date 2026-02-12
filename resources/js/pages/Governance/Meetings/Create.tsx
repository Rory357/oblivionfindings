import { Head, useForm } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { store as meetingsStore } from '@/routes/governance/meetings';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

interface Props extends PageProps {
  boardMembers: Array<{ id: number; user: { name: string } }>;
  committees: Array<{ id: number; name: string; committee_type: string }>;
}

export default function MeetingCreate({ auth, boardMembers, committees }: Props) {
  const { data, setData, post, processing, errors } = useForm({
    meeting_type: 'full_board',
    board_committee_id: '',
    title: '',
    scheduled_at: '',
    duration_minutes: 120,
    location: '',
    virtual_link: '',
    notes: '',
    chair_id: '',
    secretary_id: '',
    quorum_required: 50,
  });

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    post(meetingsStore.url());
  };

  return (
    <AppLayout
      user={auth.user}
      breadcrumbs={[
        { title: 'Governance', href: '/governance/dashboard' },
        { title: 'Meetings', href: '/governance/meetings' },
        { title: 'Create', href: '/governance/meetings/create' },
      ]}
    >
      <Head title="Schedule Meeting" />

      <div className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
          <Card>
            <CardHeader>
              <CardTitle>Schedule New Meeting</CardTitle>
            </CardHeader>
            <CardContent>
              <form onSubmit={submit} className="space-y-6">
                {/* Meeting Type */}
                <div className="space-y-2">
                  <Label htmlFor="meeting_type">Meeting Type</Label>
                  <Select
                    value={data.meeting_type}
                    onValueChange={(value) => setData('meeting_type', value)}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="full_board">Full Board Meeting</SelectItem>
                      <SelectItem value="audit_risk">Audit & Risk Committee</SelectItem>
                      <SelectItem value="people">People Committee</SelectItem>
                      <SelectItem value="finance">Finance Committee</SelectItem>
                      <SelectItem value="special_general">Special General Meeting</SelectItem>
                      <SelectItem value="executive_session">Executive Session</SelectItem>
                    </SelectContent>
                  </Select>
                  {errors.meeting_type && (
                    <p className="text-sm text-red-600">{errors.meeting_type}</p>
                  )}
                </div>

                {/* Title */}
                <div className="space-y-2">
                  <Label htmlFor="title">Meeting Title</Label>
                  <Input
                    id="title"
                    value={data.title}
                    onChange={(e) => setData('title', e.target.value)}
                    placeholder="e.g., Monthly Board Meeting - March 2026"
                  />
                  {errors.title && <p className="text-sm text-red-600">{errors.title}</p>}
                </div>

                {/* Date & Time */}
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label htmlFor="scheduled_at">Date & Time</Label>
                    <Input
                      id="scheduled_at"
                      type="datetime-local"
                      value={data.scheduled_at}
                      onChange={(e) => setData('scheduled_at', e.target.value)}
                    />
                    {errors.scheduled_at && (
                      <p className="text-sm text-red-600">{errors.scheduled_at}</p>
                    )}
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="duration_minutes">Duration (minutes)</Label>
                    <Input
                      id="duration_minutes"
                      type="number"
                      value={data.duration_minutes}
                      onChange={(e) => setData('duration_minutes', parseInt(e.target.value))}
                      min={30}
                      max={480}
                      step={15}
                    />
                  </div>
                </div>

                {/* Location */}
                <div className="space-y-2">
                  <Label htmlFor="location">Location</Label>
                  <Input
                    id="location"
                    value={data.location}
                    onChange={(e) => setData('location', e.target.value)}
                    placeholder="e.g., Board Room, 123 Main St"
                  />
                </div>

                {/* Chair & Secretary */}
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label htmlFor="chair_id">Chair</Label>
                    <Select
                      value={data.chair_id || undefined}
                      onValueChange={(value) => setData('chair_id', value)}
                    >
                      <SelectTrigger>
                        <SelectValue placeholder="Select chair" />
                      </SelectTrigger>
                      <SelectContent>
                        {boardMembers.map((member) => (
                          <SelectItem key={member.id} value={String(member.id)}>
                            {member.user.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="secretary_id">Secretary</Label>
                    <Select
                      value={data.secretary_id || undefined}
                      onValueChange={(value) => setData('secretary_id', value)}
                    >
                      <SelectTrigger>
                        <SelectValue placeholder="Select secretary" />
                      </SelectTrigger>
                      <SelectContent>
                        {boardMembers.map((member) => (
                          <SelectItem key={member.id} value={String(member.id)}>
                            {member.user.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                </div>

                {/* Notes */}
                <div className="space-y-2">
                  <Label htmlFor="notes">Notes</Label>
                  <Textarea
                    id="notes"
                    value={data.notes}
                    onChange={(e) => setData('notes', e.target.value)}
                    rows={3}
                    placeholder="Any additional notes about the meeting..."
                  />
                </div>

                {/* Submit */}
                <div className="flex justify-end gap-2">
                  <Button type="button" variant="outline" onClick={() => window.history.back()}>
                    Cancel
                  </Button>
                  <Button type="submit" disabled={processing}>
                    {processing ? 'Scheduling...' : 'Schedule Meeting'}
                  </Button>
                </div>
              </form>
            </CardContent>
          </Card>
      </div>
    </AppLayout>
  );
}
