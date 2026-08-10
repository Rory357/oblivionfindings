import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import VoiceInputButton from '@/components/voice-input-button';
import RespiteSubnav from '@/components/respite-subnav';
import DraftSavedIndicator from '@/components/draft-saved-indicator';
import DraftResumePrompt from '@/components/draft-resume-prompt';
import { useFormAutosave } from '@/hooks/use-form-autosave';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type Props = {
    stays: any[];
    stayId?: string;
    handoverTypes: Record<string, string>;
};

type HandoverDraft = {
    stay_id: string;
    handover_type: string;
    notes: string;
    sensitive_flag: boolean;
};

const hasDraftContent = (d: HandoverDraft): boolean =>
    !!(d.notes?.trim() || (d.handover_type && d.stay_id));

export default function HandoverNoteCreate({ stays, stayId, handoverTypes }: Props) {
    const page = usePage().props as { auth?: { user?: { id?: number } } };
    const userId = page.auth?.user?.id ?? 0;

    const { data, setData, post, processing, errors } = useForm<HandoverDraft>({
        stay_id: stayId || '',
        handover_type: '',
        notes: '',
        sensitive_flag: false,
    });

    const draftKey = `oblivion:respite-handover-note:v1:u${userId}`;
    const [bootstrapped, setBootstrapped] = useState(false);
    const [resumePayload, setResumePayload] = useState<{ data: HandoverDraft; savedAt: number } | null>(null);

    const { savedAt, load, clear } = useFormAutosave<HandoverDraft>(
        data,
        { stayId: data.stay_id },
        { key: draftKey, enabled: bootstrapped },
    );

    useEffect(() => {
        const existing = load();
        if (existing && hasDraftContent(existing.data as HandoverDraft)) {
            setResumePayload({ data: existing.data as HandoverDraft, savedAt: existing.savedAt });
        } else {
            setBootstrapped(true);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const resumeDraft = () => {
        if (!resumePayload) return;
        (Object.keys(resumePayload.data) as Array<keyof HandoverDraft>).forEach((k) => {
            setData(k, resumePayload.data[k] as never);
        });
        setResumePayload(null);
        setBootstrapped(true);
    };

    const discardDraft = () => {
        clear();
        setResumePayload(null);
        setBootstrapped(true);
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Respite', href: '/respite' }, { title: 'Handover Notes', href: '/respite/handover-notes' }, { title: 'New', href: '/respite/handover-notes/create' }]}>
            <Head title="New Handover Note" />

            <div className="space-y-4">
                <div className="flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <h1 className="text-lg font-semibold">New Handover Note</h1>
                        <div className="mt-1 text-sm text-slate-500">Record handover information for a respite stay.</div>
                    </div>
                    <DraftSavedIndicator savedAt={savedAt} />
                </div>
                <RespiteSubnav />

                {resumePayload && (
                    <DraftResumePrompt
                        savedAt={resumePayload.savedAt}
                        onResume={resumeDraft}
                        onDiscard={discardDraft}
                        description="We kept what you were writing for this handover note."
                    />
                )}

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        post('/respite/handover-notes', {
                            onSuccess: () => clear(),
                        });
                    }}
                    className="space-y-4"
                >
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Handover Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>Stay *</Label>
                                    <Select value={data.stay_id} onValueChange={(v) => setData('stay_id', v)}>
                                        <SelectTrigger><SelectValue placeholder="Select stay" /></SelectTrigger>
                                        <SelectContent>
                                            {stays.map((s: any) => (
                                                <SelectItem key={s.id} value={String(s.id)}>
                                                    {s.client?.first_name} {s.client?.last_name} — Stay #{s.id}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.stay_id && <div className="mt-1 text-xs text-red-500">{errors.stay_id}</div>}
                                </div>
                                <div>
                                    <Label>Handover Type *</Label>
                                    <Select value={data.handover_type} onValueChange={(v) => setData('handover_type', v)}>
                                        <SelectTrigger><SelectValue placeholder="Select type" /></SelectTrigger>
                                        <SelectContent>
                                            {Object.entries(handoverTypes).map(([value, label]) => (
                                                <SelectItem key={value} value={value}>{label}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.handover_type && <div className="mt-1 text-xs text-red-500">{errors.handover_type}</div>}
                                </div>
                            </div>

                            <div>
                                <div className="flex items-center justify-between">
                                    <Label>Notes *</Label>
                                    <VoiceInputButton
                                        value={data.notes}
                                        onChange={(next) => setData('notes', next)}
                                        fieldLabel="Handover notes"
                                    />
                                </div>
                                <Textarea rows={6} value={data.notes} onChange={(e) => setData('notes', e.target.value)} placeholder="Enter handover notes..." />
                                {errors.notes && <div className="mt-1 text-xs text-red-500">{errors.notes}</div>}
                            </div>

                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={data.sensitive_flag} onChange={(e) => setData('sensitive_flag', e.target.checked)} />
                                Mark as sensitive
                            </label>
                        </CardContent>
                    </Card>

                    <div className="flex items-center justify-between gap-2">
                        <DraftSavedIndicator savedAt={savedAt} className="sm:hidden" />
                        <div className="ml-auto flex gap-2">
                            <Button type="button" variant="outline" onClick={() => window.history.back()}>Cancel</Button>
                            <Button type="submit" disabled={processing}>{processing ? 'Saving...' : 'Create Handover Note'}</Button>
                        </div>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
