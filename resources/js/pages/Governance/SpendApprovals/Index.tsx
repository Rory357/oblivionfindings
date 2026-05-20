import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { FilterBar, FilterField } from '@/components/filter-bar';
import { EmptyState } from '@/components/ui/empty-state';
import { HandCoins, Plus, ChevronLeft, ChevronRight } from 'lucide-react';
import { statusColors } from '@/lib/status-colors';

interface Approval {
  id: number;
  reference: string;
  title: string;
  category: string;
  amount: number;
  currency: string;
  status: string;
  requires_board: boolean;
  requested_by_id?: number;
  requestedBy?: { id: number; name: string } | null;
  decidedBy?: { id: number; name: string } | null;
  resolution?: { id: number; title: string; outcome: string } | null;
  submitted_at: string | null;
  decided_at: string | null;
  created_at: string;
}

interface Props extends PageProps {
  approvals: {
    data: Approval[];
    current_page: number;
    last_page: number;
    total: number;
  };
  filters: {
    status: string | null;
    category: string | null;
    search: string | null;
  };
  summary: {
    pending: number;
    approved_ytd: number;
    rejected_ytd: number;
  };
  categories: Record<string, string>;
  thresholds: Record<string, number>;
}

const STATUSES = [
  { value: 'draft', label: 'Draft' },
  { value: 'submitted', label: 'Submitted' },
  { value: 'approved', label: 'Approved' },
  { value: 'rejected', label: 'Rejected' },
  { value: 'expired', label: 'Expired' },
];

const formatNzd = (amount: number) =>
  new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount || 0);

export default function SpendApprovalsIndex({ auth, approvals, filters, summary, categories, thresholds }: Props) {
  const [values, setValues] = useState<Record<string, any>>({
    status: filters.status,
    category: filters.category,
    search: filters.search,
  });

  const applyFilters = (next: Record<string, any>) => {
    setValues(next);
    router.get('/governance/spend-approvals', next, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  };

  const fields: FilterField[] = [
    {
      type: 'search',
      key: 'search',
      label: 'Search',
      placeholder: 'Title, reference, description',
      width: 'lg',
    },
    {
      type: 'select',
      key: 'status',
      label: 'Status',
      placeholder: 'Any status',
      width: 'md',
      options: STATUSES,
    },
    {
      type: 'select',
      key: 'category',
      label: 'Category',
      placeholder: 'Any category',
      width: 'md',
      options: Object.entries(categories).map(([value, label]) => ({ value, label })),
    },
  ];

  return (
    <AppLayout
      user={auth.user}
      breadcrumbs={[
        { title: 'Governance', href: '/governance/dashboard' },
        { title: 'Spend Approvals', href: '/governance/spend-approvals' },
      ]}
    >
      <Head title="Spend Approvals" />

      <PageLayout
        hero={
          <PageHero
            icon={HandCoins}
            category="governance"
            title="Spend Approvals"
            description="Board / finance committee sign-off for spend above configured thresholds."
            stats={[
              { label: 'Pending', value: summary.pending },
              { label: 'Approved YTD', value: formatNzd(summary.approved_ytd) },
              { label: 'Rejected YTD', value: formatNzd(summary.rejected_ytd) },
            ]}
            actions={
              <Button asChild>
                <Link href="/governance/spend-approvals/create">
                  <Plus className="mr-2 h-4 w-4" /> New Request
                </Link>
              </Button>
            }
          />
        }
      >
        <Card className="mb-4">
          <CardHeader className="pb-3">
            <CardTitle className="text-base">Approval thresholds</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {Object.entries(thresholds).map(([key, value]) => (
              <div key={key} className="rounded-lg border p-3">
                <p className="text-xs uppercase tracking-wide text-muted-foreground">
                  {categories[key] ?? key}
                </p>
                <p className="mt-1 text-lg font-semibold">{formatNzd(value)}</p>
                <p className="text-xs text-muted-foreground">requires sign-off</p>
              </div>
            ))}
          </CardContent>
        </Card>

        <FilterBar
          fields={fields}
          values={values}
          onChange={(key, value) => applyFilters({ ...values, [key]: value || null })}
          onReset={() => applyFilters({ status: null, category: null, search: null })}
          activeCount={Object.values(values).filter(Boolean).length}
          className="mb-4"
        />

        <Card>
          <CardHeader className="pb-3">
            <CardTitle>Requests</CardTitle>
          </CardHeader>
          <CardContent className="space-y-3">
            {approvals.data.length === 0 ? (
              <EmptyState
                icon={HandCoins}
                title="No spend approvals yet"
                description="Submit a request for board or finance-committee sign-off when spend exceeds the configured threshold."
                action={
                  <Button asChild>
                    <Link href="/governance/spend-approvals/create">New Request</Link>
                  </Button>
                }
              />
            ) : (
              approvals.data.map((a) => (
                <Link
                  key={a.id}
                  href={`/governance/spend-approvals/${a.id}`}
                  className="block rounded-lg border p-3 transition-colors hover:bg-muted/30"
                >
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-mono text-xs text-muted-foreground">{a.reference}</span>
                    <Badge className={statusColors[a.status] ?? ''}>{a.status}</Badge>
                    <Badge variant="outline">{categories[a.category] ?? a.category}</Badge>
                    {a.requires_board && <Badge variant="outline">Board sign-off</Badge>}
                    <span className="ml-auto font-semibold">{formatNzd(a.amount)}</span>
                  </div>
                  <p className="mt-1 font-medium">{a.title}</p>
                  <p className="text-xs text-muted-foreground">
                    Requested by {a.requestedBy?.name ?? 'unknown'} · created {a.created_at}
                    {a.decided_at && a.decidedBy && (
                      <> · decided by {a.decidedBy.name} ({a.decided_at})</>
                    )}
                  </p>
                </Link>
              ))
            )}
          </CardContent>
        </Card>

        {approvals.last_page > 1 && (
          <div className="mt-4 flex items-center justify-between">
            <p className="text-sm text-muted-foreground">
              Page {approvals.current_page} of {approvals.last_page} ({approvals.total} total)
            </p>
            <div className="flex items-center gap-2">
              <Button
                variant="outline"
                size="sm"
                disabled={approvals.current_page <= 1}
                onClick={() => applyFilters({ ...values, page: approvals.current_page - 1 })}
              >
                <ChevronLeft className="mr-1 h-4 w-4" /> Previous
              </Button>
              <Button
                variant="outline"
                size="sm"
                disabled={approvals.current_page >= approvals.last_page}
                onClick={() => applyFilters({ ...values, page: approvals.current_page + 1 })}
              >
                Next <ChevronRight className="ml-1 h-4 w-4" />
              </Button>
            </div>
          </div>
        )}
      </PageLayout>
    </AppLayout>
  );
}
