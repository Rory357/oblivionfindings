import { Head, useForm } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Settings as SettingsIcon, Save } from 'lucide-react';

interface SettingDefinition {
  key: string;
  label: string;
  category: string;
  type: 'number' | 'text' | 'json';
  default: string | number | null;
  description: string;
  value: string | number | null;
}

interface Props extends PageProps {
  settings: SettingDefinition[];
  categories: Record<string, string>;
}

export default function GovernanceSettingsIndex({ auth, settings, categories }: Props) {
  const initialValues: Record<string, string> = {};
  settings.forEach((s) => {
    initialValues[s.key] = s.value !== null && s.value !== undefined ? String(s.value) : '';
  });

  const form = useForm<{ settings: Record<string, string> }>({ settings: initialValues });

  const grouped = Object.entries(categories).map(([key, label]) => ({
    key,
    label,
    settings: settings.filter((s) => s.category === key),
  }));

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    form.put('/governance/settings');
  };

  return (
    <AppLayout
      user={auth.user}
      breadcrumbs={[
        { title: 'Governance', href: '/governance/dashboard' },
        { title: 'Settings', href: '/governance/settings' },
      ]}
    >
      <Head title="Governance Settings" />

      <PageLayout
        hero={
          <PageHero
            icon={SettingsIcon}
            category="governance"
            title="Governance Settings"
            description="Configure escalation paths, spend approval thresholds, and variance alert rules."
          />
        }
      >
        <form onSubmit={submit} className="space-y-6">
          {grouped.map((group) =>
            group.settings.length === 0 ? null : (
              <Card key={group.key}>
                <CardHeader>
                  <CardTitle>{group.label}</CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                  {group.settings.map((s) => (
                    <div key={s.key} className="grid gap-2 lg:grid-cols-[1fr,2fr] lg:items-start">
                      <div>
                        <Label htmlFor={s.key}>{s.label}</Label>
                        <p className="mt-1 text-xs text-muted-foreground">{s.description}</p>
                      </div>
                      <div>
                        <Input
                          id={s.key}
                          type={s.type === 'number' ? 'number' : 'text'}
                          value={form.data.settings[s.key] ?? ''}
                          onChange={(e) =>
                            form.setData('settings', {
                              ...form.data.settings,
                              [s.key]: e.target.value,
                            })
                          }
                        />
                        {form.errors[`settings.${s.key}` as never] && (
                          <p className="mt-1 text-xs text-status-critical">
                            {String(form.errors[`settings.${s.key}` as never])}
                          </p>
                        )}
                        <p className="mt-1 text-xs text-muted-foreground">
                          Key: <code className="font-mono">{s.key}</code> · Default:{' '}
                          {String(s.default ?? '—')}
                        </p>
                      </div>
                    </div>
                  ))}
                </CardContent>
              </Card>
            )
          )}

          <div className="flex items-center justify-end gap-2">
            <Button type="submit" disabled={form.processing}>
              <Save className="mr-2 h-4 w-4" />
              {form.processing ? 'Saving…' : 'Save settings'}
            </Button>
          </div>
        </form>
      </PageLayout>
    </AppLayout>
  );
}
