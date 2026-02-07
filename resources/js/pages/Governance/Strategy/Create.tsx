import { Head, useForm } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { store as storeStrategy } from '@/routes/governance/strategy';
import { Target } from 'lucide-react';

export default function CreateStrategy({ auth }: PageProps) {
  const { data, setData, post, processing, errors } = useForm({
    title: '',
    description: '',
    planning_horizon: '3_year',
    period_start: '',
    period_end: '',
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post(storeStrategy.url());
  };

  return (
    <AppLayout
      user={auth.user}
      breadcrumbs={[
        { title: 'Governance', href: '/governance/dashboard' },
        { title: 'Strategy', href: '/governance/strategy' },
        { title: 'Create', href: '/governance/strategy/create' },
      ]}
    >
      <Head title="New Strategic Plan" />

      <div className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="flex items-center gap-3 mb-6">
            <Target className="w-8 h-8 text-blue-500" />
            <h1 className="text-3xl font-bold text-gray-900">New Strategic Plan</h1>
          </div>

          <Card>
            <CardHeader>
              <CardTitle>Plan Details</CardTitle>
            </CardHeader>
            <CardContent>
              <form onSubmit={handleSubmit} className="space-y-4">
                <div>
                  <Label htmlFor="title">Plan Title</Label>
                  <Input
                    id="title"
                    value={data.title}
                    onChange={(e) => setData('title', e.target.value)}
                    placeholder="e.g., Strategic Plan 2026-2029"
                  />
                  {errors.title && <p className="text-sm text-red-600 mt-1">{errors.title}</p>}
                </div>

                <div>
                  <Label htmlFor="description">Description</Label>
                  <Textarea
                    id="description"
                    value={data.description}
                    onChange={(e) => setData('description', e.target.value)}
                    placeholder="Overview of the strategic plan..."
                    rows={4}
                  />
                </div>

                <div>
                  <Label htmlFor="planning_horizon">Planning Horizon</Label>
                  <Select value={data.planning_horizon} onValueChange={(v) => setData('planning_horizon', v)}>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="3_year">3-Year Plan</SelectItem>
                      <SelectItem value="5_year">5-Year Plan</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <Label htmlFor="period_start">Period Start</Label>
                    <Input
                      id="period_start"
                      type="date"
                      value={data.period_start}
                      onChange={(e) => setData('period_start', e.target.value)}
                    />
                  </div>
                  <div>
                    <Label htmlFor="period_end">Period End</Label>
                    <Input
                      id="period_end"
                      type="date"
                      value={data.period_end}
                      onChange={(e) => setData('period_end', e.target.value)}
                    />
                  </div>
                </div>

                <div className="flex gap-2 pt-4">
                  <Button type="submit" disabled={processing}>
                    Create Plan
                  </Button>
                  <Button type="button" variant="outline" onClick={() => window.history.back()}>
                    Cancel
                  </Button>
                </div>
              </form>
            </CardContent>
          </Card>
      </div>
    </AppLayout>
  );
}
