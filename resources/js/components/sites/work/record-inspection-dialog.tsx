/**
 * Record inspection result — completes one scheduled inspection. Opened from
 * an overdue or scheduled inspection, the inspection is locked; opened from a
 * Site, it asks which inspection; opened from a list header, it asks which
 * Site first. Saves to the existing
 * POST /sites/{site}/inspections/{schedule}/complete (checklists.run +
 * SitePolicy::update), which moves the schedule's next due date on and, for a
 * fail, raises the Control Room alert.
 */
import {
    VehicleSearchSelect,
    type VehicleSelectOption,
} from '@/components/fleet-assets/vehicle-workspace/search-select';
import {
    PopupCallout,
    PopupField,
    PopupForm,
    PopupSection,
} from '@/components/popup';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Segmented } from '@/components/wizard/primitives';
import { useForm } from '@inertiajs/react';
import {
    CircleCheck,
    CircleDashed,
    CircleMinus,
    CircleX,
    ClipboardCheck,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    dueLine,
    siteSelectOptions,
    useSiteWorkRead,
    type SiteWorkScheduleRef,
    type SiteWorkSite,
} from './shared';

type ResultValue = 'pass' | 'partial' | 'fail' | 'na';

const RESULTS: {
    value: ResultValue;
    label: string;
    icon: typeof CircleCheck;
}[] = [
    { value: 'pass', label: 'Pass', icon: CircleCheck },
    { value: 'partial', label: 'Partial', icon: CircleDashed },
    { value: 'fail', label: 'Fail', icon: CircleX },
    { value: 'na', label: 'Not applicable', icon: CircleMinus },
];

type ScheduleOptions = { total: number; items: SiteWorkScheduleRef[] };

export function RecordInspectionDialog({
    open,
    onClose,
    site,
    sites = [],
    schedule,
    redirectBack = false,
    onSaved,
}: {
    open: boolean;
    onClose: () => void;
    /** Locks the Site. */
    site?: SiteWorkSite;
    /** Sites to choose from when no Site is locked. */
    sites?: SiteWorkSite[];
    /** Locks the inspection (needs `site`). */
    schedule?: SiteWorkScheduleRef;
    redirectBack?: boolean;
    onSaved?: () => void;
}) {
    const [siteId, setSiteId] = useState(site ? String(site.id) : '');
    const [scheduleId, setScheduleId] = useState(
        schedule ? String(schedule.id) : '',
    );
    const [pickError, setPickError] = useState<{
        site?: string;
        schedule?: string;
    }>({});
    const [reload, setReload] = useState(0);

    const optionsUrl =
        !schedule && siteId
            ? `/sites/${siteId}/work-options?kind=schedules`
            : null;
    const options = useSiteWorkRead<ScheduleOptions>(
        optionsUrl,
        reload,
        siteId,
    );

    const scheduleOptions: VehicleSelectOption[] = useMemo(
        () =>
            options.status === 'ready'
                ? options.data.items.map((item) => ({
                      value: String(item.id),
                      label: item.title,
                      description:
                          [
                              item.inspection_type,
                              dueLine(item.due_date, item.days_overdue),
                          ]
                              .filter(Boolean)
                              .join(' · ') || undefined,
                  }))
                : [],
        [options],
    );

    const form = useForm({
        result: '',
        findings: '',
        corrective_actions: '',
        redirect_back: redirectBack,
    });

    const submit = () => {
        const errors = {
            site: siteId ? undefined : 'Choose the site.',
            schedule: scheduleId ? undefined : 'Choose the inspection.',
        };
        setPickError(errors);
        if (errors.site || errors.schedule) return;
        form.post(`/sites/${siteId}/inspections/${scheduleId}/complete`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                onSaved?.();
                onClose();
            },
        });
    };

    const lockedSite = site ?? sites.find((s) => String(s.id) === siteId);

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="md"
            icon={ClipboardCheck}
            title="Record inspection result"
            description="Record how the inspection went; its next due date moves on"
            context={
                schedule && site
                    ? {
                          title: schedule.title,
                          tag: site.name,
                          icon: ClipboardCheck,
                          note:
                              dueLine(
                                  schedule.due_date,
                                  schedule.days_overdue,
                              ) || undefined,
                      }
                    : site
                      ? {
                            title: site.name,
                            icon: ClipboardCheck,
                            note: "The site you opened this from — can't change here.",
                        }
                      : undefined
            }
            submitLabel="Record result"
            processingLabel="Recording…"
            processing={form.processing}
            onSubmit={submit}
            dirty={
                form.isDirty ||
                (!site && siteId !== '') ||
                (!schedule && scheduleId !== '')
            }
            draftName={
                lockedSite
                    ? `an inspection result for ${lockedSite.name}`
                    : 'an inspection result'
            }
        >
            {!site ? (
                <PopupField
                    label="Site"
                    htmlFor="record-inspection-site"
                    required
                    error={pickError.site}
                >
                    <VehicleSearchSelect
                        id="record-inspection-site"
                        label="Site"
                        value={siteId}
                        options={siteSelectOptions(sites)}
                        invalid={!!pickError.site}
                        onChange={(value) => {
                            setSiteId(value);
                            setScheduleId('');
                            setPickError({});
                        }}
                    />
                </PopupField>
            ) : null}

            {!schedule ? (
                <PopupField
                    label="Inspection"
                    htmlFor="record-inspection-schedule"
                    required
                    error={pickError.schedule}
                    hint={
                        !siteId
                            ? 'Choose the site first.'
                            : options.status === 'ready' &&
                                options.data.items.length === 0
                              ? 'This site has no active inspection schedules. Schedule one first.'
                              : options.status === 'ready' &&
                                  options.data.total > options.data.items.length
                                ? `Showing the next ${options.data.items.length} of ${options.data.total}, soonest due first.`
                                : undefined
                    }
                >
                    {options.status === 'failed' ? (
                        <PopupCallout tone="critical">
                            This site&apos;s inspections couldn&apos;t load.{' '}
                            <Button
                                type="button"
                                variant="link"
                                className="h-auto p-0"
                                onClick={() => setReload((n) => n + 1)}
                            >
                                Try again
                            </Button>
                        </PopupCallout>
                    ) : (
                        <VehicleSearchSelect
                            id="record-inspection-schedule"
                            label="Inspection"
                            value={scheduleId}
                            options={scheduleOptions}
                            disabled={
                                !siteId ||
                                options.status === 'loading' ||
                                (options.status === 'ready' &&
                                    options.data.items.length === 0)
                            }
                            invalid={!!pickError.schedule}
                            onChange={(value) => {
                                setScheduleId(value);
                                setPickError((e) => ({
                                    ...e,
                                    schedule: undefined,
                                }));
                            }}
                        />
                    )}
                </PopupField>
            ) : null}

            <PopupSection>
                <PopupField
                    label="Result"
                    htmlFor="record-inspection-result"
                    required
                    error={form.errors.result}
                >
                    <div
                        id="record-inspection-result"
                        role="group"
                        aria-label="Result"
                    >
                        <Segmented<string>
                            value={form.data.result}
                            onChange={(value) => form.setData('result', value)}
                            options={RESULTS}
                        />
                    </div>
                </PopupField>
                {form.data.result === 'fail' ? (
                    <PopupCallout tone="warning">
                        A failed inspection raises an alert in the Control Room.
                    </PopupCallout>
                ) : null}
                <PopupField
                    label="Findings"
                    htmlFor="record-inspection-findings"
                    error={form.errors.findings}
                >
                    <Textarea
                        id="record-inspection-findings"
                        rows={3}
                        value={form.data.findings}
                        placeholder="What the inspector found"
                        onChange={(e) =>
                            form.setData('findings', e.target.value)
                        }
                    />
                </PopupField>
                <PopupField
                    label="Corrective actions"
                    htmlFor="record-inspection-actions"
                    error={form.errors.corrective_actions}
                >
                    <Textarea
                        id="record-inspection-actions"
                        rows={3}
                        value={form.data.corrective_actions}
                        placeholder="What needs fixing, and by whom"
                        onChange={(e) =>
                            form.setData('corrective_actions', e.target.value)
                        }
                    />
                </PopupField>
            </PopupSection>
        </PopupForm>
    );
}
