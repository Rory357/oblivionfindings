import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Head, useForm } from '@inertiajs/react';

export default function SubstanceCreate() {
    const form = useForm({
        name: '',
        common_name: '',
        un_number: '',
        hsno_approval: '',
        hsno_classification: '',
        signal_word: '',
        hazard_statements: '',
        precautionary_statements: '',
        physical_form: 'liquid',
        first_aid_measures: '',
        firefighting_measures: '',
        spill_procedures: '',
        handling_precautions: '',
        storage_requirements: '',
        ppe_required: '',
        exposure_limit_type: '',
        exposure_limit_value: '',
        requires_tracking: false,
        is_controlled_substance: false,
    });

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Health & Safety', href: '/health-safety' },
                { title: 'Chemical Register', href: '/health-safety/substances' },
                { title: 'Add Substance', href: '/health-safety/substances/create' },
            ]}
        >
            <Head title="Add Substance" />

            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref="/health-safety/substances"
                        title="Add Substance"
                        description="Register a new hazardous substance in the chemical register"
                    />
                }
            >
                {/* Basic Info */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Basic Information</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div className="space-y-1">
                                <Label>Substance Name</Label>
                                <Input
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                />
                                {form.errors.name && <p className="text-xs text-status-critical">{form.errors.name}</p>}
                            </div>
                            <div className="space-y-1">
                                <Label>Common Name</Label>
                                <Input
                                    value={form.data.common_name}
                                    onChange={(e) => form.setData('common_name', e.target.value)}
                                />
                            </div>
                        </div>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div className="space-y-1">
                                <Label>UN Number</Label>
                                <Input
                                    value={form.data.un_number}
                                    onChange={(e) => form.setData('un_number', e.target.value)}
                                    placeholder="e.g. UN1203"
                                />
                            </div>
                            <div className="space-y-1">
                                <Label>HSNO Approval</Label>
                                <Input
                                    value={form.data.hsno_approval}
                                    onChange={(e) => form.setData('hsno_approval', e.target.value)}
                                    placeholder="e.g. HSR001234"
                                />
                            </div>
                            <div className="space-y-1">
                                <Label>HSNO Classification</Label>
                                <Input
                                    value={form.data.hsno_classification}
                                    onChange={(e) => form.setData('hsno_classification', e.target.value)}
                                />
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Hazard Info */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Hazard Information</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div className="space-y-1">
                                <Label>Signal Word</Label>
                                <Select
                                    value={form.data.signal_word || '__none__'}
                                    onValueChange={(v) => form.setData('signal_word', v === '__none__' ? '' : v)}
                                >
                                    <SelectTrigger><SelectValue placeholder="Select" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__none__">None</SelectItem>
                                        <SelectItem value="Danger">Danger</SelectItem>
                                        <SelectItem value="Warning">Warning</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1">
                                <Label>Physical Form</Label>
                                <Select
                                    value={form.data.physical_form}
                                    onValueChange={(v) => form.setData('physical_form', v)}
                                >
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="solid">Solid</SelectItem>
                                        <SelectItem value="liquid">Liquid</SelectItem>
                                        <SelectItem value="gas">Gas</SelectItem>
                                        <SelectItem value="aerosol">Aerosol</SelectItem>
                                        <SelectItem value="powder">Powder</SelectItem>
                                        <SelectItem value="paste">Paste</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="space-y-1">
                            <Label>Hazard Statements</Label>
                            <Textarea
                                value={form.data.hazard_statements}
                                onChange={(e) => form.setData('hazard_statements', e.target.value)}
                                placeholder="One per line"
                            />
                        </div>
                        <div className="space-y-1">
                            <Label>Precautionary Statements</Label>
                            <Textarea
                                value={form.data.precautionary_statements}
                                onChange={(e) => form.setData('precautionary_statements', e.target.value)}
                                placeholder="One per line"
                            />
                        </div>
                    </CardContent>
                </Card>

                {/* Safety Info */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Safety Information</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="space-y-1">
                            <Label>First Aid Measures</Label>
                            <Textarea
                                value={form.data.first_aid_measures}
                                onChange={(e) => form.setData('first_aid_measures', e.target.value)}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label>Firefighting Measures</Label>
                            <Textarea
                                value={form.data.firefighting_measures}
                                onChange={(e) => form.setData('firefighting_measures', e.target.value)}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label>Spill Procedures</Label>
                            <Textarea
                                value={form.data.spill_procedures}
                                onChange={(e) => form.setData('spill_procedures', e.target.value)}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label>Handling Precautions</Label>
                            <Textarea
                                value={form.data.handling_precautions}
                                onChange={(e) => form.setData('handling_precautions', e.target.value)}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label>Storage Requirements</Label>
                            <Textarea
                                value={form.data.storage_requirements}
                                onChange={(e) => form.setData('storage_requirements', e.target.value)}
                            />
                        </div>
                    </CardContent>
                </Card>

                {/* PPE & Exposure */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">PPE & Exposure Limits</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="space-y-1">
                            <Label>PPE Required</Label>
                            <Textarea
                                value={form.data.ppe_required}
                                onChange={(e) => form.setData('ppe_required', e.target.value)}
                                placeholder="e.g. Safety goggles, chemical-resistant gloves, lab coat"
                            />
                        </div>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div className="space-y-1">
                                <Label>Exposure Limit Type</Label>
                                <Select
                                    value={form.data.exposure_limit_type || '__none__'}
                                    onValueChange={(v) => form.setData('exposure_limit_type', v === '__none__' ? '' : v)}
                                >
                                    <SelectTrigger><SelectValue placeholder="Select" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__none__">None</SelectItem>
                                        <SelectItem value="WES-TWA">WES-TWA</SelectItem>
                                        <SelectItem value="WES-STEL">WES-STEL</SelectItem>
                                        <SelectItem value="WES-Ceiling">WES-Ceiling</SelectItem>
                                        <SelectItem value="BEI">BEI</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1">
                                <Label>Exposure Limit Value</Label>
                                <Input
                                    value={form.data.exposure_limit_value}
                                    onChange={(e) => form.setData('exposure_limit_value', e.target.value)}
                                    placeholder="e.g. 50 ppm"
                                />
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Flags */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Tracking & Control</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="flex items-center gap-2">
                            <Checkbox
                                checked={!!form.data.requires_tracking}
                                onCheckedChange={(v) => form.setData('requires_tracking', !!v)}
                            />
                            <Label>Requires quantity tracking</Label>
                        </div>
                        <div className="flex items-center gap-2">
                            <Checkbox
                                checked={!!form.data.is_controlled_substance}
                                onCheckedChange={(v) => form.setData('is_controlled_substance', !!v)}
                            />
                            <Label>Is a controlled substance</Label>
                        </div>
                    </CardContent>
                </Card>

                {/* Errors */}
                {Object.keys(form.errors).length > 0 && (
                    <div className="rounded-md border border-status-critical/30 bg-status-critical-bg p-3 text-sm text-status-critical">
                        <p className="font-medium">Please fix the following errors:</p>
                        <ul className="mt-1 list-disc pl-5">
                            {Object.entries(form.errors).map(([field, message]) => (
                                <li key={field}>{message}</li>
                            ))}
                        </ul>
                    </div>
                )}

                {/* Submit */}
                <div className="flex items-center justify-end">
                    <Button
                        disabled={form.processing}
                        onClick={() => form.post('/health-safety/substances', { preserveScroll: true })}
                    >
                        Add Substance
                    </Button>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
