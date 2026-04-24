import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Checkbox } from '@/components/ui/checkbox';
import { ArrowLeft, ClipboardCheck } from 'lucide-react';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export default function CreateTemplate() {
    const form = useForm({
        key: '',
        name: '',
        description: '',
        applicable_to_type: 'all',
        frequency: 'weekly',
        is_active: true,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/sites/checklists/templates');
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Sites', href: '/sites' }, { title: 'Checklist Templates', href: '/sites/checklists/templates' }, { title: 'Create', href: '#' }]}>
            <Head title="Create Checklist Template" />

            <div className="m-4 max-w-2xl mx-auto">
                <Button asChild variant="ghost" size="sm" className="mb-4">
                    <Link href="/sites/checklists/templates">
                        <ArrowLeft className="w-4 h-4 mr-1" />
                        Back to Templates
                    </Link>
                </Button>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <ClipboardCheck className="w-5 h-5" />
                            Create New Checklist Template
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleSubmit} className="space-y-6">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label htmlFor="key">Template Key *</Label>
                                    <Input
                                        id="key"
                                        value={form.data.key}
                                        onChange={(e) => form.setData('key', e.target.value)}
                                        placeholder="e.g., daily_safety_check"
                                        required
                                    />
                                    <p className="text-xs text-muted-foreground mt-1">Unique identifier, no spaces</p>
                                    {form.errors.key && <p className="text-sm text-status-critical mt-1">{form.errors.key}</p>}
                                </div>
                                <div>
                                    <Label htmlFor="name">Template Name *</Label>
                                    <Input
                                        id="name"
                                        value={form.data.name}
                                        onChange={(e) => form.setData('name', e.target.value)}
                                        placeholder="e.g., Daily Safety Check"
                                        required
                                    />
                                    {form.errors.name && <p className="text-sm text-status-critical mt-1">{form.errors.name}</p>}
                                </div>
                            </div>

                            <div>
                                <Label htmlFor="description">Description</Label>
                                <Textarea
                                    id="description"
                                    value={form.data.description}
                                    onChange={(e) => form.setData('description', e.target.value)}
                                    placeholder="What this checklist is for and when it should be used"
                                    rows={3}
                                />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>Applies To</Label>
                                    <Select
                                        value={form.data.applicable_to_type}
                                        onValueChange={(v) => form.setData('applicable_to_type', v)}
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">All Site Types</SelectItem>
                                            <SelectItem value="house">Houses Only</SelectItem>
                                            <SelectItem value="head_office">Head Offices Only</SelectItem>
                                            <SelectItem value="facility">Facilities Only</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <Label>Default Frequency</Label>
                                    <Select
                                        value={form.data.frequency}
                                        onValueChange={(v) => form.setData('frequency', v)}
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="once">One-time</SelectItem>
                                            <SelectItem value="daily">Daily</SelectItem>
                                            <SelectItem value="weekly">Weekly</SelectItem>
                                            <SelectItem value="fortnightly">Fortnightly</SelectItem>
                                            <SelectItem value="monthly">Monthly</SelectItem>
                                            <SelectItem value="quarterly">Quarterly</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="is_active"
                                    checked={form.data.is_active}
                                    onCheckedChange={(checked) => form.setData('is_active', checked as boolean)}
                                />
                                <Label htmlFor="is_active" className="font-normal cursor-pointer">
                                    Template is active and available for assignment
                                </Label>
                            </div>

                            <div className="flex justify-end gap-3 pt-4 border-t">
                                <Button asChild variant="outline">
                                    <Link href="/sites/checklists/templates">Cancel</Link>
                                </Button>
                                <Button type="submit" disabled={form.processing}>
                                    Create Template
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
