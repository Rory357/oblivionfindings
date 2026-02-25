import { Head, Link } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { HeartPulse, TrendingUp, TrendingDown, Minus, Plus } from 'lucide-react';
import { useState } from 'react';
import { cn } from '@/lib/utils';

interface Indicator {
  id: number;
  name: string;
  category: string;
  target_value: number;
  target_direction: 'above' | 'below' | 'equal';
  unit: string;
  reporting_frequency: string;
}

interface Snapshot {
  id: number;
  period_start: string;
  period_end: string;
  indicator_values: Array<{ indicator_id: number; value: number }>;
  narrative: string | null;
}

interface Props extends PageProps {
  indicators: Indicator[];
  latestSnapshot: Snapshot | null;
}

export default function ClinicalDashboard({ auth, indicators, latestSnapshot }: Props) {
  const [showForm, setShowForm] = useState(false);

  const getLatestValue = (indicatorId: number): number | null => {
    if (!latestSnapshot) return null;
    const entry = latestSnapshot.indicator_values.find(v => v.indicator_id === indicatorId);
    return entry?.value ?? null;
  };

  const isOnTarget = (indicator: Indicator, value: number | null): boolean | null => {
    if (value === null) return null;
    switch (indicator.target_direction) {
      case 'above': return value >= indicator.target_value;
      case 'below': return value <= indicator.target_value;
      case 'equal': return value === indicator.target_value;
    }
  };

  const getCategoryLabel = (cat: string) => ({
    medication_safety: 'Medication Safety',
    incident_rates: 'Incident Rates',
    restraint_usage: 'Restraint Usage',
    infection_control: 'Infection Control',
    falls: 'Falls',
    client_outcomes: 'Client Outcomes',
    other: 'Other',
  }[cat] || cat);

  const grouped = indicators.reduce((acc, ind) => {
    (acc[ind.category] = acc[ind.category] || []).push(ind);
    return acc;
  }, {} as Record<string, Indicator[]>);

  return (
    <AppLayout>
      <Head title="Clinical Governance" />
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div className="flex items-center justify-between mb-6">
          <div>
            <h1 className="text-2xl font-bold text-gray-900">Clinical Governance Dashboard</h1>
            <p className="text-gray-500 mt-1">Key clinical quality and safety indicators</p>
          </div>
          <div className="flex gap-2">
            <Link href="/governance/clinical/trends">
              <Button variant="outline"><TrendingUp className="w-4 h-4 mr-2" /> Trends</Button>
            </Link>
          </div>
        </div>

        {Object.entries(grouped).map(([category, inds]) => (
          <div key={category} className="mb-8">
            <h2 className="text-lg font-semibold text-gray-900 mb-3">{getCategoryLabel(category)}</h2>
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
              {inds.map(indicator => {
                const value = getLatestValue(indicator.id);
                const onTarget = isOnTarget(indicator, value);
                return (
                  <Card key={indicator.id}>
                    <CardContent className="p-4">
                      <div className="flex items-center justify-between mb-2">
                        <span className="text-sm font-medium text-gray-700">{indicator.name}</span>
                        {onTarget !== null && (
                          <Badge className={cn('text-xs', onTarget ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800')}>
                            {onTarget ? 'On Target' : 'Off Target'}
                          </Badge>
                        )}
                      </div>
                      <div className="flex items-baseline gap-2">
                        <span className="text-2xl font-bold">{value !== null ? value : '—'}</span>
                        <span className="text-sm text-gray-500">{indicator.unit}</span>
                      </div>
                      <div className="text-xs text-gray-400 mt-1">
                        Target: {indicator.target_direction === 'above' ? '≥' : indicator.target_direction === 'below' ? '≤' : '='} {indicator.target_value} {indicator.unit}
                      </div>
                    </CardContent>
                  </Card>
                );
              })}
            </div>
          </div>
        ))}

        {indicators.length === 0 && (
          <Card><CardContent className="p-8 text-center text-gray-500">No clinical indicators configured yet.</CardContent></Card>
        )}
      </div>
    </AppLayout>
  );
}
