import { Head, useForm } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { store as storeCompliance } from '@/routes/governance/compliance';
import { Shield } from 'lucide-react';
import { PageHero, PageLayout } from '@/components/page';

interface Props extends PageProps {
  frameworks: Array<{ value: string; label: string }>;
}

export default function CreateCompliance({ auth }: Props) {
  const { data, setData, post, processing, errors } = useForm({
    framework: 'nz_disability',
    obligation_reference: '',
    title: '',
    description: '',
    requirements: '',
    due_date: '',
    owner_id: '',
    priority: 'medium',
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post(storeCompliance.url());
  };

  return (
    <AppLayout
      user={auth.user}
      breadcrumbs={[
        { title: 'Governance', href: '/governance/dashboard' },
        { title: 'Compliance', href: '/governance/compliance' },
        { title: 'Create', href: '/governance/compliance/create' },
      ]}
    >
      <Head title="New Compliance Obligation" />

      <PageLayout
        hero={
          <PageHero
            category="governance"
            backHref="/governance/compliance"
            icon={Shield}
            title="New Compliance Obligation"
            description="Register a regulatory or framework obligation"
          />
        }
      >
          <Card>
            <CardHeader>
              <CardTitle>Obligation Details</CardTitle>
            </CardHeader>
            <CardContent>
              <form onSubmit={handleSubmit} className="space-y-4">
                <div>
                  <Label htmlFor="title">Title</Label>
                  <Input
                    id="title"
                    value={data.title}
                    onChange={(e) => setData('title', e.target.value)}
                    placeholder="e.g., Annual Audit Requirements"
                  />
                  {errors.title && <p className="text-sm text-status-critical mt-1">{errors.title}</p>}
                </div>

                <div>
                  <Label htmlFor="framework">Framework</Label>
                  <Select value={data.framework} onValueChange={(v) => setData('framework', v)}>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="nz_disability">NZ Disability Standards</SelectItem>
                      <SelectItem value="health_safety">Health & Safety</SelectItem>
                      <SelectItem value="privacy">Privacy Act</SelectItem>
                      <SelectItem value="human_rights">Human Rights Act</SelectItem>
                      <SelectItem value="financial">Financial Reporting</SelectItem>
                      <SelectItem value="charities">Charities Act</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                <div>
                  <Label htmlFor="obligation_reference">Reference Number</Label>
                  <Input
                    id="obligation_reference"
                    value={data.obligation_reference}
                    onChange={(e) => setData('obligation_reference', e.target.value)}
                    placeholder="e.g., HSWA-2015-SEC-36"
                  />
                </div>

                <div>
                  <Label htmlFor="description">Description</Label>
                  <Textarea
                    id="description"
                    value={data.description}
                    onChange={(e) => setData('description', e.target.value)}
                    placeholder="Describe the compliance obligation..."
                    rows={3}
                  />
                </div>

                <div>
                  <Label htmlFor="requirements">Requirements</Label>
                  <Textarea
                    id="requirements"
                    value={data.requirements}
                    onChange={(e) => setData('requirements', e.target.value)}
                    placeholder="What must be done to comply..."
                    rows={3}
                  />
                </div>

                <div>
                  <Label htmlFor="due_date">Due Date</Label>
                  <Input
                    id="due_date"
                    type="date"
                    value={data.due_date}
                    onChange={(e) => setData('due_date', e.target.value)}
                  />
                </div>

                <div>
                  <Label htmlFor="priority">Priority</Label>
                  <Select value={data.priority} onValueChange={(v) => setData('priority', v)}>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="low">Low</SelectItem>
                      <SelectItem value="medium">Medium</SelectItem>
                      <SelectItem value="high">High</SelectItem>
                      <SelectItem value="critical">Critical</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                <div className="flex gap-2 pt-4">
                  <Button type="submit" disabled={processing}>
                    Save Obligation
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
