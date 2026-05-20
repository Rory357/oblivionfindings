import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Head, useForm } from '@inertiajs/react';
import { FileText } from 'lucide-react';

type Props = {
    template: any | null;
};

export default function IncidentTemplateEdit({ template }: Props) {
    const isNew = !template;
    const form = useForm({
        name: template?.name ?? '',
        type: template?.type ?? '',
        severity: template?.severity ?? 'low',
        default_description: template?.default_description ?? '',
        prompts: Array.isArray(template?.prompts) ? template.prompts : [],
        checklist: Array.isArray(template?.checklist) ? template.checklist : [],
        is_active: template?.is_active ?? true,
    });

    const addPrompt = () => form.setData('prompts', [...form.data.prompts, '']);
    const addChecklist = () => form.setData('checklist', [...form.data.checklist, '']);

    return (
        <AppLayout breadcrumbs={[{ title: 'Incidents', href: '/incidents' }, { title: 'Templates', href: '/incidents/templates' }, { title: isNew ? 'New' : form.data.name || 'Edit', href: '#' }]}>
            <Head title={isNew ? 'New incident template' : `Template • ${form.data.name}`} />

            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref="/incidents/templates"
                        icon={FileText}
                        title={isNew ? 'New template' : 'Edit template'}
                        description="Used to pre-fill incident reporting"
                    />
                }
            >
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Template</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div className="space-y-1 sm:col-span-2">
                                <Label>Name</Label>
                                <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            </div>

                            <div className="flex items-center gap-2 pt-6">
                                <Checkbox checked={!!form.data.is_active} onCheckedChange={(v) => form.setData('is_active', !!v)} />
                                <Label>Active</Label>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div className="space-y-1">
                                <Label>Type (optional)</Label>
                                <Input value={form.data.type} onChange={(e) => form.setData('type', e.target.value)} />
                            </div>

                            <div className="space-y-1">
                                <Label>Severity (optional)</Label>
                                <Select value={form.data.severity || 'low'} onValueChange={(v) => form.setData('severity', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        {['low','medium','high'].map((s) => (
                                            <SelectItem key={s} value={s}>{s}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="space-y-1">
                            <Label>Default description</Label>
                            <Textarea value={form.data.default_description} onChange={(e) => form.setData('default_description', e.target.value)} />
                        </div>

                        <div className="space-y-2">
                            <div className="flex items-center justify-between">
                                <Label>Prompt fields (optional)</Label>
                                <Button type="button" size="sm" variant="outline" onClick={addPrompt}>Add</Button>
                            </div>
                            {(form.data.prompts || []).map((p: string, idx: number) => (
                                <Input
                                    key={idx}
                                    value={p}
                                    onChange={(e) => {
                                        const next = [...form.data.prompts];
                                        next[idx] = e.target.value;
                                        form.setData('prompts', next);
                                    }}
                                    placeholder={`Prompt ${idx + 1}`}
                                />
                            ))}
                        </div>

                        <div className="space-y-2">
                            <div className="flex items-center justify-between">
                                <Label>Checklist items (optional)</Label>
                                <Button type="button" size="sm" variant="outline" onClick={addChecklist}>Add</Button>
                            </div>
                            {(form.data.checklist || []).map((c: string, idx: number) => (
                                <Input
                                    key={idx}
                                    value={c}
                                    onChange={(e) => {
                                        const next = [...form.data.checklist];
                                        next[idx] = e.target.value;
                                        form.setData('checklist', next);
                                    }}
                                    placeholder={`Checklist ${idx + 1}`}
                                />
                            ))}
                        </div>

                        <div className="flex items-center justify-end">
                            <Button
                                disabled={form.processing}
                                onClick={() => {
                                    if (isNew) {
                                        form.post('/incidents/templates');
                                    } else {
                                        form.put(`/incidents/templates/${template.id}`);
                                    }
                                }}
                            >
                                Save
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
