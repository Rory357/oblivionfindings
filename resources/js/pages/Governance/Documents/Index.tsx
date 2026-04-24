import { Head, useForm, router } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { FolderOpen, Upload, Download, Trash2, FileIcon } from 'lucide-react';
import { useState } from 'react';
import { cn } from '@/lib/utils';

interface Document {
  id: number;
  title: string;
  category: string;
  file_name: string;
  file_size: number;
  is_confidential: boolean;
  version: number;
  updated_at: string;
}

interface Props extends PageProps {
  documents: {
    data: Document[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
  };
  categories: Array<{ value: string; label: string }>;
}

export default function DocumentsIndex({ auth, documents, categories }: Props) {
  const [showUpload, setShowUpload] = useState(false);
  const { data, setData, post, processing, reset } = useForm<{
    title: string;
    category: string;
    description: string;
    file: File | null;
    is_confidential: boolean;
  }>({
    title: '',
    category: 'policy',
    description: '',
    file: null,
    is_confidential: false,
  });

  const handleUpload = (e: React.FormEvent) => {
    e.preventDefault();
    post('/governance/documents', {
      forceFormData: true,
      onSuccess: () => { reset(); setShowUpload(false); },
    });
  };

  const formatBytes = (bytes: number) => {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
  };

  const getCategoryLabel = (value: string) => categories.find(c => c.value === value)?.label ?? value;

  return (
    <AppLayout>
      <Head title="Governance Documents" />
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div className="flex items-center justify-between mb-6">
          <div>
            <h1 className="text-2xl font-bold text-foreground">Governance Documents</h1>
            <p className="text-muted-foreground mt-1">Board documents, templates, and archives</p>
          </div>
          <Button onClick={() => setShowUpload(!showUpload)}>
            <Upload className="w-4 h-4 mr-2" /> Upload Document
          </Button>
        </div>

        {showUpload && (
          <Card className="mb-6">
            <CardHeader><CardTitle>Upload Document</CardTitle></CardHeader>
            <CardContent>
              <form onSubmit={handleUpload} className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <Label>Title</Label>
                    <Input value={data.title} onChange={e => setData('title', e.target.value)} />
                  </div>
                  <div>
                    <Label>Category</Label>
                    <Select value={data.category} onValueChange={val => setData('category', val)}>
                      <SelectTrigger><SelectValue /></SelectTrigger>
                      <SelectContent>
                        {categories.map(c => (
                          <SelectItem key={c.value} value={c.value}>{c.label}</SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                </div>
                <div>
                  <Label>File</Label>
                  <Input type="file" onChange={e => setData('file', e.target.files?.[0] ?? null)} />
                </div>
                <div className="flex justify-end gap-3">
                  <Button type="button" variant="outline" onClick={() => setShowUpload(false)}>Cancel</Button>
                  <Button type="submit" disabled={processing || !data.file}>Upload</Button>
                </div>
              </form>
            </CardContent>
          </Card>
        )}

        <div className="grid gap-3">
          {documents.data.map(doc => (
            <Card key={doc.id}>
              <CardContent className="p-4 flex items-center justify-between">
                <div className="flex items-center gap-3">
                  <FileIcon className="w-5 h-5 text-blue-500" />
                  <div>
                    <div className="flex items-center gap-2">
                      <span className="font-medium">{doc.title}</span>
                      <Badge variant="outline" className="text-xs">{getCategoryLabel(doc.category)}</Badge>
                      {doc.is_confidential && <Badge className="bg-red-100 text-red-800 text-xs">Confidential</Badge>}
                    </div>
                    <div className="text-sm text-muted-foreground">
                      {doc.file_name} &middot; {formatBytes(doc.file_size)} &middot; v{doc.version} &middot; {new Date(doc.updated_at).toLocaleDateString('en-NZ')}
                    </div>
                  </div>
                </div>
                <div className="flex gap-2">
                  <a href={`/governance/documents/${doc.id}/download`}>
                    <Button variant="ghost" size="sm"><Download className="w-4 h-4" /></Button>
                  </a>
                </div>
              </CardContent>
            </Card>
          ))}
          {documents.data.length === 0 && (
            <Card><CardContent className="p-8 text-center text-muted-foreground">No documents uploaded yet.</CardContent></Card>
          )}
        </div>
      </div>
    </AppLayout>
  );
}
