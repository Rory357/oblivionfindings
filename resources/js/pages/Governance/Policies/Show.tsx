import { Head, Link, useForm, router } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Textarea } from '@/components/ui/textarea';
import { BookOpen, CheckCircle, Shield, Clock } from 'lucide-react';
import { cn } from '@/lib/utils';

interface Attestation {
  id: number;
  user: { id: number; name: string };
  version: number;
  attested_at: string;
  notes: string | null;
}

interface Policy {
  id: number;
  title: string;
  category: string;
  description: string | null;
  content: string;
  version: number;
  status: string;
  effective_date: string;
  review_date: string;
  requires_attestation: boolean;
  approved_by_user: { name: string } | null;
  approved_at: string | null;
  attestations: Attestation[];
}

interface Props extends PageProps {
  policy: Policy;
  attestationStats: { total_required: number; completed: number };
  canEdit: boolean;
}

export default function PolicyShow({ auth, policy, attestationStats, canEdit }: Props) {
  const attestForm = useForm({ acknowledged: false, notes: '' });

  const handleAttest = (e: React.FormEvent) => {
    e.preventDefault();
    attestForm.post(`/governance/policies/${policy.id}/attest`);
  };

  const handleApprove = () => {
    router.post(`/governance/policies/${policy.id}/approve`);
  };

  const getStatusColor = (status: string) => ({
    draft: 'bg-gray-100 text-gray-800',
    active: 'bg-green-100 text-green-800',
    under_review: 'bg-yellow-100 text-yellow-800',
    archived: 'bg-red-100 text-red-800',
  }[status] || 'bg-gray-100 text-gray-800');

  return (
    <AppLayout>
      <Head title={policy.title} />
      <div className="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div className="flex items-center justify-between mb-6">
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-2xl font-bold text-gray-900">{policy.title}</h1>
              <Badge variant="outline">v{policy.version}</Badge>
              <Badge className={cn('text-xs', getStatusColor(policy.status))}>
                {policy.status.replace('_', ' ')}
              </Badge>
            </div>
            <p className="text-gray-500 mt-1">{policy.category} policy</p>
          </div>
          <div className="flex gap-2">
            {canEdit && policy.status === 'draft' && (
              <Button onClick={handleApprove} variant="default">
                <Shield className="w-4 h-4 mr-2" /> Approve
              </Button>
            )}
            {canEdit && (
              <Link href={`/governance/policies/${policy.id}/edit`}>
                <Button variant="outline">Edit</Button>
              </Link>
            )}
          </div>
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <div className="lg:col-span-2 space-y-6">
            <Card>
              <CardHeader>
                <CardTitle>Policy Content</CardTitle>
              </CardHeader>
              <CardContent>
                <div className="prose max-w-none" dangerouslySetInnerHTML={{ __html: policy.content }} />
              </CardContent>
            </Card>

            {policy.requires_attestation && (
              <Card>
                <CardHeader>
                  <CardTitle>Your Attestation</CardTitle>
                  <CardDescription>Acknowledge that you have read and understood this policy</CardDescription>
                </CardHeader>
                <CardContent>
                  <form onSubmit={handleAttest} className="space-y-4">
                    <div className="flex items-center gap-2">
                      <Checkbox
                        id="acknowledged"
                        checked={attestForm.data.acknowledged}
                        onCheckedChange={(val) => attestForm.setData('acknowledged', val === true)}
                      />
                      <label htmlFor="acknowledged" className="text-sm font-medium">
                        I have read and understood this policy (v{policy.version})
                      </label>
                    </div>
                    <Textarea
                      placeholder="Optional notes..."
                      value={attestForm.data.notes}
                      onChange={e => attestForm.setData('notes', e.target.value)}
                    />
                    <Button type="submit" disabled={!attestForm.data.acknowledged || attestForm.processing}>
                      <CheckCircle className="w-4 h-4 mr-2" /> Submit Attestation
                    </Button>
                  </form>
                </CardContent>
              </Card>
            )}
          </div>

          <div className="space-y-6">
            <Card>
              <CardHeader>
                <CardTitle>Details</CardTitle>
              </CardHeader>
              <CardContent className="space-y-3 text-sm">
                <div className="flex justify-between">
                  <span className="text-gray-500">Version</span>
                  <span>{policy.version}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-gray-500">Effective Date</span>
                  <span>{new Date(policy.effective_date).toLocaleDateString('en-NZ')}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-gray-500">Review Date</span>
                  <span>{new Date(policy.review_date).toLocaleDateString('en-NZ')}</span>
                </div>
                {policy.approved_by_user && (
                  <div className="flex justify-between">
                    <span className="text-gray-500">Approved By</span>
                    <span>{policy.approved_by_user.name}</span>
                  </div>
                )}
              </CardContent>
            </Card>

            {policy.requires_attestation && (
              <Card>
                <CardHeader>
                  <CardTitle>Attestation Progress</CardTitle>
                </CardHeader>
                <CardContent>
                  <div className="text-center mb-3">
                    <span className="text-3xl font-bold">{attestationStats.completed}</span>
                    <span className="text-gray-500"> / {attestationStats.total_required}</span>
                  </div>
                  <div className="w-full bg-gray-200 rounded-full h-2">
                    <div
                      className="bg-green-600 h-2 rounded-full transition-all"
                      style={{ width: `${attestationStats.total_required > 0 ? (attestationStats.completed / attestationStats.total_required) * 100 : 0}%` }}
                    />
                  </div>
                  {policy.attestations.length > 0 && (
                    <div className="mt-4 space-y-2">
                      {policy.attestations.map(att => (
                        <div key={att.id} className="flex items-center gap-2 text-sm">
                          <CheckCircle className="w-4 h-4 text-green-500" />
                          <span>{att.user.name}</span>
                          <span className="text-gray-400 ml-auto">{new Date(att.attested_at).toLocaleDateString('en-NZ')}</span>
                        </div>
                      ))}
                    </div>
                  )}
                </CardContent>
              </Card>
            )}
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
