import { Head, Link } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { index as risksIndex, create as createRisk, heatmap as risksHeatmap, show as showRisk } from '@/routes/governance/risks';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { AlertTriangle, TrendingUp, Shield, AlertCircle } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useState } from 'react';

interface Risk {
  id: number;
  risk_reference: string;
  title: string;
  category: string;
  residual_score: number;
  status: string;
  within_appetite: boolean;
  risk_owner: { name: string };
  treatments_count: number;
}

interface Props extends PageProps {
  risks: {
    data: Risk[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
  };
  categories: Array<{ value: string; label: string }>;
  summary: Record<string, { total: number; critical: number; high: number; above_appetite: number }>;
  filters: {
    category?: string;
    status?: string;
    severity?: string;
  };
}

export default function RiskIndex({ auth, risks, categories, summary, filters }: Props) {
  const [searchQuery, setSearchQuery] = useState('');

  const getRiskColor = (score: number) => {
    if (score >= 20) return 'bg-status-critical';
    if (score >= 15) return 'bg-status-warning';
    if (score >= 10) return 'bg-status-warning';
    return 'bg-status-success';
  };

  const getRiskLevel = (score: number) => {
    if (score >= 20) return 'Critical';
    if (score >= 15) return 'High';
    if (score >= 10) return 'Medium';
    return 'Low';
  };

  const totalStats = Object.values(summary).reduce((acc, cat) => ({
    total: acc.total + cat.total,
    critical: acc.critical + cat.critical,
    high: acc.high + cat.high,
    above_appetite: acc.above_appetite + cat.above_appetite,
  }), { total: 0, critical: 0, high: 0, above_appetite: 0 });

  return (
    <AppLayout
      user={auth.user}
      breadcrumbs={[
        { title: 'Governance', href: '/governance/dashboard' },
        { title: 'Risks', href: '/governance/risks' },
      ]}
    >
      <Head title="Risk Register" />

      <div className="flex flex-col gap-6 p-4 md:p-6">
          {/* Header */}
          <div className="flex items-center justify-between mb-6">
            <div>
              <h1 className="text-3xl font-bold text-foreground">Risk Register</h1>
              <p className="text-muted-foreground mt-1">Enterprise risk management</p>
            </div>
            <div className="flex gap-2">
              <Button variant="outline" asChild>
                <Link href={risksHeatmap.url()}>Risk Heatmap</Link>
              </Button>
              {(auth.can as any)?.governance?.risks?.create && (
                <Button asChild>
                  <Link href={createRisk.url()}>New Risk</Link>
                </Button>
              )}
            </div>
          </div>

          {/* Summary Cards */}
          <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <Card>
              <CardContent className="pt-6">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm text-muted-foreground">Total Risks</p>
                    <p className="text-3xl font-bold">{totalStats.total}</p>
                  </div>
                  <Shield className="w-8 h-8 text-muted-foreground" />
                </div>
              </CardContent>
            </Card>
            <Card className="border-status-critical/30">
              <CardContent className="pt-6">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm text-status-critical">Critical</p>
                    <p className="text-3xl font-bold text-status-critical">{totalStats.critical}</p>
                  </div>
                  <AlertTriangle className="w-8 h-8 text-status-critical" />
                </div>
              </CardContent>
            </Card>
            <Card className="border-status-warning/30">
              <CardContent className="pt-6">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm text-status-warning">High</p>
                    <p className="text-3xl font-bold text-status-warning">{totalStats.high}</p>
                  </div>
                  <AlertCircle className="w-8 h-8 text-status-warning" />
                </div>
              </CardContent>
            </Card>
            <Card className="border-primary">
              <CardContent className="pt-6">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm text-primary">Above Appetite</p>
                    <p className="text-3xl font-bold text-primary">{totalStats.above_appetite}</p>
                  </div>
                  <TrendingUp className="w-8 h-8 text-primary" />
                </div>
              </CardContent>
            </Card>
          </div>

          {/* Filters */}
          <Card className="mb-6">
            <CardContent className="pt-6">
              <div className="flex gap-4">
                <Input
                  placeholder="Search risks..."
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  className="max-w-sm"
                />
                <Select defaultValue={filters.category || 'all'}>
                  <SelectTrigger className="w-48">
                    <SelectValue placeholder="Category" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All Categories</SelectItem>
                    {categories.map((cat) => (
                      <SelectItem key={cat.value} value={cat.value}>{cat.label}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <Select defaultValue={filters.status || 'all'}>
                  <SelectTrigger className="w-40">
                    <SelectValue placeholder="Status" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All Status</SelectItem>
                    <SelectItem value="active">Active</SelectItem>
                    <SelectItem value="mitigating">Mitigating</SelectItem>
                    <SelectItem value="accepted">Accepted</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </CardContent>
          </Card>

          {/* Risk List */}
          <Card>
            <CardHeader>
              <CardTitle>Active Risks</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-2">
                {risks.data.map((risk) => (
                  <div
                    key={risk.id}
                    className={cn(
                      "flex items-center justify-between p-4 rounded-lg border hover:bg-muted transition-colors",
                      !risk.within_appetite && "border-primary bg-primary/10/50"
                    )}
                  >
                    <div className="flex items-center gap-4">
                      <div className={cn("w-12 h-12 rounded-full flex items-center justify-center text-white font-bold", getRiskColor(risk.residual_score))}>
                        {risk.residual_score}
                      </div>
                      <div>
                        <div className="flex items-center gap-2">
                          <Link 
                            href={showRisk.url({ risk: risk.id })}
                            className="font-semibold text-foreground hover:text-status-info"
                          >
                            {risk.title}
                          </Link>
                          <Badge variant="outline">{risk.risk_reference}</Badge>
                          {!risk.within_appetite && (
                            <Badge className="bg-primary/10 text-primary">Above Appetite</Badge>
                          )}
                        </div>
                        <div className="flex items-center gap-4 mt-1 text-sm text-muted-foreground">
                          <span>{categories.find(c => c.value === risk.category)?.label}</span>
                          <span>•</span>
                          <span>Owner: {risk.risk_owner.name}</span>
                          {risk.treatments_count > 0 && (
                            <>
                              <span>•</span>
                              <span>{risk.treatments_count} treatment{risk.treatments_count > 1 ? 's' : ''}</span>
                            </>
                          )}
                        </div>
                      </div>
                    </div>
                    <div className="flex items-center gap-4">
                      <Badge className={cn(getRiskColor(risk.residual_score), 'text-white')}>
                        {getRiskLevel(risk.residual_score)}
                      </Badge>
                      <Button variant="ghost" size="sm" asChild>
                        <Link href={showRisk.url({ risk: risk.id })}>View →</Link>
                      </Button>
                    </div>
                  </div>
                ))}
              </div>

              {/* Pagination */}
              {risks.links.length > 3 && (
                <div className="flex justify-center gap-2 mt-6">
                  {risks.links.map((link, i) => (
                    <Button
                      key={i}
                      variant={link.active ? 'default' : 'outline'}
                      size="sm"
                      disabled={!link.url}
                      asChild={!!link.url}
                    >
                      {link.url ? (
                        <Link href={link.url} dangerouslySetInnerHTML={{ __html: link.label }} />
                      ) : (
                        <span dangerouslySetInnerHTML={{ __html: link.label }} />
                      )}
                    </Button>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>
      </div>
    </AppLayout>
  );
}
