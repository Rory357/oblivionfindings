import { Head } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

interface HeatmapCell {
  score: number;
  count: number;
  color: string;
}

interface TrendPoint {
  month: string;
  new_risks: number;
}

interface Props extends PageProps {
  heatmap: HeatmapCell[][];
  trend: TrendPoint[];
}

export default function RiskHeatmap({ auth, heatmap, trend }: Props) {
  const impactLabels = ['Insignificant', 'Minor', 'Moderate', 'Major', 'Catastrophic'];
  const likelihoodLabels = ['Almost Certain', 'Likely', 'Possible', 'Unlikely', 'Rare'];

  const getRiskLevel = (score: number) => {
    if (score >= 20) return 'Critical';
    if (score >= 15) return 'High';
    if (score >= 10) return 'Medium';
    return 'Low';
  };

  return (
    <AppLayout
      user={auth.user}
      breadcrumbs={[
        { title: 'Governance', href: '/governance/dashboard' },
        { title: 'Risks', href: '/governance/risks' },
        { title: 'Heatmap', href: '/governance/risks/heatmap' },
      ]}
    >
      <Head title="Risk Heatmap" />

      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          {/* Header */}
          <div className="mb-6">
            <h1 className="text-3xl font-bold text-gray-900">Risk Heatmap</h1>
            <p className="text-gray-500 mt-1">Visual representation of risk distribution</p>
          </div>

          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {/* Heatmap */}
            <Card className="lg:col-span-2">
              <CardHeader>
                <CardTitle>Inherent Risk Matrix</CardTitle>
                <CardDescription>Likelihood × Impact = Risk Score</CardDescription>
              </CardHeader>
              <CardContent>
                <div className="overflow-x-auto">
                  <div className="min-w-[500px]">
                    {/* Header row for Impact */}
                    <div className="flex">
                      <div className="w-24"></div>
                      <div className="flex-1 text-center text-sm font-medium text-gray-500 mb-2">
                        IMPACT →
                      </div>
                    </div>
                    
                    {/* Matrix */}
                    <div className="flex">
                      {/* Likelihood labels */}
                      <div className="w-24 flex flex-col justify-around text-xs text-gray-500 pr-2">
                        <div className="h-12 flex items-center justify-end">
                          <span className="-rotate-90 whitespace-nowrap">LIKELIHOOD ↓</span>
                        </div>
                        {likelihoodLabels.map((label, i) => (
                          <div key={i} className="h-16 flex items-center justify-end text-right">
                            {label}
                          </div>
                        ))}
                      </div>

                      {/* Grid */}
                      <div className="flex-1">
                        {/* Impact header */}
                        <div className="flex">
                          {impactLabels.map((label, i) => (
                            <div key={i} className="flex-1 text-xs text-center text-gray-500 py-2">
                              {label}
                            </div>
                          ))}
                        </div>

                        {/* Cells */}
                        {heatmap.map((row, rowIndex) => (
                          <div key={rowIndex} className="flex">
                            {row.map((cell, colIndex) => (
                              <div
                                key={colIndex}
                                className={cn(
                                  "flex-1 h-16 border border-white flex flex-col items-center justify-center text-white cursor-pointer hover:opacity-80 transition-opacity",
                                  cell.count === 0 && "opacity-30"
                                )}
                                style={{ backgroundColor: cell.color }}
                                title={`Score: ${cell.score}, Risks: ${cell.count}`}
                              >
                                <span className="text-lg font-bold">{cell.score}</span>
                                {cell.count > 0 && (
                                  <span className="text-xs">{cell.count} risks</span>
                                )}
                              </div>
                            ))}
                          </div>
                        ))}
                      </div>
                    </div>
                  </div>
                </div>

                {/* Legend */}
                <div className="flex items-center justify-center gap-4 mt-6">
                  <div className="flex items-center gap-2">
                    <div className="w-4 h-4 rounded" style={{ backgroundColor: '#16a34a' }}></div>
                    <span className="text-sm text-gray-600">Low (1-9)</span>
                  </div>
                  <div className="flex items-center gap-2">
                    <div className="w-4 h-4 rounded" style={{ backgroundColor: '#ca8a04' }}></div>
                    <span className="text-sm text-gray-600">Medium (10-14)</span>
                  </div>
                  <div className="flex items-center gap-2">
                    <div className="w-4 h-4 rounded" style={{ backgroundColor: '#ea580c' }}></div>
                    <span className="text-sm text-gray-600">High (15-19)</span>
                  </div>
                  <div className="flex items-center gap-2">
                    <div className="w-4 h-4 rounded" style={{ backgroundColor: '#dc2626' }}></div>
                    <span className="text-sm text-gray-600">Critical (20-25)</span>
                  </div>
                </div>
              </CardContent>
            </Card>

            {/* Stats & Trend */}
            <div className="space-y-6">
              {/* Risk Distribution */}
              <Card>
                <CardHeader>
                  <CardTitle>Risk Distribution</CardTitle>
                </CardHeader>
                <CardContent>
                  <div className="space-y-3">
                    {heatmap.flat().reduce((acc, cell) => {
                      const level = getRiskLevel(cell.score);
                      acc[level] = (acc[level] || 0) + cell.count;
                      return acc;
                    }, {} as Record<string, number>) && Object.entries(
                      heatmap.flat().reduce((acc, cell) => {
                        const level = getRiskLevel(cell.score);
                        acc[level] = (acc[level] || 0) + cell.count;
                        return acc;
                      }, {} as Record<string, number>)
                    ).sort((a, b) => {
                      const order = ['Critical', 'High', 'Medium', 'Low'];
                      return order.indexOf(a[0]) - order.indexOf(b[0]);
                    }).map(([level, count]) => (
                      <div key={level} className="flex items-center justify-between">
                        <span className="text-sm">{level}</span>
                        <Badge className={cn(
                          level === 'Critical' && 'bg-red-100 text-red-800',
                          level === 'High' && 'bg-orange-100 text-orange-800',
                          level === 'Medium' && 'bg-yellow-100 text-yellow-800',
                          level === 'Low' && 'bg-green-100 text-green-800',
                        )}>
                          {count}
                        </Badge>
                      </div>
                    ))}
                  </div>
                </CardContent>
              </Card>

              {/* Trend Chart */}
              <Card>
                <CardHeader>
                  <CardTitle>New Risks (12 Months)</CardTitle>
                </CardHeader>
                <CardContent>
                  <div className="space-y-2">
                    {trend.slice(-6).map((point) => (
                      <div key={point.month} className="flex items-center gap-2">
                        <span className="text-xs text-gray-500 w-16">{point.month}</span>
                        <div className="flex-1 h-4 bg-gray-100 rounded-full overflow-hidden">
                          <div 
                            className="h-full bg-blue-500 rounded-full"
                            style={{ width: `${Math.min(100, point.new_risks * 10)}%` }}
                          />
                        </div>
                        <span className="text-xs font-medium w-6 text-right">{point.new_risks}</span>
                      </div>
                    ))}
                  </div>
                </CardContent>
              </Card>
            </div>
          </div>
      </div>
    </AppLayout>
  );
}
