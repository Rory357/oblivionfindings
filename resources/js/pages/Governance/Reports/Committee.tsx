import { Head } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

interface Metric {
  label: string;
  value: string;
  tone: string;
}

interface ReportCard {
  key: string;
  title: string;
  description: string;
  status: string;
  metrics: Metric[];
  highlights: string[];
}

interface Props extends PageProps {
  report: {
    committee: {
      name: string;
      description?: string | null;
    };
    headline: Metric[];
    sections: Array<{
      key: string;
      title: string;
      cards: ReportCard[];
    }>;
    risks: Array<{
      id: number;
      reference: string;
      title: string;
      category: string;
      residual_score: number;
      owner: string | null;
      within_appetite: boolean;
    }>;
  };
  generatedAt: string;
}

const statusStyles: Record<string, string> = {
  good: 'bg-green-100 text-green-800 border-green-200',
  warning: 'bg-amber-100 text-amber-800 border-amber-200',
  critical: 'bg-red-100 text-red-800 border-red-200',
  unknown: 'bg-slate-100 text-slate-800 border-slate-200',
};

const toneStyles: Record<string, string> = {
  default: 'text-slate-900',
  warning: 'text-amber-700',
  critical: 'text-red-700',
  muted: 'text-slate-500',
};

const severityStyle = (score: number) => {
  if (score >= 20) return 'bg-red-600 text-white';
  if (score >= 15) return 'bg-orange-500 text-white';
  if (score >= 10) return 'bg-amber-400 text-slate-950';

  return 'bg-green-500 text-white';
};

export default function CommitteeReport({ auth, report, generatedAt }: Props) {
  return (
    <AppLayout
      user={auth.user}
      breadcrumbs={[
        { title: 'Governance', href: '/governance/dashboard' },
        { title: 'Reports', href: '/governance/reports' },
        { title: 'Committee', href: '#' },
      ]}
    >
      <Head title={`${report.committee.name} Report`} />

      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="mb-6 space-y-2">
          <h1 className="text-3xl font-bold text-slate-900">{report.committee.name} Report</h1>
          <p className="text-sm text-slate-600">{report.committee.description || 'Committee-level assurance, delivery, and decision support.'}</p>
        </div>

        <div className="mb-8 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
          {report.headline.map((metric) => (
            <Card key={metric.label}>
              <CardContent className="pt-6">
                <p className="text-sm text-slate-500">{metric.label}</p>
                <p className={cn('mt-2 text-3xl font-bold', toneStyles[metric.tone] ?? toneStyles.default)}>{metric.value}</p>
              </CardContent>
            </Card>
          ))}
        </div>

        <div className="space-y-8">
          {report.sections.map((section) => (
            <section key={section.key} className="space-y-4">
              <h2 className="text-xl font-semibold text-slate-900">{section.title}</h2>
              <div className="grid gap-4 lg:grid-cols-2">
                {section.cards.map((card) => (
                  <Card key={card.key}>
                    <CardHeader className="space-y-3 pb-3">
                      <div className="flex items-start justify-between gap-3">
                        <div>
                          <CardTitle className="text-lg">{card.title}</CardTitle>
                          <CardDescription>{card.description}</CardDescription>
                        </div>
                        <Badge className={statusStyles[card.status] ?? statusStyles.unknown}>{card.status.replace(/_/g, ' ')}</Badge>
                      </div>
                    </CardHeader>
                    <CardContent className="space-y-4">
                      <div className="grid grid-cols-2 gap-3">
                        {card.metrics.map((metric) => (
                          <div key={`${card.key}-${metric.label}`} className="rounded-lg bg-slate-50 p-3">
                            <p className="text-xs uppercase tracking-wide text-slate-500">{metric.label}</p>
                            <p className={cn('mt-1 text-lg font-semibold', toneStyles[metric.tone] ?? toneStyles.default)}>{metric.value}</p>
                          </div>
                        ))}
                      </div>
                      {card.highlights.length > 0 && (
                        <div className="space-y-2">
                          {card.highlights.slice(0, 3).map((highlight) => (
                            <p key={highlight} className="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700">
                              {highlight}
                            </p>
                          ))}
                        </div>
                      )}
                    </CardContent>
                  </Card>
                ))}
              </div>
            </section>
          ))}
        </div>

        <Card className="mt-8">
          <CardHeader>
            <CardTitle>Assigned Risks</CardTitle>
            <CardDescription>Highest residual exposure within this committee’s remit.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            {report.risks.length ? (
              report.risks.map((risk) => (
                <div key={risk.id} className="flex flex-col gap-3 rounded-lg border border-slate-200 p-4 lg:flex-row lg:items-center lg:justify-between">
                  <div className="space-y-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <p className="font-medium text-slate-900">{risk.title}</p>
                      <Badge variant="outline">{risk.reference}</Badge>
                      <Badge variant="outline">{risk.category}</Badge>
                    </div>
                    {risk.owner && <p className="text-sm text-slate-600">Owner: {risk.owner}</p>}
                  </div>
                  <div className="flex items-center gap-3">
                    {!risk.within_appetite && (
                      <Badge className="bg-red-100 text-red-800 border-red-200">Outside appetite</Badge>
                    )}
                    <Badge className={severityStyle(risk.residual_score)}>{risk.residual_score}</Badge>
                  </div>
                </div>
              ))
            ) : (
              <p className="text-sm text-slate-500">No risks are currently assigned to this committee.</p>
            )}
          </CardContent>
        </Card>

        <p className="mt-6 text-right text-sm text-slate-400">
          Generated {new Date(generatedAt).toLocaleString('en-NZ', { timeZone: 'Pacific/Auckland' })}
        </p>
      </div>
    </AppLayout>
  );
}
