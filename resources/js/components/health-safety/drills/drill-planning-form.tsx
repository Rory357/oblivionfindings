import { actualEventInstant } from '@/components/clinical/actual-event-time-field';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import { toDatetimeLocal, workerTimeOffset } from '@/lib/datetime';
import { DRILL_REGISTER } from '@/lib/health-safety/drill-navigation';
import { typeMeta } from '@/pages/health-safety/drills/shared';
import { useEffect, useRef, useState } from 'react';
import { DrillCommandFooter, useDrillCommandForm } from './drill-command-form';
import { DrillTimeField } from './drill-time-field';
import type { DrillPickers, DrillWorkspaceDetail } from './types';

export function DrillPlanningForm({
    detail,
    pickers,
    mode,
    returnTo,
    onSaved,
    onCancel,
}: {
    detail: DrillWorkspaceDetail | null;
    pickers: DrillPickers;
    mode: 'create' | 'edit' | 'schedule-next';
    returnTo: string;
    onSaved: () => void;
    onCancel: () => void;
}) {
    const edit = mode === 'edit';
    const { form, guards, save, failure, setFailure } = useDrillCommandForm(
        {
            site_id: detail?.site ? String(detail.site.id) : '',
            title: detail?.title ?? '',
            drill_type:
                detail?.drill_type === 'fire'
                    ? 'fire_evacuation'
                    : (detail?.drill_type ?? ''),
            scheduled_at: edit ? toDatetimeLocal(detail?.scheduled_at) : '',
            time_offset:
                edit && detail?.scheduled_at
                    ? workerTimeOffset(detail.scheduled_at)
                    : '',
            scenario_description: detail?.scenario_description ?? '',
            assembly_point: detail?.assembly_point ?? '',
            evacuation_scheme: detail?.evacuation_scheme ?? '',
            conducted_by:
                edit && detail?.conducted_by ? String(detail.conducted_by) : '',
            total_participants:
                edit && detail?.total_participants != null
                    ? String(detail.total_participants)
                    : '',
            is_unannounced: detail?.is_unannounced ?? false,
            warden_ids: [] as number[],
        },
        detail,
        onSaved,
    );
    const [staff, setStaff] = useState(detail ? pickers.staff : []);
    const [wardenSearch, setWardenSearch] = useState('');
    const [loading, setLoading] = useState(false);
    const [staffError, setStaffError] = useState<string | null>(null);
    const pending = useRef<AbortController | null>(null);
    useEffect(() => () => pending.current?.abort(), []);
    const loadSite = async (value: string) => {
        pending.current?.abort();
        form.setData((current) => ({
            ...current,
            site_id: value,
            conducted_by: '',
            warden_ids: [],
        }));
        setStaff([]);
        setStaffError(null);
        if (!value) {
            setLoading(false);
            return;
        }
        const request = new AbortController();
        pending.current = request;
        setLoading(true);
        try {
            const response = await fetch(
                `${DRILL_REGISTER}/options?site_id=${encodeURIComponent(value)}`,
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal: request.signal,
                },
            );
            if (!response.ok)
                throw new Error(
                    'Staff options could not be loaded for this Site. Try again before scheduling.',
                );
            const options = (await response.json()) as DrillPickers;
            if (!Array.isArray(options.staff))
                throw new Error(
                    'Staff options could not be confirmed. Try again before scheduling.',
                );
            if (!request.signal.aborted) setStaff(options.staff);
        } catch (error) {
            if (!request.signal.aborted)
                setStaffError(
                    error instanceof Error
                        ? error.message
                        : 'Staff options could not be loaded.',
                );
        } finally {
            if (!request.signal.aborted) setLoading(false);
        }
    };
    const allowed =
        mode === 'create' ||
        (edit ? detail?.can.edit : detail?.can.schedule_next);
    const label = edit
        ? 'Save planning changes'
        : mode === 'schedule-next'
          ? 'Schedule next drill'
          : 'Schedule drill';
    if (!allowed)
        return (
            <p role="alert">
                This drill no longer allows this planning change.
            </p>
        );
    return (
        <form
            className="space-y-5"
            onSubmit={(event) => {
                event.preventDefault();
                if (loading || staffError || form.processing) return;
                const instant = actualEventInstant(
                    form.data.scheduled_at,
                    form.data.time_offset,
                );
                if (!instant) {
                    setFailure(
                        'Choose a valid New Zealand schedule time and its clock occurrence if requested.',
                    );
                    return;
                }
                if (!form.data.site_id || !form.data.drill_type) {
                    setFailure('Choose the Site and drill type.');
                    return;
                }
                form.transform((data) => ({
                    ...guards,
                    return_to: returnTo,
                    section:
                        mode === 'schedule-next' ? 'connections' : 'overview',
                    action: mode === 'create' ? undefined : mode,
                    ...(edit ? {} : { site_id: Number(data.site_id) }),
                    title: data.title,
                    drill_type: data.drill_type,
                    scheduled_at: instant,
                    scenario_description: data.scenario_description || null,
                    assembly_point: data.assembly_point || null,
                    evacuation_scheme: data.evacuation_scheme || null,
                    conducted_by: data.conducted_by
                        ? Number(data.conducted_by)
                        : null,
                    total_participants:
                        data.total_participants === ''
                            ? null
                            : Number(data.total_participants),
                    is_unannounced: data.is_unannounced,
                    ...(!edit ? { warden_ids: data.warden_ids } : {}),
                }));
                save(
                    edit ? 'put' : 'post',
                    mode === 'create'
                        ? DRILL_REGISTER
                        : `${DRILL_REGISTER}/${detail!.id}${edit ? '' : '/schedule-next'}`,
                );
            }}
        >
            <h2 className="text-section-title">
                {edit ? 'Edit drill planning' : label}
            </h2>
            {mode === 'schedule-next' && (
                <p className="text-subtle">
                    This creates a new scheduled drill at the same Site and
                    keeps this drill’s results and findings.
                </p>
            )}
            {detail ? (
                <p className="text-sm">
                    <strong>Site:</strong> {detail.site?.name ?? 'Not recorded'}
                </p>
            ) : (
                <Field label="Site" required>
                    <SelectInput
                        frontline
                        value={form.data.site_id}
                        placeholder="Choose an approved Site"
                        onChange={(value) => void loadSite(value)}
                        options={pickers.sites.map((site) => ({
                            value: String(site.id),
                            label: site.name,
                        }))}
                    />
                </Field>
            )}
            <Field label="Drill title" required>
                <Input
                    className="frontline-tap"
                    required
                    maxLength={255}
                    value={form.data.title}
                    onChange={(event) =>
                        form.setData('title', event.target.value)
                    }
                />
            </Field>
            <Field label="Drill type" required>
                <SelectInput
                    frontline
                    value={form.data.drill_type}
                    placeholder="Choose a drill type"
                    onChange={(value) => form.setData('drill_type', value)}
                    options={pickers.drill_types
                        .filter((value) => value !== 'fire')
                        .map((value) => ({
                            value,
                            label: typeMeta(value).label,
                        }))}
                />
            </Field>
            <DrillTimeField
                label="Scheduled for"
                value={form.data.scheduled_at}
                offset={form.data.time_offset}
                onChange={(value, offset) =>
                    form.setData((data) => ({
                        ...data,
                        scheduled_at: value,
                        time_offset: offset,
                    }))
                }
            />
            <Field label="Scenario">
                <Textarea
                    rows={4}
                    maxLength={5000}
                    value={form.data.scenario_description}
                    onChange={(event) =>
                        form.setData('scenario_description', event.target.value)
                    }
                />
            </Field>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Assembly point">
                    <Input
                        className="frontline-tap"
                        maxLength={255}
                        value={form.data.assembly_point}
                        onChange={(event) =>
                            form.setData('assembly_point', event.target.value)
                        }
                    />
                </Field>
                <Field label="Evacuation scheme">
                    <Input
                        className="frontline-tap"
                        maxLength={255}
                        value={form.data.evacuation_scheme}
                        onChange={(event) =>
                            form.setData(
                                'evacuation_scheme',
                                event.target.value,
                            )
                        }
                    />
                </Field>
            </div>
            <label className="frontline-tap flex items-center gap-3">
                <Checkbox
                    checked={form.data.is_unannounced}
                    onCheckedChange={(value) =>
                        form.setData('is_unannounced', value === true)
                    }
                />
                Unannounced drill
            </label>
            {loading && (
                <p role="status" className="text-subtle">
                    Loading current staff at this Site…
                </p>
            )}
            {staffError && (
                <div className="space-y-2">
                    <p role="alert" className="text-status-critical">
                        {staffError}
                    </p>
                    <Button
                        type="button"
                        className="frontline-tap"
                        variant="outline"
                        onClick={() => void loadSite(form.data.site_id)}
                    >
                        Retry staff options
                    </Button>
                </div>
            )}
            <fieldset
                disabled={loading || !!staffError || !form.data.site_id}
                className="space-y-4"
            >
                <Field label="Coordinator">
                    <SelectInput
                        frontline
                        value={form.data.conducted_by || 'none'}
                        placeholder="Choose a coordinator"
                        onChange={(value) =>
                            form.setData(
                                'conducted_by',
                                value === 'none' ? '' : value,
                            )
                        }
                        options={[
                            { value: 'none', label: 'Unassigned' },
                            ...staff.map((person) => ({
                                value: String(person.id),
                                label: person.name,
                            })),
                            ...(edit &&
                            detail?.coordinator &&
                            !staff.some(
                                (person) =>
                                    person.id === detail.coordinator!.id,
                            )
                                ? [
                                      {
                                          value: String(detail.coordinator.id),
                                          label: `${detail.coordinator.name} · no longer eligible; choose another person`,
                                      },
                                  ]
                                : []),
                        ]}
                    />
                </Field>
                {!edit && (
                    <fieldset className="space-y-2">
                        <legend className="mb-2 text-sm font-medium">
                            Wardens for this drill
                        </legend>
                        {staff.length > 6 && (
                            <Field label="Find wardens">
                                <Input
                                    className="frontline-tap"
                                    type="search"
                                    value={wardenSearch}
                                    onChange={(event) =>
                                        setWardenSearch(event.target.value)
                                    }
                                    placeholder="Search current Site staff"
                                />
                            </Field>
                        )}
                        {form.data.warden_ids.length > 0 && (
                            <p className="text-subtle">
                                {form.data.warden_ids.length} wardens selected
                            </p>
                        )}
                        {staff.length ? (
                            <div className="max-h-64 overflow-y-auto">
                                {staff
                                    .filter((person) =>
                                        person.name
                                            .toLocaleLowerCase()
                                            .includes(
                                                wardenSearch
                                                    .trim()
                                                    .toLocaleLowerCase(),
                                            ),
                                    )
                                    .map((person) => (
                                        <label
                                            key={person.id}
                                            className="frontline-tap flex items-center gap-3"
                                        >
                                            <Checkbox
                                                checked={form.data.warden_ids.includes(
                                                    person.id,
                                                )}
                                                onCheckedChange={(value) =>
                                                    form.setData(
                                                        'warden_ids',
                                                        value === true
                                                            ? [
                                                                  ...form.data
                                                                      .warden_ids,
                                                                  person.id,
                                                              ]
                                                            : form.data.warden_ids.filter(
                                                                  (id) =>
                                                                      id !==
                                                                      person.id,
                                                              ),
                                                    )
                                                }
                                            />
                                            {person.name}
                                        </label>
                                    ))}
                            </div>
                        ) : (
                            <p className="text-subtle">
                                No eligible staff choices are available for the
                                selected Site.
                            </p>
                        )}
                    </fieldset>
                )}
            </fieldset>
            {edit && (
                <p className="text-subtle">
                    Update participant roles and attendance in Participants.
                    Planning changes keep those recorded details.
                </p>
            )}
            <Field label="Expected participant count">
                <Input
                    className="frontline-tap"
                    type="number"
                    min={0}
                    max={9999}
                    step={1}
                    value={form.data.total_participants}
                    onChange={(event) =>
                        form.setData('total_participants', event.target.value)
                    }
                />
            </Field>
            <DrillCommandFooter
                label={label}
                processing={form.processing}
                disabled={loading || !!staffError}
                cancelLabel={
                    mode === 'create' ? 'Back to register' : 'Back to drill'
                }
                failure={failure}
                errors={form.errors}
                onCancel={onCancel}
            />
        </form>
    );
}
