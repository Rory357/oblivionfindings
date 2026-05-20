import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { Head, router, useForm } from '@inertiajs/react';
import { Pencil, Plus, Tag, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ConfirmAction } from '@/pages/sites/_confirm-action';
import { CateringTabs } from '../_tabs';
import { type DietaryTag, type TagKind, type TagSeverity, tagBadgeStyle } from '../_helpers';

type Props = {
    tags: DietaryTag[];
    kindOptions: TagKind[];
    severityOptions: TagSeverity[];
    canManage: boolean;
};

type Editing = Partial<DietaryTag> & { _isNew?: boolean };

export default function CateringTagsIndex({ tags, canManage }: Props) {
    const [editing, setEditing] = useState<Editing | null>(null);

    const form = useForm({
        key: '',
        label: '',
        kind: 'dietary' as TagKind,
        severity: 'info' as TagSeverity,
        color: '',
        description: '',
    });

    function openNew() {
        form.reset();
        form.setData({ key: '', label: '', kind: 'dietary', severity: 'info', color: '', description: '' });
        setEditing({ _isNew: true });
    }

    function openEdit(tag: DietaryTag) {
        form.setData({
            key: tag.key,
            label: tag.label,
            kind: tag.kind,
            severity: tag.severity,
            color: tag.color ?? '',
            description: tag.description ?? '',
        });
        setEditing(tag);
    }

    function submit(e: React.FormEvent) {
        e.preventDefault();
        if (editing?._isNew) {
            form.post('/catering/tags', { onSuccess: () => setEditing(null) });
        } else if (editing?.id) {
            form.put(`/catering/tags/${editing.id}`, { onSuccess: () => setEditing(null) });
        }
    }

    function destroy(tag: DietaryTag) {
        router.delete(`/catering/tags/${tag.id}`);
    }

    const grouped = tags.reduce<Record<TagKind, DietaryTag[]>>((acc, t) => {
        (acc[t.kind] ||= []).push(t);
        return acc;
    }, { dietary: [], allergen: [] });

    return (
        <AppLayout breadcrumbs={[{ title: 'Catering', href: '/catering' }, { title: 'Dietary & Allergen Tags', href: '/catering/tags' }]}>
            <Head title="Dietary & Allergen Tags" />
            <PageLayout
                hero={
                    <PageHero
                        icon={Tag}
                        title="Dietary & Allergen Tags"
                        description="Used across recipes, products and resident profiles to drive allergen warnings."
                        stats={[
                            { label: 'Total', value: tags.length },
                            { label: 'Dietary', value: grouped.dietary.length },
                            { label: 'Allergens', value: grouped.allergen.length },
                        ]}
                        actions={
                            canManage && (
                                <Button onClick={openNew}>
                                    <Plus className="mr-2 h-4 w-4" /> New tag
                                </Button>
                            )
                        }
                    />
                }
            >
                <CateringTabs active="tags" />

                {(['dietary', 'allergen'] as TagKind[]).map((kind) => (
                    <section key={kind}>
                        <h2 className="mb-2 text-lg font-medium capitalize">{kind === 'allergen' ? 'Allergens' : 'Dietary'}</h2>
                        <div className="rounded-md border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Label</TableHead>
                                        <TableHead>Key</TableHead>
                                        <TableHead>Severity</TableHead>
                                        <TableHead>Preview</TableHead>
                                        <TableHead className="w-24">Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {grouped[kind].length === 0 && (
                                        <TableRow><TableCell colSpan={5} className="text-center text-muted-foreground">No {kind} tags yet.</TableCell></TableRow>
                                    )}
                                    {grouped[kind].map((tag) => (
                                        <TableRow key={tag.id}>
                                            <TableCell className="font-medium">{tag.label}</TableCell>
                                            <TableCell className="text-xs text-muted-foreground">{tag.key}</TableCell>
                                            <TableCell><Badge variant="outline" className="capitalize">{tag.severity}</Badge></TableCell>
                                            <TableCell><Badge variant="outline" style={tagBadgeStyle(tag)}>{tag.label}</Badge></TableCell>
                                            <TableCell>
                                                {canManage && (
                                                    <div className="flex gap-1">
                                                        <Button size="icon" variant="ghost" onClick={() => openEdit(tag)}><Pencil className="h-4 w-4" /></Button>
                                                        <ConfirmAction
                                                            title={`Delete tag "${tag.label}"?`}
                                                            description="Tag is removed from all products, recipes and clients it was applied to."
                                                            confirmLabel="Delete"
                                                            onConfirm={() => destroy(tag)}
                                                        >
                                                            <Button size="icon" variant="ghost"><Trash2 className="h-4 w-4 text-destructive" /></Button>
                                                        </ConfirmAction>
                                                    </div>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </section>
                ))}

                <Dialog open={editing !== null} onOpenChange={(o) => !o && setEditing(null)}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>{editing?._isNew ? 'New tag' : `Edit ${editing?.label ?? 'tag'}`}</DialogTitle>
                            <DialogDescription>Tags propagate from products into recipes and drive resident allergen warnings.</DialogDescription>
                        </DialogHeader>
                        <form onSubmit={submit} className="space-y-3">
                            <div>
                                <Label>Label</Label>
                                <Input value={form.data.label} onChange={(e) => form.setData('label', e.target.value)} required />
                            </div>
                            <div>
                                <Label>Key (slug; leave blank to auto)</Label>
                                <Input value={form.data.key} onChange={(e) => form.setData('key', e.target.value)} />
                            </div>
                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <Label>Kind</Label>
                                    <Select value={form.data.kind} onValueChange={(v) => form.setData('kind', v as TagKind)}>
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="dietary">Dietary</SelectItem>
                                            <SelectItem value="allergen">Allergen</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <Label>Severity</Label>
                                    <Select value={form.data.severity} onValueChange={(v) => form.setData('severity', v as TagSeverity)}>
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="info">Info</SelectItem>
                                            <SelectItem value="warn">Warn</SelectItem>
                                            <SelectItem value="critical">Critical</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            <div>
                                <Label>Colour (hex, optional)</Label>
                                <Input value={form.data.color} onChange={(e) => form.setData('color', e.target.value)} placeholder="#b91c1c" />
                            </div>
                            <div>
                                <Label>Description</Label>
                                <Textarea value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} rows={2} />
                            </div>
                            <DialogFooter>
                                <Button variant="ghost" type="button" onClick={() => setEditing(null)}>Cancel</Button>
                                <Button type="submit" disabled={form.processing}>{editing?._isNew ? 'Create' : 'Save'}</Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </PageLayout>
        </AppLayout>
    );
}
