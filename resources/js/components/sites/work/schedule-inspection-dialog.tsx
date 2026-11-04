/**
 * Schedule inspection — one pop-up for the Sites list, a Site's inspections
 * page and the cross-site Inspections & Maintenance page. Opened from one
 * Site, the Site is locked; opened from a list header, it asks which Site.
 * Saves to the existing POST /sites/{site}/inspections (checklists.schedule +
 * SitePolicy::update), which also adds the optional calendar event.
 */
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { VehicleSearchSelect } from '@/components/fleet-assets/vehicle-workspace/search-select';
import { PopupField, PopupForm, PopupSection } from '@/components/popup';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';
import { CalendarPlus, MapPin } from 'lucide-react';
import { useState } from 'react';
import {
    FREQUENCY_LABELS,
    SITE_TYPE_LABELS,
    siteSelectOptions,
    type SiteWorkSite,
} from './shared';

/** "Custom" is left out: there is no repeat-rule field, so it only ever
 *  behaved as monthly. */
const FREQUENCIES = ['weekly', 'monthly', 'quarterly', 'bi_annual', 'annual'];

export function ScheduleInspectionDialog({
    open,
    onClose,
    site,
    sites = [],
    redirectBack = false,
    onSaved,
}: {
    open: boolean;
    onClose: () => void;
    /** Locks the Site (opened from that Site). */
    site?: SiteWorkSite;
    /** Sites to choose from when no Site is locked. */
    sites?: SiteWorkSite[];
    /** Return to the page that opened the pop-up after saving. */
    redirectBack?: boolean;
    onSaved?: () => void;
}) {
    const [siteId, setSiteId] = useState(site ? String(site.id) : '');
    const [siteError, setSiteError] = useState<string>();
    const form = useForm({
        inspection_type: '',
        title: '',
        description: '',
        frequency: 'monthly',
        first_due_date: '',
        auto_create_calendar_event: true,
        redirect_back: redirectBack,
    });

    const submit = () => {
        if (!siteId) {
            setSiteError('Choose the site to inspect.');
            return;
        }
        form.post(`/sites/${siteId}/inspections`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                onSaved?.();
                onClose();
            },
        });
    };

    const chosenSite = site ?? sites.find((s) => String(s.id) === siteId);

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="md"
            icon={CalendarPlus}
            title="Schedule inspection"
            description="Set up a repeating inspection; the next due date moves on each time a result is recorded"
            context={
                site
                    ? {
                          title: site.name,
                          tag: site.type
                              ? SITE_TYPE_LABELS[site.type]
                              : undefined,
                          icon: MapPin,
                          note: "The site you opened this from — can't change here.",
                      }
                    : undefined
            }
            submitLabel="Schedule inspection"
            processingLabel="Scheduling…"
            processing={form.processing}
            onSubmit={submit}
            dirty={form.isDirty || (!site && siteId !== '')}
            draftName={
                chosenSite
                    ? `an inspection for ${chosenSite.name}`
                    : 'an inspection'
            }
        >
            {!site ? (
                <PopupField
                    label="Site"
                    htmlFor="schedule-inspection-site"
                    required
                    error={siteError}
                >
                    <VehicleSearchSelect
                        id="schedule-inspection-site"
                        label="Site"
                        value={siteId}
                        options={siteSelectOptions(sites)}
                        invalid={!!siteError}
                        onChange={(value) => {
                            setSiteId(value);
                            setSiteError(undefined);
                        }}
                    />
                </PopupField>
            ) : null}

            <PopupSection columns={2}>
                <PopupField
                    label="Inspection type"
                    htmlFor="schedule-inspection-type"
                    required
                    error={form.errors.inspection_type}
                >
                    <Input
                        id="schedule-inspection-type"
                        value={form.data.inspection_type}
                        maxLength={50}
                        placeholder="e.g. Fire safety"
                        aria-invalid={!!form.errors.inspection_type}
                        onChange={(e) =>
                            form.setData('inspection_type', e.target.value)
                        }
                    />
                </PopupField>
                <PopupField
                    label="Title"
                    htmlFor="schedule-inspection-title"
                    required
                    error={form.errors.title}
                >
                    <Input
                        id="schedule-inspection-title"
                        value={form.data.title}
                        maxLength={255}
                        placeholder="e.g. Quarterly fire safety check"
                        aria-invalid={!!form.errors.title}
                        onChange={(e) => form.setData('title', e.target.value)}
                    />
                </PopupField>
                <PopupField
                    label="How often"
                    htmlFor="schedule-inspection-frequency"
                    required
                    error={form.errors.frequency}
                >
                    <Select
                        value={form.data.frequency}
                        onValueChange={(value) =>
                            form.setData('frequency', value)
                        }
                    >
                        <SelectTrigger
                            id="schedule-inspection-frequency"
                            className="w-full"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {FREQUENCIES.map((value) => (
                                <SelectItem key={value} value={value}>
                                    {FREQUENCY_LABELS[value]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </PopupField>
                <PopupField
                    label="First due"
                    htmlFor="schedule-inspection-first-due"
                    required
                    error={form.errors.first_due_date}
                >
                    <DatePicker
                        id="schedule-inspection-first-due"
                        label="First due"
                        value={form.data.first_due_date}
                        invalid={!!form.errors.first_due_date}
                        onChange={(value) =>
                            form.setData('first_due_date', value)
                        }
                    />
                </PopupField>
                <PopupField
                    label="Description"
                    htmlFor="schedule-inspection-description"
                    error={form.errors.description}
                    wide
                >
                    <Textarea
                        id="schedule-inspection-description"
                        rows={3}
                        value={form.data.description}
                        placeholder="What the inspector checks, and anything to bring"
                        onChange={(e) =>
                            form.setData('description', e.target.value)
                        }
                    />
                </PopupField>
                <label className="flex items-center gap-2.5 text-sm sm:col-span-full">
                    <Checkbox
                        checked={form.data.auto_create_calendar_event}
                        onCheckedChange={(checked) =>
                            form.setData(
                                'auto_create_calendar_event',
                                checked === true,
                            )
                        }
                    />
                    Add it to the site calendar
                </label>
            </PopupSection>
        </PopupForm>
    );
}
