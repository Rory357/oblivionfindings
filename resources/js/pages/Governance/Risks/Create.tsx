import { Head, useForm } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { store as storeRisk } from '@/routes/governance/risks';
import { AlertTriangle } from 'lucide-react';

interface Props extends PageProps {
  categories: Array<{ value: string; label: string }>;
}

export default function CreateRisk({ auth }: Props) {
  const { data, setData, post, processing, errors } = useForm({
    category: '',
    title: '',
    description: '',
    likelihood_score: 3,
    impact_score: 3,
    control_effectiveness: 'moderate',
    risk_owner_id: '',
    mitigation_strategy: 'treat',
    review_frequency: 'quarterly',
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post(storeRisk.url());
  };

  return (
    <AppLayout
      user={auth.user}
      breadcrumbs={[
        { title: 'Governance', href: '/governance/dashboard' },
        { title: 'Risks', href: '/governance/risks' },
        { title: 'Create', href: '/governance/risks/create' },
      ]}
    >
      <Head title="New Risk" />

      <div className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="flex items-center gap-3 mb-6">
            <AlertTriangle className="w-8 h-8 text-orange-500" />
            <h1 className="text-3xl font-bold text-gray-900">New Risk</h1>
          </div>

          <Card>
            <CardHeader>
              <CardTitle>Risk Details</CardTitle>
            </CardHeader>
            <CardContent>
              <form onSubmit={handleSubmit} className="space-y-4">
                <div>
                  <Label htmlFor="title">Risk Title</Label>
                  <Input
                    id="title"
                    value={data.title}
                    onChange={(e) => setData('title', e.target.value)}
                    placeholder="e.g., Data Breach Risk"
                  />
                  {errors.title && <p className="text-sm text-red-600 mt-1">{errors.title}</p>}
                </div>

                <div>
                  <Label htmlFor="category">Category</Label>
                  <Select value={data.category} onValueChange={(v) => setData('category', v)}>
                    <SelectTrigger>
                      <SelectValue placeholder="Select category" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="client_safety">Client Safety</SelectItem>
                      <SelectItem value="reputational">Reputational</SelectItem>
                      <SelectItem value="financial">Financial</SelectItem>
                      <SelectItem value="it_cyber">IT/Cyber</SelectItem>
                      <SelectItem value="workforce">Workforce</SelectItem>
                      <SelectItem value="operational">Operational</SelectItem>
                      <SelectItem value="compliance">Compliance</SelectItem>
                    </SelectContent>
                  </Select>
                  {errors.category && <p className="text-sm text-red-600 mt-1">{errors.category}</p>}
                </div>

                <div>
                  <Label htmlFor="description">Description</Label>
                  <Textarea
                    id="description"
                    value={data.description}
                    onChange={(e) => setData('description', e.target.value)}
                    placeholder="Describe the risk..."
                    rows={4}
                  />
                  {errors.description && <p className="text-sm text-red-600 mt-1">{errors.description}</p>}
                </div>

                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <Label htmlFor="likelihood">Likelihood (1-5)</Label>
                    <Input
                      id="likelihood"
                      type="number"
                      min={1}
                      max={5}
                      value={data.likelihood_score}
                      onChange={(e) => setData('likelihood_score', parseInt(e.target.value))}
                    />
                  </div>
                  <div>
                    <Label htmlFor="impact">Impact (1-5)</Label>
                    <Input
                      id="impact"
                      type="number"
                      min={1}
                      max={5}
                      value={data.impact_score}
                      onChange={(e) => setData('impact_score', parseInt(e.target.value))}
                    />
                  </div>
                </div>

                <div>
                  <Label htmlFor="control_effectiveness">Control Effectiveness</Label>
                  <Select value={data.control_effectiveness} onValueChange={(v) => setData('control_effectiveness', v)}>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">None</SelectItem>
                      <SelectItem value="weak">Weak</SelectItem>
                      <SelectItem value="moderate">Moderate</SelectItem>
                      <SelectItem value="strong">Strong</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                <div>
                  <Label htmlFor="mitigation_strategy">Mitigation Strategy</Label>
                  <Select value={data.mitigation_strategy} onValueChange={(v) => setData('mitigation_strategy', v)}>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="treat">Treat</SelectItem>
                      <SelectItem value="transfer">Transfer</SelectItem>
                      <SelectItem value="terminate">Terminate</SelectItem>
                      <SelectItem value="tolerate">Tolerate</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                <div className="flex gap-2 pt-4">
                  <Button type="submit" disabled={processing}>
                    Save Risk
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
