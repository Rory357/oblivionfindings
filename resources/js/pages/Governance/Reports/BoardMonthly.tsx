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
  freshness: { label: string };
  source: string;
  metrics: Metric[];
  highlights: string[];
  href: string;
}

interface ReportSection {
  key: string;
  title: string;
  cards: ReportCard[];
}

interface Props extends PageProps {
  report: {
    headline: Metric[];
    sections: ReportSection[];
  };
  generatedAt: string;
}

const statusStyles: Record<string, string> = {
  good: 'bg-green-100 text-green-800 border-green-200',
  warning: 'bg-amber-100 text-amber-800 border-amber-200',
  critical: 'bg-red-100 text-red-800 border-red-200',
  unknown: 'bg-muted text-foreground border-border',
};

const toneStyles: Record<string, string> = {
  default: 'text-foreground',
  warning: 'text-amber-700',
  critical: 'text-red-700',
  muted: 'text-muted-foreground',
};

export default function BoardMonthly({ auth, report, generatedAt }: Props) {
  return (
    <AppLayout
      user={auth.user}
      breadcrumbs={[
        { title: 'Governance', href: '/governance/dashboard' },
        { title: 'Reports', href: '/governance/reports' },
        { title: 'Board Monthly', href: '#' },
      ]}
    >
      <Head title="Board Monthly Report" />

      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="mb-6 space-y-2">
          <h1 className="text-3xl font-bold text-foreground">Board Monthly Report</h1>
          <p className="text-sm text-muted-foreground">A board-ready summary of decisions, delivery, assurance, and organisational controls.</p>
        </div>

        <div className="mb-8 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
          {report.headline.map((metric) => (
            <Card key={metric.label}>
              <CardContent className="pt-6">
                <p className="text-sm text-muted-foreground">{metric.label}</p>
                <p className={cn('mt-2 text-3xl font-bold', toneStyles[metric.tone] ?? toneStyles.default)}>{metric.value}</p>
              </CardContent>
            </Card>
          ))}
        </div>

        <div className="space-y-8">
          {report.sections.map((section) => (
            <section key={section.key} className="space-y-4">
              <h2 className="text-xl font-semibold text-foreground">{section.title}</h2>
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
                      <div className="flex flex-wrap gap-2 text-xs">
                        <Badge variant="outline">{card.source}</Badge>
                        <Badge variant="outline">{card.freshness.label}</Badge>
                      </div>
                    </CardHeader>
                    <CardContent className="space-y-4">
                      <div className="grid grid-cols-2 gap-3">
                        {card.metrics.map((metric) => (
                          <div key={`${card.key}-${metric.label}`} className="rounded-lg bg-muted p-3">
                            <p className="text-xs uppercase tracking-wide text-muted-foreground">{metric.label}</p>
                            <p className={cn('mt-1 text-lg font-semibold', toneStyles[metric.tone] ?? toneStyles.default)}>{metric.value}</p>
                          </div>
                        ))}
                      </div>
                      {card.highlights.length > 0 && (
                        <div className="space-y-2">
                          {card.highlights.slice(0, 3).map((highlight) => (
                            <p key={highlight} className="rounded-lg border border-border px-3 py-2 text-sm text-foreground">
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

        <p className="mt-6 text-right text-sm text-muted-foreground">
          Generated {new Date(generatedAt).toLocaleString('en-NZ', { timeZone: 'Pacific/Auckland' })}
        </p>
      </div>
    </AppLayout>
  );
}
