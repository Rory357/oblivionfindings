import { useEffect, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { download as downloadPack } from '@/routes/governance/packs';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import { CheckCircle, Clock, FileDown, Files, ShieldAlert, Users } from 'lucide-react';
import { cn } from '@/lib/utils';
import axios from 'axios';

interface Props extends PageProps {
  pack: {
    id: number;
    generated_at: string;
    distributed_at: string | null;
    file_size: string | null;
    watermark_text: string;
    meeting: {
      title: string;
      scheduled_at: string;
    };
  };
  is_distributed: boolean;
  manifestSections: Array<{
    id: string;
    title: string;
    type: string;
    included: boolean;
  }>;
  contentSections: Array<{
    key: string;
    title: string;
    summary: string;
    type: string;
  }>;
  distributionStats: {
    intended_recipients: number;
    read_count: number;
    download_count: number;
    outstanding_reads: number;
    read_rate: number;
    download_rate: number;
  };
}

export default function PackShow({ auth, pack, is_distributed, manifestSections, contentSections, distributionStats }: Props) {
  const [distributing, setDistributing] = useState(false);
  const canManagePack = !!auth.can?.governance?.packs?.manage;

  useEffect(() => {
    if (is_distributed) {
      void axios.post(`/governance/packs/${pack.id}/read`).catch(() => undefined);
    }
  }, [is_distributed, pack.id]);

  const distributePack = () => {
    setDistributing(true);

    router.post(`/governance/packs/${pack.id}/distribute`, {}, {
      preserveScroll: true,
      onFinish: () => setDistributing(false),
    });
  };

  return (
    <AppLayout
      user={auth.user}
      breadcrumbs={[
        { title: 'Governance', href: '/governance/dashboard' },
        { title: 'Packs', href: '/governance/meetings' },
        { title: 'Board Pack', href: `/governance/packs/${pack.id}` },
      ]}
    >
      <Head title="Board Pack" />

      <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
        <div className="mb-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
          <div className="space-y-2">
            <h1 className="text-3xl font-bold text-slate-900" dusk="pack-heading">Board Pack</h1>
            <p className="text-sm text-slate-600">{pack.meeting.title}</p>
            <p className="text-xs text-slate-500">
              Generated {new Date(pack.generated_at).toLocaleString('en-NZ', { timeZone: 'Pacific/Auckland' })}
            </p>
          </div>

          <div className="flex flex-wrap gap-2">
            {canManagePack && !is_distributed && (
              <Button variant="outline" onClick={distributePack} disabled={distributing} dusk="distribute-pack">
                <Users className="mr-2 h-4 w-4" />
                {distributing ? 'Distributing...' : 'Distribute Pack'}
              </Button>
            )}
            <Button asChild>
              <Link href={downloadPack.url({ pack: pack.id })} dusk="download-pack">
                <FileDown className="mr-2 h-4 w-4" />
                Download Pack
              </Link>
            </Button>
          </div>
        </div>

        <Card className={cn(is_distributed ? 'border-green-200 bg-green-50' : 'border-amber-200 bg-amber-50')}>
          <CardContent className="flex items-start gap-3 pt-6">
            {is_distributed ? <CheckCircle className="mt-0.5 h-6 w-6 text-green-600" /> : <Clock className="mt-0.5 h-6 w-6 text-amber-600" />}
            <div className="space-y-1">
              <p className="font-medium text-slate-900">{is_distributed ? 'Pack distributed' : 'Pack ready for distribution'}</p>
              <p className="text-sm text-slate-700">
                {is_distributed
                  ? `Distributed ${new Date(pack.distributed_at!).toLocaleDateString('en-NZ', { timeZone: 'Pacific/Auckland' })}.`
                  : 'Generate any final papers, then distribute to the board when ready.'}
              </p>
            </div>
          </CardContent>
        </Card>

        <div className="my-6 grid gap-6 lg:grid-cols-[1.2fr,1fr]">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Users className="h-5 w-5" />
                Distribution
              </CardTitle>
              <CardDescription>Real pack engagement from distribution, read, and download tracking.</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
                {[
                  ['Recipients', distributionStats.intended_recipients],
                  ['Read', distributionStats.read_count],
                  ['Downloads', distributionStats.download_count],
                  ['Outstanding', distributionStats.outstanding_reads],
                ].map(([label, value]) => (
                  <div key={label} className="rounded-lg bg-slate-50 p-4 text-center">
                    <p className="text-xs uppercase tracking-wide text-slate-500">{label}</p>
                    <p className="mt-2 text-3xl font-bold text-slate-900">{value}</p>
                  </div>
                ))}
              </div>

              <div className="space-y-3">
                <div>
                  <div className="mb-1 flex items-center justify-between text-sm text-slate-600">
                    <span>Read rate</span>
                    <span>{distributionStats.read_rate}%</span>
                  </div>
                  <Progress value={distributionStats.read_rate} />
                </div>
                <div>
                  <div className="mb-1 flex items-center justify-between text-sm text-slate-600">
                    <span>Download rate</span>
                    <span>{distributionStats.download_rate}%</span>
                  </div>
                  <Progress value={Math.min(distributionStats.download_rate, 100)} />
                </div>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Files className="h-5 w-5" />
                Manifest
              </CardTitle>
              <CardDescription>{manifestSections.length} included section(s)</CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">
              {manifestSections.map((section, index) => (
                <div key={`${section.id}-${index}`} className="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-3">
                  <div className="flex items-center gap-3">
                    <span className="text-xs text-slate-400">{index + 1}.</span>
                    <div>
                      <p className="font-medium text-slate-900">{section.title}</p>
                      <p className="text-xs text-slate-500">{section.type}</p>
                    </div>
                  </div>
                  {section.included && <CheckCircle className="h-5 w-5 text-green-600" />}
                </div>
              ))}
            </CardContent>
          </Card>
        </div>

        <Card>
          <CardHeader>
            <CardTitle>Content Sections</CardTitle>
            <CardDescription>What this pack actually contains right now.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            {contentSections.map((section) => (
              <div key={section.key} className="rounded-lg border border-slate-200 p-4">
                <div className="flex items-center justify-between gap-3">
                  <div>
                    <p className="font-medium text-slate-900">{section.title}</p>
                    <p className="text-sm text-slate-600">{section.summary}</p>
                  </div>
                  <Badge variant="outline">{section.type}</Badge>
                </div>
              </div>
            ))}
          </CardContent>
        </Card>

        <Card className="mt-6 border-red-200">
          <CardContent className="flex items-start gap-3 pt-6">
            <ShieldAlert className="mt-0.5 h-5 w-5 text-red-500" />
            <div className="space-y-1">
              <p className="font-medium text-red-900">Confidential — Board only</p>
              <p className="text-sm text-red-700">This pack is confidential governance material. Watermark: {pack.watermark_text}</p>
            </div>
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
