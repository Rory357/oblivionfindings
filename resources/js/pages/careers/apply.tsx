import { Head, Link, useForm } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Checkbox } from '@/components/ui/checkbox';

interface Job {
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
}

interface Props {
    job: Job;
    trackingDefaults: {
        source_channel: string;
    };
}

export default function CareersApply({ job, trackingDefaults }: Props) {
    const form = useForm({
        first_name: '',
        last_name: '',
        preferred_name: '',
        personal_email: '',
        personal_phone: '',
        cover_letter: '',
        cv: null as File | null,
        privacy_consent: false,
        source_channel: trackingDefaults.source_channel || 'career_page',
        source_reference: '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.post(`/careers/jobs/${job.slug}/apply`);
    }

    return (
        <>
            <Head title={`Apply - ${job.title}`} />
            <div className="mx-auto max-w-4xl px-4 py-10 space-y-6">
                <div>
                    <Link href="/careers" className="text-sm text-muted-foreground hover:underline">Back to careers</Link>
                    <h1 className="mt-2 text-3xl font-bold">Apply for {job.title}</h1>
                    <p className="text-sm text-muted-foreground">
                        {job.position_role ? job.position_role.replace('_', ' ') : 'General role'}
                        {' · '}
                        {job.employment_type.replace('_', ' ')}
                        {job.site?.name ? ` · ${job.site.name}` : ''}
                    </p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Role Summary</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        {job.summary && <p>{job.summary}</p>}
                        {job.description && <p>{job.description}</p>}
                        {job.requirements && (
                            <div>
                                <p className="font-medium">Requirements</p>
                                <p>{job.requirements}</p>
                            </div>
                        )}
                        {job.responsibilities && (
                            <div>
                                <p className="font-medium">Responsibilities</p>
                                <p>{job.responsibilities}</p>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Your Application</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="space-y-4">
                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label>First name</Label>
                                    <Input value={form.data.first_name} onChange={(e) => form.setData('first_name', e.target.value)} />
                                    {form.errors.first_name && <p className="text-sm text-destructive">{form.errors.first_name}</p>}
                                </div>
                                <div className="space-y-2">
                                    <Label>Last name</Label>
                                    <Input value={form.data.last_name} onChange={(e) => form.setData('last_name', e.target.value)} />
                                    {form.errors.last_name && <p className="text-sm text-destructive">{form.errors.last_name}</p>}
                                </div>
                            </div>

                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label>Preferred name</Label>
                                    <Input value={form.data.preferred_name} onChange={(e) => form.setData('preferred_name', e.target.value)} />
                                </div>
                                <div className="space-y-2">
                                    <Label>Email</Label>
                                    <Input type="email" value={form.data.personal_email} onChange={(e) => form.setData('personal_email', e.target.value)} />
                                    {form.errors.personal_email && <p className="text-sm text-destructive">{form.errors.personal_email}</p>}
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label>Phone</Label>
                                <Input value={form.data.personal_phone} onChange={(e) => form.setData('personal_phone', e.target.value)} />
                            </div>

                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label>How did you hear about this role?</Label>
                                    <select
                                        className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                                        value={form.data.source_channel}
                                        onChange={(e) => form.setData('source_channel', e.target.value)}
                                    >
                                        <option value="career_page">Career page</option>
                                        <option value="linkedin">LinkedIn</option>
                                        <option value="seek">SEEK</option>
                                        <option value="indeed">Indeed</option>
                                        <option value="referral">Referral</option>
                                        <option value="agency">Agency</option>
                                        <option value="social">Social media</option>
                                        <option value="other">Other</option>
                                    </select>
                                    {form.errors.source_channel && <p className="text-sm text-destructive">{form.errors.source_channel}</p>}
                                </div>
                                <div className="space-y-2">
                                    <Label>Referral / campaign reference (optional)</Label>
                                    <Input
                                        value={form.data.source_reference}
                                        onChange={(e) => form.setData('source_reference', e.target.value)}
                                        placeholder="Name, code, or campaign"
                                    />
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label>Cover letter</Label>
                                <Textarea rows={5} value={form.data.cover_letter} onChange={(e) => form.setData('cover_letter', e.target.value)} />
                            </div>

                            <div className="space-y-2">
                                <Label>CV (optional)</Label>
                                <Input type="file" onChange={(e) => form.setData('cv', e.target.files?.[0] ?? null)} />
                                {form.errors.cv && <p className="text-sm text-destructive">{form.errors.cv}</p>}
                            </div>

                            <div className="flex items-center gap-2">
                                <Checkbox checked={form.data.privacy_consent} onCheckedChange={(checked) => form.setData('privacy_consent', Boolean(checked))} id="privacy-consent" />
                                <Label htmlFor="privacy-consent" className="font-normal">I consent to processing of my information for recruitment purposes.</Label>
                            </div>
                            {form.errors.privacy_consent && <p className="text-sm text-destructive">{form.errors.privacy_consent}</p>}

                            <Button type="submit" disabled={form.processing}>{form.processing ? 'Submitting...' : 'Submit Application'}</Button>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

