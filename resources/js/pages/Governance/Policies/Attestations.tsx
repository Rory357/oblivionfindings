import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Textarea } from '@/components/ui/textarea';
import { EmptyState } from '@/components/ui/empty-state';
import { BookOpen, CheckCircle2, Clock } from 'lucide-react';
import { statusColors } from '@/lib/status-colors';

interface PolicySummary {
  id: number;
  title: string;
  category: string;
  version: number;
  effective_from: string | null;
  next_review_date: string | null;
  my_attestation: {
    acknowledged: boolean;
    acknowledged_at: string | null;
    notes: string | null;
  } | null;
  total_required: number;
  total_attested: number | null;
}

interface Props extends PageProps {
  outstanding: PolicySummary[];
  completed: PolicySummary[];
  canManage: boolean;
  summary: {
    outstanding_count: number;
    completed_count: number;
    board_member_count: number;
  };
}

function AttestForm({ policy }: { policy: PolicySummary }) {
  const [open, setOpen] = useState(false);
  const form = useForm({
    acknowledged: true,
    notes: '',
  });

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    form.post(`/governance/policies/${policy.id}/attest`, {
      onSuccess: () => setOpen(false),
    });
  };

  if (!open) {
    return (
      <Button size="sm" onClick={() => setOpen(true)}>
        Attest to this policy
      </Button>
    );
  }

  return (
    <form onSubmit={submit} className="mt-3 space-y-3 rounded-lg border bg-muted/30 p-3">
      <label className="flex items-start gap-2 text-sm">
        <Checkbox
          checked={form.data.acknowledged}
          onCheckedChange={(v) => form.setData('acknowledged', v === true)}
        />
        <span>I have read and understood this policy.</span>
      </label>
      <Textarea
        rows={3}
        placeholder="Optional notes (e.g. queries, clarifications)"
        value={form.data.notes}
        onChange={(e) => form.setData('notes', e.target.value)}
      />
      <div className="flex items-center justify-end gap-2">
        <Button type="button" variant="outline" size="sm" onClick={() => setOpen(false)}>
          Cancel
        </Button>
        <Button type="submit" size="sm" disabled={!form.data.acknowledged || form.processing}>
          {form.processing ? 'Recording…' : 'Confirm attestation'}
        </Button>
      </div>
    </form>
  );
}

export default function PolicyAttestations({ auth, outstanding, completed, canManage, summary }: Props) {
  return (
    <AppLayout
      user={auth.user}
      breadcrumbs={[
        { title: 'Governance', href: '/governance/dashboard' },
        { title: 'Policies', href: '/governance/policies' },
        { title: 'Attestations', href: '/governance/policies/attestations' },
      ]}
    >
      <Head title="Policy Attestations" />

      <PageLayout
        hero={
          <PageHero
            icon={BookOpen}
            category="governance"
            title="Policy Attestations"
            description="Record that you have read and understood each approved governance policy."
            stats={[
              { label: 'Outstanding', value: summary.outstanding_count },
              { label: 'Completed', value: summary.completed_count },
              { label: 'Active board members', value: summary.board_member_count },
            ]}
          />
        }
      >
        <Card className="mb-4">
          <CardHeader className="pb-3">
            <CardTitle className="flex items-center gap-2 text-base">
              <Clock className="h-4 w-4 text-status-warning" />
              Outstanding
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-3">
            {outstanding.length === 0 ? (
              <EmptyState
                icon={CheckCircle2}
                variant="compact"
                title="You're up to date"
                description="All approved policies have been attested to."
              />
            ) : (
              outstanding.map((policy) => (
                <div key={policy.id} className="rounded-lg border p-3">
                  <div className="flex flex-wrap items-center gap-2">
                    <Link href={`/governance/policies/${policy.id}`} className="font-medium hover:underline">
                      {policy.title}
                    </Link>
                    <Badge variant="outline">v{policy.version}</Badge>
                    <Badge className={statusColors.draft ?? ''}>{policy.category}</Badge>
                    {canManage && policy.total_attested !== null && (
                      <Badge variant="outline" className="ml-auto">
                        {policy.total_attested}/{policy.total_required} board members
                      </Badge>
                    )}
                  </div>
                  <p className="mt-1 text-xs text-muted-foreground">
                    Effective {policy.effective_from ?? '—'} · Next review {policy.next_review_date ?? '—'}
                  </p>
                  <AttestForm policy={policy} />
                </div>
              ))
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="flex items-center gap-2 text-base">
              <CheckCircle2 className="h-4 w-4 text-status-success" />
              Completed
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-2">
            {completed.length === 0 ? (
              <EmptyState
                icon={Clock}
                variant="compact"
                title="No attestations recorded yet"
                description="Policies you have attested to will appear here."
              />
            ) : (
              completed.map((policy) => (
                <Link
                  key={policy.id}
                  href={`/governance/policies/${policy.id}`}
                  className="flex flex-wrap items-center gap-2 rounded-lg border p-3 transition-colors hover:bg-muted/30"
                >
                  <span className="font-medium">{policy.title}</span>
                  <Badge variant="outline">v{policy.version}</Badge>
                  <Badge className={statusColors.approved ?? ''}>Attested</Badge>
                  <span className="ml-auto text-xs text-muted-foreground">
                    {policy.my_attestation?.acknowledged_at ?? ''}
                  </span>
                </Link>
              ))
            )}
          </CardContent>
        </Card>
      </PageLayout>
    </AppLayout>
  );
}
