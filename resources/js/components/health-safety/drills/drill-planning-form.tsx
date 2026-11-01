import { actualEventInstant } from '@/components/clinical/actual-event-time-field';
import { PopupForm } from '@/components/popup';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import { toDatetimeLocal, workerTimeOffset } from '@/lib/datetime';
import { DRILL_REGISTER } from '@/lib/health-safety/drill-navigation';
import { cn } from '@/lib/utils';
import { typeMeta } from '@/pages/health-safety/drills/shared';
import { Siren } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { DrillCommandFooter, useDrillCommandForm } from './drill-command-form';
import { DrillTimeField } from './drill-time-field';
import type { DrillPickers, DrillWorkspaceDetail } from './types';

/** G4-12: the three drill types drawn as tiles; the rest open from "More types…". */
const TILE_TYPES = ['fire_evacuation', 'earthquake', 'lockdown'] as const;

export function DrillPlanningForm({
    detail,
    pickers,
    mode,
    returnTo,
    onSaved,
    onCancel,
    popup = false,
    initialSiteId = null,
}: {
    detail: DrillWorkspaceDetail | null;
    pickers: DrillPickers;
    mode: 'create' | 'edit' | 'schedule-next';
    returnTo: string;
    onSaved: () => void;
    onCancel: () => void;
    /** G4-12: show the form as the kit's pop-up (the register's "Plan a drill"). */
    popup?: boolean;
    /** G4-12: a lead row's "Plan a drill" starts at that row's Site. */
    initialSiteId?: number | null;
}) {
    const edit = mode === 'edit';
    const { form, guards, save, failure, setFailure } = useDrillCommandForm(
        {
            site_id: detail?.site ? String(detail.site.id) : initialSiteId ? String(initialSiteId) : '',
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
    const [moreTypes, setMoreTypes] = useState(false);
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
                    'Staff options could not be loaded for this Site. Try again before planning.',
                );
            const options = (await response.json()) as DrillPickers;
            if (!Array.isArray(options.staff))
                throw new Error(
                    'Staff options could not be confirmed. Try again before planning.',
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
    // A lead row's "Plan a drill" opens at its Site: load that Site's staff once.
    const firstSite = useRef(!detail && initialSiteId ? String(initialSiteId) : '');
    useEffect(() => {
        if (firstSite.current) void loadSite(firstSite.current);
        // eslint-disable-next-line react-hooks/exhaustive-deps -- once, for the Site the form opened with
    }, []);
    const allowed =
        mode === 'create' ||
        (edit ? detail?.can.edit : detail?.can.schedule_next);
    const label = edit
        ? 'Save planning changes'
        : mode === 'schedule-next'
          ? 'Plan the next drill'
          : 'Plan a drill';
    if (!allowed)
        return (
            <p role="alert">
                This drill no longer allows this planning change.
            </p>
        );
    const submit = () => {
                if (loading || staffError || form.processing) return;
                const instant = actualEventInstant(
                    form.data.scheduled_at,
                    form.data.time_offset,
                );
                if (!instant) {
                    setFailure(
                        'Choose the date and time the drill is planned for. When the clocks change that night, also choose which one.',
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
    };
    const types = pickers.drill_types.filter((value) => value !== 'fire');
    const tileTypes = moreTypes
        ? types
        : types.filter((value) => (TILE_TYPES as readonly string[]).includes(value) || value === form.data.drill_type);
    const fields = (
        <>
            {mode === 'schedule-next' && (
                <p className="text-subtle">
                    This plans a new drill at the same Site and keeps this
                    drill’s results and things to fix.
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
            {/* G4-12: drill type is a tile picker; "More types…" is a text button, not a type. */}
            <fieldset className="space-y-2">
                <legend className="mb-2 text-sm font-medium">
                    Drill type <span className="text-status-critical">*</span>
                </legend>
                <div role="radiogroup" aria-label="Drill type" className="flex flex-wrap items-center gap-2">
                    {tileTypes.map((value) => {
                        const meta = typeMeta(value);
                        const Icon = meta.icon;
                        const picked = form.data.drill_type === value;
                        return (
                            // eslint-disable-next-line no-restricted-syntax -- a tile-picker choice (DESIGN.md tile pickers), not a form button.
                            <button
                                key={value}
                                type="button"
                                role="radio"
                                aria-checked={picked}
                                onClick={() => form.setData('drill_type', value)}
                                className={cn(
                                    'frontline-tap inline-flex items-center gap-2 rounded-lg border px-3 text-sm font-medium outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                    picked
                                        ? 'border-primary bg-accent text-primary'
                                        : 'border-border bg-card text-foreground hover:bg-muted',
                                )}
                            >
                                <Icon className="size-4" aria-hidden="true" />
                                {meta.label}
                            </button>
                        );
                    })}
                    {!moreTypes && types.length > tileTypes.length && (
                        <Button
                            type="button"
                            variant="link"
                            className="frontline-tap px-1"
                            onClick={() => setMoreTypes(true)}
                        >
                            More types…
                        </Button>
                    )}
                </div>
            </fieldset>
            <DrillTimeField
                pickers
                required
                label="Planned for"
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
            <Field label="What happens in the drill">
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
        </>
    );
    // G4-12: from the register, "Plan a drill" is the kit's 720 pop-up; on a drill's page it stays in place.
    if (popup)
        return (
            <PopupForm
                open
                onClose={onCancel}
                size="md"
                icon={Siren}
                title={label}
                description="The drill, when it runs and who takes part"
                error={failure ?? (Object.keys(form.errors).length ? Object.values(form.errors).join(' ') : undefined)}
                submitLabel={label}
                processingLabel="Saving…"
                processing={form.processing}
                submitDisabled={loading || !!staffError}
                onSubmit={submit}
                dirty={form.isDirty}
                draftName="this drill plan"
            >
                <div className="space-y-5">{fields}</div>
            </PopupForm>
        );
    return (
        <form
            className="space-y-5"
            onSubmit={(event) => {
                event.preventDefault();
                submit();
            }}
        >
            <h2 className="text-section-title">
                {edit ? 'Edit drill planning' : label}
            </h2>
            {fields}
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
