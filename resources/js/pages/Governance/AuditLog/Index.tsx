import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { FilterBar, FilterField } from '@/components/filter-bar';
import { EmptyState } from '@/components/ui/empty-state';
import { History, Download, ChevronLeft, ChevronRight } from 'lucide-react';
import { statusColors } from '@/lib/status-colors';

interface AuditEntry {
  kind: 'action' | 'change';
  id: number;
  user_id: number | null;
  type: string;
  entity_type: string;
  entity_id: number;
  description: string | null;
  old_values: Record<string, unknown> | null;
  new_values: Record<string, unknown> | null;
  metadata: Record<string, unknown> | null;
  ip_address: string | null;
  created_at: string;
  user: { id: number; name: string; email: string } | null;
}

interface Props extends PageProps {
  entries: {
    data: AuditEntry[];
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
  };
  filters: {
    user_id: number | null;
    entity_type: string | null;
    action: string | null;
    change_type: string | null;
    from: string | null;
    to: string | null;
  };
  entityTypes: string[];
  actionTypes: string[];
  changeTypes: string[];
}

export default function GovernanceAuditLogIndex({ auth, entries, filters, entityTypes, actionTypes, changeTypes }: Props) {
  const [values, setValues] = useState<Record<string, any>>({
    entity_type: filters.entity_type,
    action: filters.action,
    change_type: filters.change_type,
    from: filters.from,
    to: filters.to,
  });

  const applyFilters = (next: Record<string, any>) => {
    setValues(next);
    router.get('/governance/audit-log', next, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  };

  const fields: FilterField[] = [
    {
      type: 'select',
      key: 'entity_type',
      label: 'Entity',
      placeholder: 'Any entity',
      width: 'md',
      options: entityTypes.map((t) => ({ value: t, label: t })),
    },
    {
      type: 'select',
      key: 'action',
      label: 'Action',
      placeholder: 'Any action',
      width: 'md',
      options: actionTypes.map((t) => ({ value: t, label: t })),
    },
    {
      type: 'select',
      key: 'change_type',
      label: 'Change type',
      placeholder: 'Any change',
      width: 'md',
      options: changeTypes.map((t) => ({ value: t, label: t })),
    },
    {
      type: 'date-range',
      key: 'date',
      label: 'Date',
      width: 'sm',
    },
  ];

  const exportUrl = (() => {
    const params = new URLSearchParams();
    Object.entries(values).forEach(([k, v]) => {
      if (v) params.set(k, String(v));
    });
    return `/governance/audit-log/export?${params.toString()}`;
  })();

  const activeFilterCount = Object.values(values).filter(Boolean).length;

  return (
    <AppLayout
      user={auth.user}
      breadcrumbs={[
        { title: 'Governance', href: '/governance/dashboard' },
        { title: 'Audit Log', href: '/governance/audit-log' },
      ]}
    >
      <Head title="Governance Audit Log" />

      <PageLayout
        hero={
          <PageHero
            icon={History}
            category="governance"
            variant="compact"
            title="Governance Audit Log"
            description="Cross-module changes and action events on governance entities. Filter, scroll, or export."
            stats={[
              { label: 'Total events', value: entries.total },
              { label: 'Page', value: `${entries.current_page} / ${entries.last_page}` },
            ]}
            actions={
              <Button asChild variant="outline">
                <a href={exportUrl} download>
                  <Download className="mr-2 h-4 w-4" /> Export CSV
                </a>
              </Button>
            }
          />
        }
      >
        <FilterBar
          fields={fields}
          values={values}
          onChange={(key, value) => {
            const next = { ...values, [key]: value || null };
            applyFilters(next);
          }}
          onReset={() => applyFilters({ entity_type: null, action: null, change_type: null, from: null, to: null })}
          activeCount={activeFilterCount}
          className="mb-4"
        />

        <Card>
          <CardHeader className="pb-3">
            <CardTitle>Recent activity</CardTitle>
          </CardHeader>
          <CardContent className="space-y-2">
            {entries.data.length === 0 ? (
              <EmptyState
                icon={History}
                title="No audit events match your filters"
                description="Try clearing filters, or narrow your date range."
              />
            ) : (
              entries.data.map((entry) => (
                <article
                  key={`${entry.kind}-${entry.id}`}
                  className="flex flex-col gap-2 rounded-lg border bg-card p-3 lg:flex-row lg:items-start lg:justify-between"
                >
                  <div className="space-y-1">
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                      <Badge className={statusColors[entry.kind === 'action' ? 'in_progress' : 'completed'] ?? ''}>
                        {entry.kind === 'action' ? 'Action' : 'Change'}
                      </Badge>
                      <span className="font-medium">{entry.type}</span>
                      <span className="text-muted-foreground">
                        on {entry.entity_type}#{entry.entity_id}
                      </span>
                    </div>
                    {entry.description && (
                      <p className="text-sm text-muted-foreground">{entry.description}</p>
                    )}
                    <p className="text-xs text-muted-foreground">
                      {entry.user ? `${entry.user.name} (${entry.user.email})` : 'System'} ·{' '}
                      {entry.ip_address ?? 'no IP'} · {entry.created_at}
                    </p>
                  </div>
                </article>
              ))
            )}
          </CardContent>
        </Card>

        {entries.last_page > 1 && (
          <div className="mt-4 flex items-center justify-between">
            <p className="text-sm text-muted-foreground">
              Page {entries.current_page} of {entries.last_page}
            </p>
            <div className="flex items-center gap-2">
              <Button
                variant="outline"
                size="sm"
                disabled={entries.current_page <= 1}
                onClick={() => applyFilters({ ...values, page: entries.current_page - 1 })}
              >
                <ChevronLeft className="mr-1 h-4 w-4" /> Previous
              </Button>
              <Button
                variant="outline"
                size="sm"
                disabled={entries.current_page >= entries.last_page}
                onClick={() => applyFilters({ ...values, page: entries.current_page + 1 })}
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
