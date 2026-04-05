import { Head, useForm, router } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Landmark, Plus } from 'lucide-react';
import { useState } from 'react';
import { cn } from '@/lib/utils';

interface Obligation {
  id: number;
  principle: string;
  title: string;
  description: string;
  implementation_status: string;
  evidence_notes: string | null;
  target_date: string | null;
  order: number;
}

interface Principle {
  value: string;
  label: string;
}

interface Props extends PageProps {
  obligationsByPrinciple: Record<string, Obligation[]>;
  principles: Principle[];
}

export default function TeTiritiIndex({ auth, obligationsByPrinciple, principles }: Props) {
  const [showForm, setShowForm] = useState(false);
  const { data, setData, post, processing, reset } = useForm({
    principle: 'partnership',
    title: '',
    description: '',
    implementation_status: 'not_started',
    evidence_notes: '',
    target_date: '',
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post('/governance/te-tiriti', {
      onSuccess: () => { reset(); setShowForm(false); },
    });
  };

  const getStatusColor = (status: string) => ({
    not_started: 'bg-gray-100 text-gray-800',
    in_progress: 'bg-yellow-100 text-yellow-800',
    implemented: 'bg-blue-100 text-blue-800',
    embedded: 'bg-green-100 text-green-800',
  }[status] || 'bg-gray-100 text-gray-800');

  const getStatusLabel = (status: string) => ({
    not_started: 'Not Started',
    in_progress: 'In Progress',
    implemented: 'Implemented',
    embedded: 'Embedded',
  }[status] || status);

  const getPrincipleStats = (obligations: Obligation[]) => {
    const total = obligations.length;
    const embedded = obligations.filter(o => o.implementation_status === 'embedded').length;
    const implemented = obligations.filter(o => o.implementation_status === 'implemented').length;
    return { total, progress: total > 0 ? Math.round(((embedded + implemented) / total) * 100) : 0 };
  };

  return (
    <AppLayout>
      <Head title="Te Tiriti o Waitangi" />
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div className="flex items-center justify-between mb-6">
          <div>
            <h1 className="text-2xl font-bold text-gray-900">Te Tiriti o Waitangi Framework</h1>
            <p className="text-gray-500 mt-1">Obligations and implementation tracking across Te Tiriti principles</p>
          </div>
          <Button onClick={() => setShowForm(!showForm)}>
            <Plus className="w-4 h-4 mr-2" /> New Obligation
          </Button>
        </div>

        {showForm && (
          <Card className="mb-6">
            <CardHeader><CardTitle>New Te Tiriti Obligation</CardTitle></CardHeader>
            <CardContent>
              <form onSubmit={handleSubmit} className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <Label>Principle</Label>
                    <Select value={data.principle} onValueChange={val => setData('principle', val)}>
                      <SelectTrigger><SelectValue /></SelectTrigger>
                      <SelectContent>
                        {principles.map(p => (
                          <SelectItem key={p.value} value={p.value}>{p.label}</SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                  <div>
                    <Label>Status</Label>
                    <Select value={data.implementation_status} onValueChange={val => setData('implementation_status', val)}>
                      <SelectTrigger><SelectValue /></SelectTrigger>
                      <SelectContent>
                        <SelectItem value="not_started">Not Started</SelectItem>
                        <SelectItem value="in_progress">In Progress</SelectItem>
                        <SelectItem value="implemented">Implemented</SelectItem>
                        <SelectItem value="embedded">Embedded</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                </div>
                <div><Label>Title</Label><Input value={data.title} onChange={e => setData('title', e.target.value)} /></div>
                <div><Label>Description</Label><Textarea value={data.description} onChange={e => setData('description', e.target.value)} rows={3} /></div>
                <div className="grid grid-cols-2 gap-4">
                  <div><Label>Evidence Notes</Label><Textarea value={data.evidence_notes} onChange={e => setData('evidence_notes', e.target.value)} rows={2} /></div>
                  <div><Label>Target Date</Label><Input type="date" value={data.target_date} onChange={e => setData('target_date', e.target.value)} /></div>
                </div>
                <div className="flex justify-end gap-3">
                  <Button type="button" variant="outline" onClick={() => setShowForm(false)}>Cancel</Button>
                  <Button type="submit" disabled={processing}>Save Obligation</Button>
                </div>
              </form>
            </CardContent>
          </Card>
        )}

        {principles.map(principle => {
          const obligations = obligationsByPrinciple[principle.value] || [];
          const stats = getPrincipleStats(obligations);
          return (
            <Card key={principle.value} className="mb-6">
              <CardHeader>
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-3">
                    <Landmark className="w-6 h-6 text-teal-600" />
                    <div>
                      <CardTitle>{principle.label}</CardTitle>
                      <CardDescription>{stats.total} obligation(s) &middot; {stats.progress}% implemented</CardDescription>
                    </div>
                  </div>
                  <div className="w-32 bg-gray-200 rounded-full h-2">
                    <div className="bg-teal-600 h-2 rounded-full" style={{ width: `${stats.progress}%` }} />
                  </div>
                </div>
              </CardHeader>
              <CardContent>
                {obligations.length === 0 ? (
                  <p className="text-gray-500 text-sm">No obligations recorded for this principle.</p>
                ) : (
                  <div className="space-y-3">
                    {obligations.map(obligation => (
                      <div key={obligation.id} className="border rounded-lg p-3">
                        <div className="flex items-center justify-between">
                          <span className="font-medium">{obligation.title}</span>
                          <Badge className={cn('text-xs', getStatusColor(obligation.implementation_status))}>
                            {getStatusLabel(obligation.implementation_status)}
                          </Badge>
                        </div>
                        <p className="text-sm text-gray-600 mt-1">{obligation.description}</p>
                        {obligation.evidence_notes && (
                          <p className="text-xs text-gray-400 mt-1">Evidence: {obligation.evidence_notes}</p>
                        )}
                        {obligation.target_date && (
                          <p className="text-xs text-gray-400">Target: {new Date(obligation.target_date).toLocaleDateString('en-NZ')}</p>
                        )}
                      </div>
                    ))}
                  </div>
                )}
              </CardContent>
            </Card>
          );
        })}
      </div>
    </AppLayout>
  );
}
