import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import { type BreadcrumbItem } from '@/types';

type EventDto = {
  id: number;
  type: string;
  occurred_at: string;
  subject?: string | null;
  body?: string | null;
  actor?: { id: number; name: string } | null;
  client?: { id: number; first_name: string; last_name: string } | null;
  site?: { id: number; name: string } | null;
  meta?: any;
};

type Props = {
  scope: { type: 'staff' | 'client' | 'site'; id: number; name: string };
  range: { from: string; to: string };
  events: EventDto[];
};

export default function TimelineIndex(props: Props) {
  const { auth } = usePage().props as any;
  const canCreate = !!auth?.can?.timeline?.create;

  const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Timeline', href: '/timeline' },
  ];

  const noteForm = useForm<{ body: string }>({ body: '' });
  const submitNote = () => {
    if (props.scope.type !== 'client') return;
    noteForm.post(`/clients/${props.scope.id}/notes`, {
      preserveScroll: true,
      onSuccess: () => noteForm.reset('body'),
    });
  };

  return (
    <AppLayout breadcrumbs={breadcrumbs}>
      <Head title="Timeline" />

      <div className="space-y-4 p-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div>
            <div className="text-sm font-semibold">{props.scope.name}</div>
            <div className="text-xs text-muted-foreground">
              {new Date(props.range.from).toLocaleDateString()} → {new Date(props.range.to).toLocaleDateString()}
            </div>
          </div>

          <div className="flex flex-wrap gap-2">
            {props.scope.type === 'staff' ? (
              <Button asChild size="sm" variant="outline">
                <Link href={`/summaries/staff/${props.scope.id}`}>View summary</Link>
              </Button>
            ) : null}
            {props.scope.type === 'client' ? (
              <>
                <Button asChild size="sm" variant="outline">
                  <Link href={`/clients/${props.scope.id}`}>Open client</Link>
                </Button>
                <Button asChild size="sm" variant="outline">
                  <Link href={`/summaries/clients/${props.scope.id}`}>View summary</Link>
                </Button>
              </>
            ) : null}
          </div>
        </div>

        {props.scope.type === 'client' && canCreate ? (
          <Card className="rounded-2xl">
            <CardHeader>
              <CardTitle className="text-base">Add a note</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
              <Textarea
                value={noteForm.data.body}
                onChange={(e) => noteForm.setData('body', e.target.value)}
                placeholder="Write a quick note for this client…"
                rows={4}
              />
              <div className="flex items-center gap-2">
                <Button size="sm" onClick={submitNote} disabled={noteForm.processing || !noteForm.data.body.trim()}>
                  Add note
                </Button>
                {noteForm.errors.body ? (
                  <span className="text-xs text-destructive">{noteForm.errors.body}</span>
                ) : null}
              </div>
            </CardContent>
          </Card>
        ) : null}

        <Card className="rounded-2xl">
          <CardHeader>
            <CardTitle className="text-base">Activity</CardTitle>
          </CardHeader>
          <CardContent>
            {props.events.length ? (
              <div className="space-y-3">
                {props.events.map((e) => (
                  <div key={e.id} className="rounded-xl border p-3">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                      <div className="min-w-0">
                        <div className="text-sm font-medium">
                          {e.subject ?? e.type}
                        </div>
                        <div className="mt-1 text-xs text-muted-foreground">
                          {new Date(e.occurred_at).toLocaleString()}
                          {e.actor ? ` • ${e.actor.name}` : ''}
                          {e.client ? ` • ${e.client.first_name} ${e.client.last_name}` : ''}
                          {e.site ? ` • ${e.site.name}` : ''}
                        </div>
                      </div>
                      <div className="text-xs text-muted-foreground">{e.type}</div>
                    </div>
                    {e.body ? (
                      <div className="mt-2 whitespace-pre-wrap text-sm text-slate-700 dark:text-slate-200">
                        {e.body}
                      </div>
                    ) : null}
                  </div>
                ))}
              </div>
            ) : (
              <div className="text-sm text-muted-foreground">No timeline activity in this range.</div>
            )}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
