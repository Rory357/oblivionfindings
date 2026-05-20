import { Head, Link, useForm } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PageHero, PageLayout } from '@/components/page';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { store as storeResolution } from '@/routes/governance/resolutions';

interface MeetingOption {
  id: number;
  title: string;
  scheduled_at: string;
}

interface Props extends PageProps {
  meetings: MeetingOption[];
  selectedMeetingId?: number | string | null;
}

export default function CreateResolution({ auth, meetings, selectedMeetingId }: Props) {
  const { data, setData, transform, post, processing, errors } = useForm({
    title: '',
    description: '',
    type: 'ordinary',
    voting_deadline: '',
    meeting_id: selectedMeetingId ? String(selectedMeetingId) : 'none',
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    transform((current) => ({
      ...current,
      meeting_id: current.meeting_id === 'none' ? '' : current.meeting_id,
    }));
    post(storeResolution.url());
  };

  return (
    <AppLayout
      user={auth.user}
      breadcrumbs={[
        { title: 'Governance', href: '/governance/dashboard' },
        { title: 'Resolutions', href: '/governance/resolutions' },
        { title: 'Create', href: '/governance/resolutions/create' },
      ]}
    >
      <Head title="New Resolution" />

      <PageLayout
        hero={
          <PageHero
            variant="compact"
            backHref="/governance/resolutions"
            title="New Resolution"
          />
        }
      >
          <Card>
            <CardHeader>
              <CardTitle>Resolution Details</CardTitle>
            </CardHeader>
            <CardContent>
              <form onSubmit={handleSubmit} className="space-y-4">
                <div>
                  <Label htmlFor="title">Resolution Title</Label>
                  <Input
                    id="title"
                    value={data.title}
                    onChange={(e) => setData('title', e.target.value)}
                    placeholder="e.g., Approval of Annual Budget 2026"
                  />
                  {errors.title && <p className="text-sm text-status-critical mt-1">{errors.title}</p>}
                </div>

                <div>
                  <Label htmlFor="description">Description</Label>
                  <Textarea
                    id="description"
                    value={data.description}
                    onChange={(e) => setData('description', e.target.value)}
                    placeholder="Detailed description of the resolution..."
                    rows={4}
                  />
                  {errors.description && <p className="text-sm text-status-critical mt-1">{errors.description}</p>}
                </div>

                <div>
                  <Label htmlFor="type">Resolution Type</Label>
                  <Select value={data.type} onValueChange={(v) => setData('type', v)}>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="ordinary">Ordinary Resolution</SelectItem>
                      <SelectItem value="special">Special Resolution</SelectItem>
                      <SelectItem value="unanimous">Unanimous Resolution</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                <div>
                  <Label htmlFor="voting_deadline">Voting Deadline</Label>
                  <Input
                    id="voting_deadline"
                    type="datetime-local"
                    value={data.voting_deadline}
                    onChange={(e) => setData('voting_deadline', e.target.value)}
                  />
                </div>

                <div>
                  <Label htmlFor="meeting_id">Link to Meeting (optional)</Label>
                  <Select value={data.meeting_id} onValueChange={(v) => setData('meeting_id', v)}>
                    <SelectTrigger>
                      <SelectValue placeholder="No linked meeting" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">No linked meeting</SelectItem>
                      {meetings.map((meeting) => (
                        <SelectItem key={meeting.id} value={String(meeting.id)}>
                          {meeting.title} ({new Date(meeting.scheduled_at).toLocaleDateString()})
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  {errors.meeting_id && <p className="text-sm text-status-critical mt-1">{errors.meeting_id}</p>}
                  <p className="text-xs text-muted-foreground mt-2">
                    Need a meeting?{' '}
                    <Link href="/governance/meetings/create" className="text-status-info hover:underline">
                      Create one here
                    </Link>
                    .
                  </p>
                </div>

                <div className="flex gap-2 pt-4">
                  <Button type="submit" disabled={processing}>
                    Create Resolution
                  </Button>
                  <Button type="button" variant="outline" onClick={() => window.history.back()}>
                    Cancel
                  </Button>
                </div>
              </form>
            </CardContent>
          </Card>
      </PageLayout>
    </AppLayout>
  );
}
