import { Head, Link } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { index as risksIndex, create as createRisk, heatmap as risksHeatmap, show as showRisk } from '@/routes/governance/risks';
import { PageHero, PageLayout } from '@/components/page';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { AlertTriangle, TrendingUp, Shield, ShieldAlert, AlertCircle } from 'lucide-react';
import { cn } from '@/lib/utils';
import { riskScoreColor, riskScoreLevel } from '@/lib/governance-status';
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

  const getRiskColor = riskScoreColor;
  const getRiskLevel = riskScoreLevel;

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

      <PageLayout
        hero={
          <PageHero
            icon={ShieldAlert}
            title="Risk Register"
            description="Track enterprise risks, residual scores, and treatments across the organisation."
            stats={[
              { label: 'Total risks', value: totalStats.total },
              { label: 'Critical', value: totalStats.critical },
              { label: 'High', value: totalStats.high },
              { label: 'Above appetite', value: totalStats.above_appetite },
            ]}
            actions={
              <div className="flex items-center gap-2">
                <Button variant="outline" asChild className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground">
                  <Link href={risksHeatmap.url()}>Risk Heatmap</Link>
                </Button>
                {auth.can?.governance?.risks?.create && (
                  <Button asChild>
                    <Link href={createRisk.url()}>New Risk</Link>
                  </Button>
                )}
              </div>
            }
          />
        }
      >

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
                      !risk.within_appetite && "border-primary bg-primary/10"
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
      </PageLayout>
    </AppLayout>
  );
}
