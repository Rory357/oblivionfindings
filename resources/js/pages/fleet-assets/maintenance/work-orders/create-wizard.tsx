import { MaintenanceDateRange } from '@/components/fleet-assets/maintenance/date-range';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { DateTimeField, localDateTimeLabel } from '@/components/fleet-assets/maintenance/date-time-field';
import { formatDateOnly } from '@/lib/datetime';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow, WizardShell, WizardStepPane, WizardSuccessPane, type WizardStep } from '@/components/wizard/shell';
import { useForm } from '@inertiajs/react';
import { Check, ClipboardCheck, FileText, Link2, Paperclip, Search, Wrench } from 'lucide-react';
import { useEffect, useState } from 'react';

export type WizardAsset = {
    id: number; name: string; asset_tag: string | null; category: string | null;
    site_id?: number; registration_number?: string | null; location?: string | null;
};
export type WizardChecklistRun = {
    id: number; asset_id?: number; asset_name: string; template_name: string; run_at: string | null;
};
type ExistingOrder = { id: number; reference_number: string | null; title: string; status: string };

const STEPS: readonly WizardStep[] = [
    { key: 'resource', label: 'Resource', blurb: 'Affected asset and estimate', icon: Wrench },
    { key: 'problem', label: 'Problem', blurb: 'What you observed', icon: ClipboardCheck },
    { key: 'evidence', label: 'Evidence', blurb: 'Photos and documents', icon: Paperclip },
    { key: 'related', label: 'Related work', blurb: 'Avoid a duplicate job', icon: Link2 },
    { key: 'review', label: 'Review', blurb: 'Confirm and send', icon: FileText },
];

type StagedEvidence = { file: File; category: string; description: string };
const estimateLabel = (start: string, end: string) =>
    start === end ? formatDateOnly(start) : `${formatDateOnly(start)} – ${formatDateOnly(end)}`;

export function WorkOrderCreateWizard({ open, onClose, assets, checklistRuns, prefillAssetId, prefillChecklistRunId,
    prefillExistingWorkOrderId, prefillCorrectsReportId, canLink = true }: {
    open: boolean; onClose: () => void; assets: WizardAsset[];
    users?: Array<{ id: number; name: string }>;
    checklistRuns: WizardChecklistRun[];
    prefillAssetId?: string | null; prefillChecklistRunId?: string | null;
    prefillExistingWorkOrderId?: string | null; prefillCorrectsReportId?: string | null;
    canLink?: boolean;
}) {
    const [stepIndex, setStepIndex] = useState(0);
    const [assetSearch, setAssetSearch] = useState('');
    const [assetOptions, setAssetOptions] = useState(assets);
    const [orderSearch, setOrderSearch] = useState('');
    const [orderOptions, setOrderOptions] = useState<ExistingOrder[]>([]);
    const [orderSearching, setOrderSearching] = useState(false);
    const [orderSearchError, setOrderSearchError] = useState(false);
    const [selectedResource, setSelectedResource] = useState<WizardAsset | undefined>(() => assets.find((asset) => String(asset.id) === prefillAssetId));
    const [choosingAsset, setChoosingAsset] = useState(!prefillAssetId);
    const [assetSearching, setAssetSearching] = useState(false);
    const [assetSearchError, setAssetSearchError] = useState(false);
    const [assetRetry, setAssetRetry] = useState(0);
    const [furthestStep, setFurthestStep] = useState(0);
    const [staged, setStaged] = useState<StagedEvidence[]>([]);
    const [fileError, setFileError] = useState('');
    const [done, setDone] = useState(false);
    const form = useForm({
        asset_id: prefillAssetId ?? '', title: '', description: '', priority: 'medium',
        observed_local: '', observed_offset: '',
        source_type: prefillChecklistRunId ? 'fleet_checklist_run' : '',
        source_id: prefillChecklistRunId ?? '', existing_work_order_id: prefillExistingWorkOrderId ?? '',
        corrects_report_id: prefillCorrectsReportId ?? '',
        estimated_start_date: '', estimated_end_date: '', request_key: crypto.randomUUID(),
        files: [] as StagedEvidence[],
    });

    useEffect(() => {
        if (assetSearch.trim().length < 2) { setAssetOptions(assets); setAssetSearching(false); setAssetSearchError(false); return; }
        const controller = new AbortController();
        setAssetSearching(true); setAssetSearchError(false);
        const timer = setTimeout(() => {
            fetch(`/fleet-assets/maintenance/work-orders/options/search?type=assets&q=${encodeURIComponent(assetSearch)}`,
                { signal: controller.signal, credentials: 'same-origin' })
                .then((response) => response.ok ? response.json() : Promise.reject(response))
                .then((body: { results: WizardAsset[] }) => { setAssetOptions(body.results); setAssetSearching(false); })
                .catch(() => { if (!controller.signal.aborted) { setAssetSearchError(true); setAssetSearching(false); } });
        }, 250);
        return () => { clearTimeout(timer); controller.abort(); };
    }, [assetSearch, assets, assetRetry]);

    useEffect(() => {
        if (!form.data.asset_id || orderSearch.trim().length < 2) {
            setOrderOptions([]); setOrderSearching(false); setOrderSearchError(false); return;
        }
        const controller = new AbortController();
        setOrderSearching(true); setOrderSearchError(false);
        const timer = setTimeout(() => {
            fetch(`/fleet-assets/maintenance/work-orders/options/search?type=work_orders&asset_id=${form.data.asset_id}&q=${encodeURIComponent(orderSearch)}`,
                { signal: controller.signal, credentials: 'same-origin' })
                .then((response) => response.ok ? response.json() : Promise.reject(response))
                .then((body: { results: ExistingOrder[] }) => { setOrderOptions(body.results); setOrderSearching(false); })
                .catch(() => { if (!controller.signal.aborted) {
                    setOrderOptions([]); setOrderSearchError(true); setOrderSearching(false);
                } });
        }, 250);
        return () => { clearTimeout(timer); controller.abort(); };
    }, [orderSearch, form.data.asset_id]);

    const selectedAsset = [selectedResource, ...assetOptions, ...assets].filter((asset): asset is WizardAsset => Boolean(asset)).find((asset) => String(asset.id) === form.data.asset_id);
    const selectedRun = checklistRuns.find((run) => String(run.id) === form.data.source_id);
    const canContinue = stepIndex === 0 ? Boolean(form.data.asset_id &&
        (Boolean(form.data.estimated_start_date) === Boolean(form.data.estimated_end_date)))
        : stepIndex === 1 ? Boolean(form.data.title.trim() && form.data.description.trim() &&
            (!form.data.observed_local || /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(form.data.observed_local))) : true;
    const submit = () => {
        form.transform((data) => ({ ...data, files: staged }));
        form.post('/fleet-assets/maintenance/work-orders', {
        preserveScroll: true,
        forceFormData: true,
        onError: (errors) => setStepIndex(errors.asset_id || errors.estimated_start_date || errors.estimated_end_date ? 0 : errors.title || errors.description || errors.observed_local ? 1 : Object.keys(errors).some((key) => key.startsWith('files')) ? 2 : 3),
        onSuccess: () => setDone(true),
        });
    };
    const advance = () => { setFurthestStep((current) => Math.max(current, stepIndex + 1)); setStepIndex(stepIndex + 1); };
    const startAgain = () => { form.reset(); form.setData('request_key', crypto.randomUUID()); setStaged([]); setDone(false); setStepIndex(0); setFurthestStep(0); };

    return <WizardShell open={open} onClose={onClose} title="Report a problem"
        description="Identify the resource, describe the problem, link evidence and related work, then review."
        railIcon={Wrench} railTitle="Report a problem" railSub="Maintenance" steps={STEPS}
        stepIndex={stepIndex} onStepClick={(index) => { if (index <= stepIndex || (index <= furthestStep && canContinue)) setStepIndex(index); }}
        footerStart={<Button type="button" variant="ghost" onClick={() => stepIndex ? setStepIndex(stepIndex - 1) : onClose()}>
            {stepIndex ? 'Back' : 'Keep draft and close'}</Button>}
        footerEnd={stepIndex < 4 ? <Button type="button" onClick={advance} disabled={!canContinue}>Continue</Button>
            : <Button type="button" onClick={submit} disabled={form.processing}>{form.processing ? 'Sending…' : form.data.existing_work_order_id ? 'Send linked report' : 'Send report'}</Button>}
        success={done ? <WizardSuccessPane title="Problem report recorded"
            blurb="The report and its separate evidence are retained. The site Coordinator will assess the concern. No safety clearance is implied."
            actions={<><Button type="button" variant="outline" onClick={startAgain}>Report another problem</Button><Button type="button" onClick={onClose}>Close</Button></>} /> : undefined}>
        {stepIndex === 0 && <WizardStepPane><div className="space-y-5">
            <div><h2 className="text-lg font-semibold">Which resource is affected?</h2><p className="mt-1 text-sm text-muted-foreground">Only assets in your approved sites are available.</p></div>
            {selectedAsset && !choosingAsset ? <div className="flex items-center gap-3 rounded-xl border border-primary/40 bg-primary/5 p-4">
                <Wrench className="size-5 text-primary" /><div className="flex-1"><strong className="text-sm">{selectedAsset.name} · {selectedAsset.asset_tag ?? selectedAsset.registration_number}</strong>
                    <p className="mt-1 text-xs text-muted-foreground">{selectedAsset.location ?? selectedAsset.category ?? 'Asset'}</p></div><Check className="size-4 text-primary" />
                <Button type="button" size="sm" variant="outline" onClick={() => setChoosingAsset(true)}>Change</Button></div> : <div className="space-y-2">
                <Label htmlFor="work-asset-search">Affected asset *</Label>
                <div className="relative"><Search className="absolute left-3 top-3 size-4 text-muted-foreground" />
                    <Input id="work-asset-search" className="pl-9" value={assetSearch} onChange={(event) => setAssetSearch(event.target.value)} placeholder="Name, tag, registration or location" /></div>
                <div className="max-h-44 overflow-y-auto rounded-lg border">
                    {[...(selectedAsset && !assetOptions.some((asset) => asset.id === selectedAsset.id) ? [selectedAsset] : []), ...assetOptions].map((asset) =>
                        <Button key={asset.id} type="button" variant={form.data.asset_id === String(asset.id) ? 'secondary' : 'ghost'}
                            className="h-auto w-full justify-start rounded-none border-b px-3 py-2 text-left last:border-0"
                            onClick={() => { setSelectedResource(asset); setChoosingAsset(false); form.setData((data) => ({ ...data, asset_id: String(asset.id), source_type: '', source_id: '', existing_work_order_id: '', corrects_report_id: '' })); setOrderSearch(''); }}>
                            <span>{asset.name} {asset.asset_tag ? `· ${asset.asset_tag}` : ''}<small className="block text-muted-foreground">{asset.registration_number ?? asset.category ?? 'Asset'} {asset.location ? `· ${asset.location}` : ''}</small></span>
                        </Button>)}
                    {!assetSearching && !assetSearchError && assetOptions.length === 0 && <p className="p-3 text-sm text-muted-foreground">No matching asset at your approved sites.</p>}
                </div>
                {assetSearching && <p role="status" className="text-sm text-muted-foreground">Searching approved assets…</p>}
                {assetSearchError && <div role="alert" className="rounded-lg border border-destructive/30 p-3 text-sm"><p>Asset search could not be completed. Your selection and report draft are kept.</p>
                    <Button type="button" size="sm" variant="outline" className="mt-2" onClick={() => setAssetRetry((value) => value + 1)}>Retry asset search</Button></div>}
                {selectedAsset && <Button type="button" variant="ghost" size="sm" onClick={() => setChoosingAsset(false)}>Keep selected asset</Button>}
                {form.errors.asset_id && <p className="text-sm text-destructive">{form.errors.asset_id}</p>}
            </div>}
            {selectedAsset && <><MaintenanceDateRange title="Estimated maintenance window"
                hint={`When might ${selectedAsset.name} need maintenance?`} optional
                start={form.data.estimated_start_date || null} end={form.data.estimated_end_date || null}
                onChange={(start, end) => form.setData((data) => ({ ...data, estimated_start_date: start ?? '', estimated_end_date: end ?? '' }))} />
                <p className="text-xs text-muted-foreground">Selected dates appear on the resource calendar. This is an estimate; provider confirmation is separate.</p>
                {(form.errors.estimated_start_date || form.errors.estimated_end_date) && <p role="alert" className="text-sm text-destructive">{form.errors.estimated_start_date ?? form.errors.estimated_end_date}</p>}</>}
            <p className="rounded-lg border border-primary/20 bg-primary/5 p-3 text-sm">For an immediate concern, follow your local response procedure. This report does not replace an incident report where one is required.</p>
        </div></WizardStepPane>}
        {stepIndex === 1 && <WizardStepPane><div className="space-y-5">
            <div className="space-y-2"><Label htmlFor="work-title">Problem title *</Label>
                <Input id="work-title" value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} maxLength={255} placeholder="e.g. Brake warning remains on" />
                {form.errors.title && <p className="text-sm text-destructive">{form.errors.title}</p>}</div>
            <div className="space-y-2"><Label htmlFor="work-description">What did you observe? *</Label>
                <Textarea id="work-description" rows={4} value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} maxLength={5000}
                    placeholder="Describe what happened, what you saw and whether the resource was used." />
                {form.errors.description && <p className="text-sm text-destructive">{form.errors.description}</p>}</div>
            <DateTimeField id="work-observed" label="Observed at" value={form.data.observed_local}
                onChange={(value) => form.setData('observed_local', value)}
                hint="Optional. The observation time is separate from the estimated maintenance window."
                error={form.errors.observed_local} />
            {form.data.observed_local && <div><Label htmlFor="observed-offset">If this minute occurs twice when clocks change</Label>
                <select id="observed-offset" className="mt-1 h-10 w-full rounded-md border bg-background px-3" value={form.data.observed_offset}
                    onChange={(event) => form.setData('observed_offset', event.target.value)}>
                    <option value="">Ordinary Auckland time</option><option value="+13:00">First occurrence · UTC+13</option><option value="+12:00">Second occurrence · UTC+12</option>
                </select></div>}
            <div className="space-y-2"><Label htmlFor="work-priority">Priority for assessment</Label>
                <select id="work-priority" className="h-10 w-full rounded-md border bg-background px-3" value={form.data.priority}
                    onChange={(event) => form.setData('priority', event.target.value)}>
                    <option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option><option value="critical">Critical</option>
                </select></div>
        </div></WizardStepPane>}
        {stepIndex === 2 && <WizardStepPane><div className="space-y-4">
            <p className="text-sm text-muted-foreground">Add relevant condition photos or documents. Private files stay with this report and are visible only to authorised staff.</p>
            <FileDropzone id="report-evidence" accept="image/jpeg,image/png,application/pdf" title="Drop photos & documents here" hint="PDF, JPG or PNG · up to 10 MB per file · up to 10 files"
                onFiles={(files) => { const allowed = files.filter((file) => file.size > 0 && file.size <= 10 * 1024 * 1024 && ['image/jpeg', 'image/png', 'application/pdf'].includes(file.type));
                    setFileError(allowed.length === files.length ? '' : 'Some files were not added. Choose a PDF, JPG or PNG under 10 MB.');
                    setStaged((current) => [...current, ...allowed.map((file) => ({ file, category: file.type.startsWith('image/') ? 'Condition photo' : 'Other document', description: '' }))].slice(0, 10)); }} />
            {fileError && <p role="alert" className="text-sm text-destructive">{fileError}</p>}
            {staged.map((item, index) => <StagedFileCard key={`${item.file.name}-${index}`} file={item.file} onRemove={() => setStaged((current) => current.filter((_, i) => i !== index))}>
                <div className="grid gap-2 sm:grid-cols-2"><select aria-label={`Category for ${item.file.name}`} className="h-10 rounded-md border bg-background px-2 text-sm" value={item.category}
                    onChange={(event) => setStaged((current) => current.map((entry, i) => i === index ? { ...entry, category: event.target.value } : entry))}>
                    <option>Condition photo</option><option>Inspection document</option><option>Service record</option><option>Quote</option><option>Other document</option></select>
                    <Input aria-label={`Description for ${item.file.name}`} value={item.description} maxLength={2000} placeholder="What does this show, and who supplied it?"
                        onChange={(event) => setStaged((current) => current.map((entry, i) => i === index ? { ...entry, description: event.target.value } : entry))} /></div>
            </StagedFileCard>)}
            {Object.entries(form.errors).filter(([key]) => key.startsWith('files')).map(([key, error]) => <p key={key} role="alert" className="text-sm text-destructive">{error}</p>)}
        </div></WizardStepPane>}
        {stepIndex === 3 && <WizardStepPane><div className="space-y-5">
            <div className="space-y-2"><Label htmlFor="work-check-source">Related check</Label>
                <select id="work-check-source" className="h-10 w-full rounded-md border bg-background px-3" value={form.data.source_id}
                    onChange={(event) => form.setData((data) => ({ ...data, source_id: event.target.value, source_type: event.target.value ? 'fleet_checklist_run' : '' }))}>
                    <option value="">No related check</option>
                    {checklistRuns.filter((run) => !run.asset_id || String(run.asset_id) === form.data.asset_id).map((run) =>
                        <option key={run.id} value={run.id}>{run.template_name} · {run.asset_name}</option>)}
                </select></div>
            {canLink ? <div className="space-y-2"><Label htmlFor="work-duplicate-search">Existing work on this asset</Label>
                <Input id="work-duplicate-search" value={orderSearch} onChange={(event) => setOrderSearch(event.target.value)} placeholder="Search title or reference" />
                {orderOptions.map((order) => <Button key={order.id} type="button" variant={form.data.existing_work_order_id === String(order.id) ? 'secondary' : 'outline'}
                    className="h-auto w-full justify-start" onClick={() => form.setData((data) => ({ ...data,
                        existing_work_order_id: String(order.id), corrects_report_id: data.existing_work_order_id === String(order.id) ? data.corrects_report_id : '' }))}>
                    {order.reference_number} · {order.title} · {order.status}</Button>)}
                {orderSearching && <p role="status" className="text-sm text-muted-foreground">Searching this resource…</p>}
                {orderSearchError && <p role="alert" className="text-sm text-destructive">Related work could not be checked. Your report draft is kept; try the search again.</p>}
                {orderSearch.trim().length >= 2 && !orderSearching && !orderSearchError && orderOptions.length === 0 &&
                    <p className="text-sm text-muted-foreground">No matching work on this resource. You can send a separate report.</p>}
                {form.data.existing_work_order_id && <Button type="button" variant="ghost" onClick={() => form.setData((data) => ({ ...data, existing_work_order_id: '', corrects_report_id: '' }))}>Create separate work instead</Button>}
                {form.data.corrects_report_id && <p className="text-sm font-medium">Correcting report #{form.data.corrects_report_id}. The earlier report remains visible and unchanged.</p>}
                <p className="text-xs text-muted-foreground">Link only if this is the same issue. The original report remains separate evidence.</p>
            </div> : <p className="text-sm text-muted-foreground">The site Coordinator will review related work and retain your report as its own source.</p>}
        </div></WizardStepPane>}
        {stepIndex === 4 && <WizardStepPane><div className="space-y-4"><ReviewCard title="Resource and problem" icon={Wrench}>
            <ReviewRow label="Asset" value={selectedAsset?.name ?? 'Not selected'} />
            <ReviewRow label="Problem" value={form.data.title} />
            <ReviewRow label="Observation" value={form.data.description} />
            <ReviewRow label="Observed" value={form.data.observed_local ? localDateTimeLabel(form.data.observed_local) : 'Not provided'} />
            <ReviewRow label="Estimated maintenance window" value={form.data.estimated_start_date ? `${estimateLabel(form.data.estimated_start_date, form.data.estimated_end_date)} · estimate only` : 'Not known yet'} />
        </ReviewCard><ReviewCard title="Evidence and routing" icon={Paperclip}>
            <ReviewRow label="Evidence" value={staged.length ? staged.map((item) => `${item.file.name} · ${item.category}`).join(', ') : 'No attachment supplied'} />
            <ReviewRow label="Related check" value={selectedRun?.template_name ?? 'None'} />
            <ReviewRow label="Related work" value={form.data.existing_work_order_id || 'New work assessment'} />
            {form.data.corrects_report_id && <ReviewRow label="Corrects report" value={`#${form.data.corrects_report_id} · new dated source`} />}
            <p className="mt-4 text-sm text-muted-foreground">The approved site Coordinator receives this report. Your request key and draft remain available for a retry.</p>
            {Object.keys(form.errors).length > 0 && <p className="mt-3 text-sm text-destructive">Check the highlighted fields and try again.</p>}
        </ReviewCard></div></WizardStepPane>}
    </WizardShell>;
}
