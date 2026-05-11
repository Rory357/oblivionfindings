import { Head, Link, router } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { BookOpen, Plus, Eye } from 'lucide-react';
import { cn } from '@/lib/utils';

interface Policy {
  id: number;
  title: string;
  category: string;
  version: number;
  status: string;
  effective_date: string;
  review_date: string;
  requires_attestation: boolean;
  attestations_count: number;
}

interface Props extends PageProps {
  policies: {
    data: Policy[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
  };
  categories: Array<{ value: string; label: string }>;
}

export default function PolicyIndex({ auth, policies, categories }: Props) {
  const getStatusColor = (status: string) => ({
    draft: 'bg-muted text-foreground',
    active: 'bg-status-success-bg text-status-success',
    under_review: 'bg-status-warning-bg text-status-warning',
    archived: 'bg-status-critical-bg text-status-critical',
  }[status] || 'bg-muted text-foreground');

  const getCategoryLabel = (value: string) =>
    categories.find(c => c.value === value)?.label ?? value;

  return (
    <AppLayout>
      <Head title="Governance Policies" />
      <div className="flex flex-col gap-6 p-4 md:p-6">
        <div className="flex items-center justify-between mb-6">
          <div>
            <h1 className="text-2xl font-bold text-foreground">Governance Policies</h1>
            <p className="text-muted-foreground mt-1">Board policies, procedures, and attestation tracking</p>
          </div>
          <Link href="/governance/policies/create">
            <Button><Plus className="w-4 h-4 mr-2" /> New Policy</Button>
          </Link>
        </div>

        <div className="grid gap-4">
          {policies.data.map(policy => (
            <Card key={policy.id}>
              <CardContent className="p-4">
                <div className="flex items-center justify-between">
                  <div className="flex-1">
                    <div className="flex items-center gap-3">
                      <BookOpen className="w-5 h-5 text-status-info" />
                      <Link href={`/governance/policies/${policy.id}`} className="text-lg font-medium hover:text-status-info">
                        {policy.title}
                      </Link>
                      <Badge variant="outline">v{policy.version}</Badge>
                      <Badge className={cn('text-xs', getStatusColor(policy.status))}>
                        {policy.status.replace('_', ' ')}
                      </Badge>
                    </div>
                    <div className="flex items-center gap-4 mt-2 text-sm text-muted-foreground">
                      <span>{getCategoryLabel(policy.category)}</span>
                      <span>Review: {new Date(policy.review_date).toLocaleDateString('en-NZ', { year: 'numeric', month: 'short', day: 'numeric' })}</span>
                      {policy.requires_attestation && (
                        <span>{policy.attestations_count} attestation(s)</span>
                      )}
                    </div>
                  </div>
                  <Link href={`/governance/policies/${policy.id}`}>
                    <Button variant="ghost" size="sm"><Eye className="w-4 h-4" /></Button>
                  </Link>
                </div>
              </CardContent>
            </Card>
          ))}

          {policies.data.length === 0 && (
            <Card>
              <CardContent className="p-8 text-center text-muted-foreground">
                No policies found. Create your first governance policy.
              </CardContent>
            </Card>
          )}
        </div>

        {policies.links && policies.links.length > 3 && (
          <div className="flex justify-center gap-1 mt-6">
            {policies.links.map((link, i) => (
              <Link
                key={i}
                href={link.url || '#'}
                className={cn(
                  'px-3 py-1 rounded text-sm',
                  link.active ? 'bg-status-info text-white' : 'bg-card text-muted-foreground hover:bg-muted',
                  !link.url && 'opacity-50 pointer-events-none'
                )}
                dangerouslySetInnerHTML={{ __html: link.label }}
              />
            ))}
          </div>
        )}
      </div>
    </AppLayout>
  );
}
