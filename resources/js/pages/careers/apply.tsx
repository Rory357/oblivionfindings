import { Head, useForm, usePage } from '@inertiajs/react';
import { PageHero, PageLayout } from '@/components/page';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Checkbox } from '@/components/ui/checkbox';
import { Badge } from '@/components/ui/badge';
import { Briefcase, FileText, MapPin } from 'lucide-react';
import { employmentTypeLabels, sourceChannelOptions } from '@/lib/job-posting-constants';

type Props = {
    job: {
        id: number;
        title: string;
        slug: string;
        position_role: string | null;
        employment_type: string;
        summary: string | null;
        description: string | null;
        requirements: string | null;
        responsibilities: string | null;
        site: { id: number; name: string } | null;
        closing_at: string | null;
    };
    trackingDefaults: {
        source_channel: string;
    };
};

export default function CareersApply({ job, trackingDefaults }: Props) {
    const { flash } = usePage().props as { flash?: { success?: string } };

    const form = useForm({
        first_name: '',
        last_name: '',
        preferred_name: '',
        personal_email: '',
        personal_phone: '',
        cover_letter: '',
        cv: null as File | null,
        privacy_consent: false,
        source_channel: trackingDefaults?.source_channel ?? 'career_page',
        source_reference: '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.post(`/careers/jobs/${job.slug}/apply`, { forceFormData: true });
    }

    return (
        <>
            <Head title={`Apply - ${job.title}`} />
            <div className="mx-auto max-w-3xl px-4 py-10">
                <PageLayout
                    padding="none"
                    hero={
                        <PageHero
                            variant="compact"
                            backHref="/careers"
                            backLabel="Back to careers"
                            icon={FileText}
                            title={`Apply for ${job.title}`}
                        >
                            <div className="flex flex-wrap gap-2 text-sm text-muted-foreground">
                                <span className="flex items-center gap-1"><Briefcase className="h-3.5 w-3.5" /> {employmentTypeLabels[job.employment_type] || job.employment_type}</span>
                                {job.position_role && <Badge variant="outline" className="text-xs">{job.position_role.replace(/_/g, ' ')}</Badge>}
                                {job.site && <span className="flex items-center gap-1"><MapPin className="h-3.5 w-3.5" /> {job.site.name}</span>}
                            </div>
                        </PageHero>
                    }
                >
                {flash?.success && (
                    <div className="rounded-lg border border-status-success/30 bg-status-success-bg p-4 text-sm text-status-success">
                        {flash.success}
                    </div>
                )}

                {job.summary && (
                    <p className="text-muted-foreground italic border-l-2 border-primary/30 pl-4">{job.summary}</p>
                )}

                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Your details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 md:grid-cols-2">
                                <div>
                                    <Label>First name *</Label>
                                    <Input value={form.data.first_name} onChange={e => form.setData('first_name', e.target.value)} className="mt-1" />
                                    {form.errors.first_name && <p className="text-sm text-destructive mt-1">{form.errors.first_name}</p>}
                                </div>
                                <div>
                                    <Label>Last name *</Label>
                                    <Input value={form.data.last_name} onChange={e => form.setData('last_name', e.target.value)} className="mt-1" />
                                    {form.errors.last_name && <p className="text-sm text-destructive mt-1">{form.errors.last_name}</p>}
                                </div>
                            </div>
                            <div>
                                <Label>Preferred name</Label>
                                <Input value={form.data.preferred_name} onChange={e => form.setData('preferred_name', e.target.value)} className="mt-1" />
                            </div>
                            <div className="grid gap-4 md:grid-cols-2">
                                <div>
                                    <Label>Email *</Label>
                                    <Input type="email" value={form.data.personal_email} onChange={e => form.setData('personal_email', e.target.value)} className="mt-1" />
                                    {form.errors.personal_email && <p className="text-sm text-destructive mt-1">{form.errors.personal_email}</p>}
                                </div>
                                <div>
                                    <Label>Phone</Label>
                                    <Input value={form.data.personal_phone} onChange={e => form.setData('personal_phone', e.target.value)} className="mt-1" />
                                </div>
                            </div>
                            <div>
                                <Label>How did you hear about this role?</Label>
                                <select
                                    className="mt-1 h-10 w-full rounded-md border bg-background px-3 text-sm"
                                    value={form.data.source_channel}
                                    onChange={e => form.setData('source_channel', e.target.value)}
                                >
                                    {sourceChannelOptions.map(opt => (
                                        <option key={opt.value} value={opt.value}>{opt.label}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <Label>Reference (optional)</Label>
                                <Input
                                    value={form.data.source_reference}
                                    onChange={e => form.setData('source_reference', e.target.value)}
                                    placeholder="e.g. campaign code or referral name"
                                    className="mt-1"
                                />
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Application</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <Label>Cover letter</Label>
                                <Textarea
                                    rows={6}
                                    value={form.data.cover_letter}
                                    onChange={e => form.setData('cover_letter', e.target.value)}
                                    className="mt-1"
                                    placeholder="Tell us why you're interested in this role..."
                                />
                            </div>
                            <div>
                                <Label>CV / Resume</Label>
                                <Input
                                    type="file"
                                    accept=".pdf,.doc,.docx"
                                    onChange={e => form.setData('cv', e.target.files?.[0] ?? null)}
                                    className="mt-1"
                                />
                                <p className="text-xs text-muted-foreground mt-1">PDF, DOC, or DOCX (max 10MB)</p>
                                {form.errors.cv && <p className="text-sm text-destructive mt-1">{form.errors.cv}</p>}
                            </div>

                            <div className="flex items-start gap-2 pt-2">
                                <Checkbox
                                    checked={form.data.privacy_consent}
                                    onCheckedChange={checked => form.setData('privacy_consent', Boolean(checked))}
                                    id="privacy-consent"
                                />
                                <Label htmlFor="privacy-consent" className="font-normal text-sm leading-5">
                                    I consent to the collection and processing of my personal information for recruitment purposes in accordance with the Privacy Act 2020.
                                </Label>
                            </div>
                            {form.errors.privacy_consent && <p className="text-sm text-destructive">{form.errors.privacy_consent}</p>}
                        </CardContent>
                    </Card>

                    <div className="flex justify-end">
                        <Button type="submit" size="lg" disabled={form.processing}>
                            {form.processing ? 'Submitting...' : 'Submit Application'}
                        </Button>
                    </div>
                </form>
                </PageLayout>
            </div>
        </>
    );
}
