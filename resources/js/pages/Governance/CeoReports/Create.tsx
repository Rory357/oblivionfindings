import { Head, useForm } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

interface Meeting {
  id: number;
  title: string;
  scheduled_at: string;
}

interface Props extends PageProps {
  meetings: Meeting[];
}

export default function CeoReportCreate({ auth, meetings }: Props) {
  const { data, setData, post, processing, errors } = useForm({
    governance_meeting_id: '',
    operational_summary: '',
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post('/governance/ceo-reports');
  };

  return (
    <AppLayout>
      <Head title="Create CEO Report" />
      <div className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <h1 className="text-2xl font-bold text-gray-900 mb-6">Create CEO Board Report</h1>

        <form onSubmit={handleSubmit}>
          <Card>
            <CardContent className="p-6 space-y-6">
              <div>
                <Label>Board Meeting</Label>
                <Select value={data.governance_meeting_id || undefined} onValueChange={val => setData('governance_meeting_id', val)}>
                  <SelectTrigger><SelectValue placeholder="Select meeting..." /></SelectTrigger>
                  <SelectContent>
                    {meetings.map(m => (
                      <SelectItem key={m.id} value={String(m.id)}>
                        {m.title} ({new Date(m.scheduled_at).toLocaleDateString('en-NZ')})
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                {errors.governance_meeting_id && <p className="text-red-500 text-sm mt-1">{errors.governance_meeting_id}</p>}
              </div>

              <div>
                <Label>Report Title</Label>
                <Input value={meetings.find((meeting) => String(meeting.id) === data.governance_meeting_id)?.title ?? 'Will be generated from the selected meeting'} readOnly />
              </div>

              <div>
                <Label>Executive Summary</Label>
                <Textarea value={data.operational_summary} onChange={e => setData('operational_summary', e.target.value)} rows={8} />
                {errors.operational_summary && <p className="text-red-500 text-sm mt-1">{errors.operational_summary}</p>}
              </div>

              <div className="flex justify-end gap-3">
                <Button type="button" variant="outline" onClick={() => window.history.back()}>Cancel</Button>
                <Button type="submit" disabled={processing}>Create Report</Button>
              </div>
            </CardContent>
          </Card>
        </form>
      </div>
    </AppLayout>
  );
}
