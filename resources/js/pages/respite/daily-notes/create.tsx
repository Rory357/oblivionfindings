import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import DraftSavedIndicator from '@/components/draft-saved-indicator';
import DraftResumePrompt from '@/components/draft-resume-prompt';
import { useFormAutosave } from '@/hooks/use-form-autosave';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type Props = {
    stays: any[];
    stayId?: string;
    clientId?: string;
    shiftPeriods: string[];
    wellbeingLevels: any;
    mobilityLevels: string[];
};

type DailyNoteDraft = {
    stay_id: string;
    note_date: string;
    shift_period: string;
    mood: string;
    appetite: string;
    sleep_quality: string;
    engagement: string;
    mobility: string;
    activities: string;
    observations: string;
    concerns: string;
    goals_progress: string;
    incident_occurred: boolean;
    sensitive_flag: boolean;
};

const hasDraftContent = (d: DailyNoteDraft): boolean =>
    !!(
        d.activities?.trim() ||
        d.observations?.trim() ||
        d.concerns?.trim() ||
        d.goals_progress?.trim() ||
        d.mood ||
        d.appetite ||
        d.sleep_quality ||
        d.engagement ||
        d.mobility
    );

export default function DailyNoteCreate({ stays, stayId, shiftPeriods, wellbeingLevels, mobilityLevels }: Props) {
    const wellbeingOptions = Array.isArray(wellbeingLevels) ? wellbeingLevels : Object.keys(wellbeingLevels || {});
    const page = usePage().props as { auth?: { user?: { id?: number } } };
    const userId = page.auth?.user?.id ?? 0;

    const { data, setData, post, processing, errors } = useForm<DailyNoteDraft>({
        stay_id: stayId || '',
        note_date: '',
        shift_period: '',
        mood: '',
        appetite: '',
        sleep_quality: '',
        engagement: '',
        mobility: '',
        activities: '',
        observations: '',
        concerns: '',
        goals_progress: '',
        incident_occurred: false,
        sensitive_flag: false,
    });

    const draftKey = `oblivion:respite-daily-note:v1:u${userId}`;
    const [bootstrapped, setBootstrapped] = useState(false);
    const [resumePayload, setResumePayload] = useState<{ data: DailyNoteDraft; savedAt: number } | null>(null);

    const { savedAt, load, clear } = useFormAutosave<DailyNoteDraft>(
        data,
        { stayId: data.stay_id },
        { key: draftKey, enabled: bootstrapped },
    );

    useEffect(() => {
        const existing = load();
        if (existing && hasDraftContent(existing.data as DailyNoteDraft)) {
            setResumePayload({ data: existing.data as DailyNoteDraft, savedAt: existing.savedAt });
        } else {
            setBootstrapped(true);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const resumeDraft = () => {
        if (!resumePayload) return;
        (Object.keys(resumePayload.data) as Array<keyof DailyNoteDraft>).forEach((k) => {
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
        <AppLayout breadcrumbs={[
            { title: 'Respite', href: '/respite' },
            { title: 'Daily Notes', href: '/respite/daily-notes' },
            { title: 'New Note', href: '/respite/daily-notes/create' },
        ]}>
            <Head title="New Daily Note" />

            <div className="space-y-4">
                <div className="flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <h1 className="text-lg font-semibold">New Daily Note</h1>
                        <div className="mt-1 text-sm text-slate-500">
                            Record wellbeing observations and activities for a shift.
                        </div>
                    </div>
                    <DraftSavedIndicator savedAt={savedAt} />
                </div>

                {resumePayload && (
                    <DraftResumePrompt
                        savedAt={resumePayload.savedAt}
                        onResume={resumeDraft}
                        onDiscard={discardDraft}
                        description="We kept what you were writing for this daily note."
                    />
                )}

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        post('/respite/daily-notes', {
                            onSuccess: () => clear(),
                        });
                    }}
                    className="space-y-4"
                >
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Shift Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-3">
                                <div>
                                    <Label>Stay</Label>
                                    <Select value={data.stay_id} onValueChange={(v) => setData('stay_id', v)}>
                                        <SelectTrigger><SelectValue placeholder="Select a stay" /></SelectTrigger>
                                        <SelectContent>
                                            {stays.map((s: any) => (
                                                <SelectItem key={s.id} value={String(s.id)}>
                                                    {s.client?.first_name} {s.client?.last_name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.stay_id && <div className="mt-1 text-xs text-red-500">{errors.stay_id}</div>}
                                </div>
                                <div>
                                    <Label>Note Date</Label>
                                    <Input
                                        type="date"
                                        value={data.note_date}
                                        onChange={(e) => setData('note_date', e.target.value)}
                                    />
                                    {errors.note_date && <div className="mt-1 text-xs text-red-500">{errors.note_date}</div>}
                                </div>
                                <div>
                                    <Label>Shift Period</Label>
                                    <Select value={data.shift_period} onValueChange={(v) => setData('shift_period', v)}>
                                        <SelectTrigger><SelectValue placeholder="Select shift" /></SelectTrigger>
                                        <SelectContent>
                                            {shiftPeriods.map((sp) => (
                                                <SelectItem key={sp} value={sp}>{sp}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.shift_period && <div className="mt-1 text-xs text-red-500">{errors.shift_period}</div>}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Wellbeing</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <div>
                                <Label>Mood</Label>
                                <Select value={data.mood} onValueChange={(v) => setData('mood', v)}>
                                    <SelectTrigger><SelectValue placeholder="Select" /></SelectTrigger>
                                    <SelectContent>
                                        {wellbeingOptions.map((level: string) => (
                                            <SelectItem key={level} value={level}>{level}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.mood && <div className="mt-1 text-xs text-red-500">{errors.mood}</div>}
                            </div>
                            <div>
                                <Label>Appetite</Label>
                                <Select value={data.appetite} onValueChange={(v) => setData('appetite', v)}>
                                    <SelectTrigger><SelectValue placeholder="Select" /></SelectTrigger>
                                    <SelectContent>
                                        {wellbeingOptions.map((level: string) => (
                                            <SelectItem key={level} value={level}>{level}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.appetite && <div className="mt-1 text-xs text-red-500">{errors.appetite}</div>}
                            </div>
                            <div>
                                <Label>Sleep Quality</Label>
                                <Select value={data.sleep_quality} onValueChange={(v) => setData('sleep_quality', v)}>
                                    <SelectTrigger><SelectValue placeholder="Select" /></SelectTrigger>
                                    <SelectContent>
                                        {wellbeingOptions.map((level: string) => (
                                            <SelectItem key={level} value={level}>{level}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.sleep_quality && <div className="mt-1 text-xs text-red-500">{errors.sleep_quality}</div>}
                            </div>
                            <div>
                                <Label>Engagement</Label>
                                <Select value={data.engagement} onValueChange={(v) => setData('engagement', v)}>
                                    <SelectTrigger><SelectValue placeholder="Select" /></SelectTrigger>
                                    <SelectContent>
                                        {wellbeingOptions.map((level: string) => (
                                            <SelectItem key={level} value={level}>{level}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.engagement && <div className="mt-1 text-xs text-red-500">{errors.engagement}</div>}
                            </div>
                            <div>
                                <Label>Mobility</Label>
                                <Select value={data.mobility} onValueChange={(v) => setData('mobility', v)}>
                                    <SelectTrigger><SelectValue placeholder="Select" /></SelectTrigger>
                                    <SelectContent>
                                        {mobilityLevels.map((level) => (
                                            <SelectItem key={level} value={level}>{level}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.mobility && <div className="mt-1 text-xs text-red-500">{errors.mobility}</div>}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Notes</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <Label>Activities</Label>
                                <Textarea
                                    value={data.activities}
                                    onChange={(e) => setData('activities', e.target.value)}
                                    rows={3}
                                />
                                {errors.activities && <div className="mt-1 text-xs text-red-500">{errors.activities}</div>}
                            </div>
                            <div>
                                <Label>Observations</Label>
                                <Textarea
                                    value={data.observations}
                                    onChange={(e) => setData('observations', e.target.value)}
                                    rows={3}
                                />
                                {errors.observations && <div className="mt-1 text-xs text-red-500">{errors.observations}</div>}
                            </div>
                            <div>
                                <Label>Concerns</Label>
                                <Textarea
                                    value={data.concerns}
                                    onChange={(e) => setData('concerns', e.target.value)}
                                    rows={3}
                                />
                                {errors.concerns && <div className="mt-1 text-xs text-red-500">{errors.concerns}</div>}
                            </div>
                            <div>
                                <Label>Goals Progress</Label>
                                <Textarea
                                    value={data.goals_progress}
                                    onChange={(e) => setData('goals_progress', e.target.value)}
                                    rows={3}
                                />
                                {errors.goals_progress && <div className="mt-1 text-xs text-red-500">{errors.goals_progress}</div>}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Flags</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-wrap gap-6">
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={data.incident_occurred}
                                    onChange={(e) => setData('incident_occurred', e.target.checked)}
                                    className="rounded border-slate-300"
                                />
                                Incident occurred
                            </label>
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={data.sensitive_flag}
                                    onChange={(e) => setData('sensitive_flag', e.target.checked)}
                                    className="rounded border-slate-300"
                                />
                                Sensitive
                            </label>
                        </CardContent>
                    </Card>

                    <div className="flex items-center justify-between gap-2">
                        <DraftSavedIndicator savedAt={savedAt} className="sm:hidden" />
                        <div className="ml-auto">
                            <Button type="submit" disabled={processing}>
                                Save Daily Note
                            </Button>
                        </div>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
