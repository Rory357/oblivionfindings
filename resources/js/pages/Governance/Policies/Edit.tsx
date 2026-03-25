import { Head, useForm } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Checkbox } from '@/components/ui/checkbox';

interface Policy {
  id: number;
  title: string;
  category: string;
  description: string | null;
  content: string;
  effective_date: string;
  review_date: string;
  requires_attestation: boolean;
  status: string;
}

interface Props extends PageProps {
  policy: Policy;
}

export default function PolicyEdit({ auth, policy }: Props) {
  const { data, setData, put, processing, errors } = useForm({
    title: policy.title,
    category: policy.category,
    description: policy.description || '',
    content: policy.content,
    review_date: policy.review_date?.split('T')[0] || '',
    requires_attestation: policy.requires_attestation,
    status: policy.status,
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    put(`/governance/policies/${policy.id}`);
  };

  return (
    <AppLayout>
      <Head title={`Edit: ${policy.title}`} />
      <div className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <h1 className="text-2xl font-bold text-gray-900 mb-6">Edit Policy</h1>

        <form onSubmit={handleSubmit}>
          <Card>
            <CardContent className="p-6 space-y-6">
              <div>
                <Label htmlFor="title">Policy Title</Label>
                <Input id="title" value={data.title} onChange={e => setData('title', e.target.value)} />
                {errors.title && <p className="text-red-500 text-sm mt-1">{errors.title}</p>}
              </div>

              <div>
                <Label htmlFor="category">Category</Label>
                <Select value={data.category} onValueChange={val => setData('category', val)}>
                  <SelectTrigger><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="governance">Governance</SelectItem>
                    <SelectItem value="financial">Financial</SelectItem>
                    <SelectItem value="hr">Human Resources</SelectItem>
                    <SelectItem value="health_safety">Health & Safety</SelectItem>
                    <SelectItem value="privacy">Privacy</SelectItem>
                    <SelectItem value="clinical">Clinical</SelectItem>
                    <SelectItem value="operational">Operational</SelectItem>
                    <SelectItem value="other">Other</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div>
                <Label htmlFor="description">Description</Label>
                <Textarea id="description" value={data.description} onChange={e => setData('description', e.target.value)} rows={2} />
              </div>

              <div>
                <Label htmlFor="content">Policy Content</Label>
                <Textarea id="content" value={data.content} onChange={e => setData('content', e.target.value)} rows={12} />
              </div>

              <div>
                <Label htmlFor="review_date">Review Date</Label>
                <Input id="review_date" type="date" value={data.review_date} onChange={e => setData('review_date', e.target.value)} />
              </div>

              <div>
                <Label htmlFor="status">Status</Label>
                <Select value={data.status} onValueChange={val => setData('status', val)}>
                  <SelectTrigger><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="draft">Draft</SelectItem>
                    <SelectItem value="active">Active</SelectItem>
                    <SelectItem value="under_review">Under Review</SelectItem>
                    <SelectItem value="archived">Archived</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="flex items-center gap-2">
                <Checkbox
                  id="requires_attestation"
                  checked={data.requires_attestation}
                  onCheckedChange={val => setData('requires_attestation', val === true)}
                />
                <label htmlFor="requires_attestation" className="text-sm">Require board member attestation</label>
              </div>

              <div className="flex justify-end gap-3">
                <Button type="button" variant="outline" onClick={() => window.history.back()}>Cancel</Button>
                <Button type="submit" disabled={processing}>Update Policy</Button>
              </div>
            </CardContent>
          </Card>
        </form>
      </div>
    </AppLayout>
  );
}
