import { Head, Link } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { download as downloadPack } from '@/routes/governance/packs';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import { FileDown, Users, CheckCircle, Clock, AlertTriangle } from 'lucide-react';
import { cn } from '@/lib/utils';

interface BoardPack {
  id: number;
  generated_at: string;
  distributed_at: string | null;
  file_size: string;
  watermark_text: string;
  document_manifest: Array<{
    id: string;
    title: string;
    type: string;
    included: boolean;
  }>;
  meeting: {
    title: string;
    scheduled_at: string;
  };
}

interface Props extends PageProps {
  pack: BoardPack;
  is_distributed: boolean;
  read_count: number;
  download_count: number;
}

export default function PackShow({ auth, pack, is_distributed, read_count, download_count }: Props) {
  const totalMembers = 5; // Would come from actual data
  const readPercentage = (read_count / totalMembers) * 100;

  return (
    <AppLayout
      user={auth.user}
      breadcrumbs={[
        { title: 'Governance', href: '/governance/dashboard' },
        { title: 'Packs', href: '/governance/packs' },
        { title: 'Board Pack', href: `/governance/packs/${pack.id}` },
      ]}
    >
      <Head title="Board Pack" />

      <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
          {/* Header */}
          <div className="flex items-start justify-between mb-6">
            <div>
              <h1 className="text-3xl font-bold text-gray-900">Board Pack</h1>
              <p className="text-gray-500 mt-1">{pack.meeting.title}</p>
              <p className="text-sm text-gray-400">
                Generated {new Date(pack.generated_at).toLocaleString()}
              </p>
            </div>
            <div className="flex gap-2">
              <Button asChild>
                <Link href={downloadPack.url({ pack: pack.id })}>
                  <FileDown className="w-4 h-4 mr-2" />
                  Download PDF
                </Link>
              </Button>
            </div>
          </div>

          {/* Status Alert */}
          <Card className={cn(
            "mb-6",
            is_distributed ? "bg-green-50 border-green-200" : "bg-yellow-50 border-yellow-200"
          )}>
            <CardContent className="pt-6">
              <div className="flex items-center gap-3">
                {is_distributed ? (
                  <>
                    <CheckCircle className="w-6 h-6 text-green-600" />
                    <div>
                      <p className="font-medium text-green-900">Pack Distributed</p>
                      <p className="text-sm text-green-700">
                        Distributed on {new Date(pack.distributed_at!).toLocaleDateString()}
                      </p>
                    </div>
                  </>
                ) : (
                  <>
                    <Clock className="w-6 h-6 text-yellow-600" />
                    <div>
                      <p className="font-medium text-yellow-900">Not Yet Distributed</p>
                      <p className="text-sm text-yellow-700">
                        This pack has been generated but not yet distributed to board members.
                      </p>
                    </div>
                  </>
                )}
              </div>
            </CardContent>
          </Card>

          {/* Read Stats */}
          {is_distributed && (
            <Card className="mb-6">
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Users className="w-5 h-5" />
                  Distribution Stats
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="grid grid-cols-2 gap-4 mb-4">
                  <div className="text-center p-4 bg-gray-50 rounded-lg">
                    <p className="text-3xl font-bold">{read_count}</p>
                    <p className="text-sm text-gray-500">Marked as Read</p>
                  </div>
                  <div className="text-center p-4 bg-gray-50 rounded-lg">
                    <p className="text-3xl font-bold">{download_count}</p>
                    <p className="text-sm text-gray-500">Downloads</p>
                  </div>
                </div>
                <div className="space-y-2">
                  <div className="flex justify-between text-sm">
                    <span>Read Rate</span>
                    <span>{Math.round(readPercentage)}%</span>
                  </div>
                  <Progress value={readPercentage} />
                </div>
              </CardContent>
            </Card>
          )}

          {/* Document Manifest */}
          <Card>
            <CardHeader>
              <CardTitle>Pack Contents</CardTitle>
              <CardDescription>{pack.document_manifest.length} sections included</CardDescription>
            </CardHeader>
            <CardContent>
              <div className="space-y-2">
                {pack.document_manifest.map((doc, index) => (
                  <div
                    key={index}
                    className="flex items-center justify-between p-3 rounded-lg border"
                  >
                    <div className="flex items-center gap-3">
                      <span className="text-gray-400 w-6">{index + 1}.</span>
                      <span className="font-medium">{doc.title}</span>
                      <Badge variant="outline">{doc.type}</Badge>
                    </div>
                    {doc.included && (
                      <CheckCircle className="w-5 h-5 text-green-500" />
                    )}
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>

          {/* Confidentiality Notice */}
          <Card className="mt-6 border-red-200">
            <CardContent className="pt-6">
              <div className="flex items-start gap-3">
                <AlertTriangle className="w-5 h-5 text-red-500 mt-0.5" />
                <div>
                  <p className="font-medium text-red-900">Confidential — Board Only</p>
                  <p className="text-sm text-red-700 mt-1">
                    This document contains confidential information intended solely for board members. 
                    Unauthorized distribution is strictly prohibited. Watermark: {pack.watermark_text}
                  </p>
                </div>
              </div>
            </CardContent>
          </Card>
      </div>
    </AppLayout>
  );
}
