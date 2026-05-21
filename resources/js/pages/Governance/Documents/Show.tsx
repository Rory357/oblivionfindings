import { Head, Link } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { FolderOpen, Download, ArrowLeft, FileText } from 'lucide-react';
import { statusColors } from '@/lib/status-colors';

interface Document {
  id: number;
  title: string;
  category: string;
  description: string | null;
  file_name: string;
  file_size: number;
  mime_type: string | null;
  version: number;
  is_current: boolean;
  uploaded_by: { id: number; name: string } | null;
  created_at: string;
  updated_at: string;
}

interface Props extends PageProps {
  document: Document;
}

const formatBytes = (bytes: number): string => {
  if (bytes === 0) return '0 B';
  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return `${parseFloat((bytes / Math.pow(k, i)).toFixed(2))} ${sizes[i]}`;
};

export default function DocumentShow({ auth, document }: Props) {
  const canManage = auth.can?.governance?.documents?.manage ?? false;

  return (
    <AppLayout
      user={auth.user}
      breadcrumbs={[
        { title: 'Governance', href: '/governance/dashboard' },
        { title: 'Documents', href: '/governance/documents' },
        { title: document.title, href: `/governance/documents/${document.id}` },
      ]}
    >
      <Head title={document.title} />

      <PageLayout
        hero={
          <PageHero
            icon={FolderOpen}
            category="governance"
            title={document.title}
            description={document.description ?? 'Governance document'}
            badges={[
              { label: document.category },
              ...(document.is_current ? [{ label: 'Current' }] : [{ label: 'Archived' }]),
            ]}
            stats={[
              { label: 'Version', value: `v${document.version}` },
              { label: 'Size', value: formatBytes(document.file_size) },
            ]}
            actions={
              <div className="flex gap-2">
                <Button asChild variant="outline">
                  <Link href="/governance/documents">
                    <ArrowLeft className="mr-2 h-4 w-4" /> Back
                  </Link>
                </Button>
                <Button asChild>
                  <a href={`/governance/documents/${document.id}/download`} download>
                    <Download className="mr-2 h-4 w-4" /> Download
                  </a>
                </Button>
              </div>
            }
          />
        }
      >
        <div className="grid gap-4 lg:grid-cols-3">
          <div className="lg:col-span-2 space-y-4">
            <Card>
              <CardHeader>
                <CardTitle className="text-base flex items-center gap-2">
                  <FileText className="h-4 w-4" /> Document
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-3 text-sm">
                <div>
                  <p className="text-xs text-muted-foreground">Filename</p>
                  <p className="font-mono">{document.file_name}</p>
                </div>
                {document.description && (
                  <div>
                    <p className="text-xs text-muted-foreground">Description</p>
                    <p className="whitespace-pre-wrap">{document.description}</p>
                  </div>
                )}
                <div className="grid gap-3 sm:grid-cols-2">
                  <div>
                    <p className="text-xs text-muted-foreground">Mime type</p>
                    <p>{document.mime_type ?? '—'}</p>
                  </div>
                  <div>
                    <p className="text-xs text-muted-foreground">Status</p>
                    <Badge className={document.is_current ? statusColors.approved ?? '' : statusColors.archived ?? ''}>
                      {document.is_current ? 'Current' : 'Archived'}
                    </Badge>
                  </div>
                </div>
              </CardContent>
            </Card>
          </div>

          <div className="space-y-3">
            <Card>
              <CardHeader>
                <CardTitle className="text-base">Metadata</CardTitle>
              </CardHeader>
              <CardContent className="space-y-3 text-sm">
                <div>
                  <p className="text-xs text-muted-foreground">Uploaded by</p>
                  <p className="font-medium">{document.uploaded_by?.name ?? 'Unknown'}</p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">Created</p>
                  <p>{document.created_at}</p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">Last updated</p>
                  <p>{document.updated_at}</p>
                </div>
              </CardContent>
            </Card>

            {canManage && (
              <Card>
                <CardHeader>
                  <CardTitle className="text-base">Actions</CardTitle>
                </CardHeader>
                <CardContent>
                  <Button
                    variant="outline"
                    className="w-full"
                    onClick={() => {
                      if (confirm('Remove this document?')) {
                        const form = window.document.createElement('form');
                        form.method = 'POST';
                        form.action = `/governance/documents/${document.id}`;
                        const csrf = window.document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
                        const token = window.document.createElement('input');
                        token.type = 'hidden';
                        token.name = '_token';
                        token.value = csrf;
                        const method = window.document.createElement('input');
                        method.type = 'hidden';
                        method.name = '_method';
                        method.value = 'DELETE';
                        form.appendChild(token);
                        form.appendChild(method);
                        window.document.body.appendChild(form);
                        form.submit();
                      }
                    }}
                  >
                    Remove document
                  </Button>
                </CardContent>
              </Card>
            )}
          </div>
        </div>
      </PageLayout>
    </AppLayout>
  );
}
