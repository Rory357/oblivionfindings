import { actualEventInstant } from '@/components/clinical/actual-event-time-field';
import { DrillTimeField } from '@/components/health-safety/drills/drill-time-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import { useSafetyDraftState } from '@/hooks/use-safety-workspace-drafts';
import {
    formatDateTimeLong,
    toDatetimeLocal,
    workerTimeOffset,
} from '@/lib/datetime';
import { loneWorkerHref } from '@/lib/health-safety/lone-worker-navigation';
import { Link } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useRef, useState } from 'react';
import {
    PlannedLocationFields,
    plannedLocationDraft,
    plannedLocationInput,
} from './planned-location-fields';
import type { SessionPickers } from './types';

type SessionStartProps = {
    pickers: SessionPickers;
    own?: boolean;
    returnTo: string;
    onCancel: () => void;
};

export function SessionStartForm(props: SessionStartProps) {
    const [generation, setGeneration] = useState(0);
    const [freshPickers, setFreshPickers] = useState<SessionPickers | null>(
        null,
    );
    const [refreshing, setRefreshing] = useState(false);
    const [refreshError, setRefreshError] = useState<string | null>(null);
    const request = useRef<AbortController | null>(null);
    useEffect(() => () => request.current?.abort(), []);
    return (
        <SessionStartEntry
            key={generation}
            {...props}
            pickers={freshPickers ?? props.pickers}
            refreshing={refreshing}
            refreshError={refreshError}
            onAnother={async () => {
                if (refreshing) return;
                const controller = new AbortController();
                request.current = controller;
                setRefreshing(true);
                setRefreshError(null);
                try {
                    const response = await axios.get(
                        '/health-safety/lone-workers/options',
                        {
                            signal: controller.signal,
                            headers: { Accept: 'application/json' },
                        },
                    );
                    if (controller.signal.aborted) return;
                    const options = response.data;
                    if (!options?.can?.start) {
                        setRefreshError(
                            'Starting another session is not currently available. You can still open the session you saved.',
                        );
                        return;
                    }
                    if (
                        !Array.isArray(options.pickers?.sites) ||
                        !Array.isArray(options.pickers?.workers) ||
                        !Array.isArray(options.pickers?.clients) ||
                        !Array.isArray(options.pickers?.shifts)
                    )
                        throw new Error('Options unavailable');
                    setFreshPickers(options.pickers);
                    setGeneration((value) => value + 1);
                } catch {
                    if (!controller.signal.aborted)
                        setRefreshError(
                            'The next form could not be loaded. Your saved session is recorded. Try Start another session again.',
                        );
                } finally {
                    if (!controller.signal.aborted) setRefreshing(false);
                }
            }}
        />
    );
}

function SessionStartEntry({
    pickers,
    own = false,
    returnTo,
    onCancel,
    onAnother,
    refreshing,
    refreshError,
}: SessionStartProps & {
    onAnother: () => void;
    refreshing: boolean;
    refreshError: string | null;
}) {
    const [key] = useState(() => crypto.randomUUID());
    const [initial] = useState(() => {
        const end = new Date(Date.now() + 60 * 60_000);
        return {
            shift_id: '',
            user_id: '',
            site_id: '',
            client_id: '',
            expected_end_at: toDatetimeLocal(end),
            time_offset: workerTimeOffset(end.toISOString()),
            check_in_interval_minutes: '60',
            activity_description: '',
            location: '',
            planned_location: plannedLocationDraft(),
        };
    });
    const [data, setData] = useState(initial);
    const [busy, setBusy] = useState(false);
    const [saved, setSaved] = useState(false);
    const [createdId, setCreatedId] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);
    useSafetyDraftState({
        dirty: !saved && JSON.stringify(data) !== JSON.stringify(initial),
        busy,
    });
    const shift = pickers.shifts.find(
        (item) => String(item.id) === data.shift_id,
    );
    const site = pickers.sites.find((item) => String(item.id) === data.site_id);
    const chooseShift = (value: string) => {
        const selected = pickers.shifts.find(
            (item) => String(item.id) === value,
        );
        setData((current) => ({
            ...current,
            shift_id: value,
            user_id: selected ? String(selected.user_id) : '',
            site_id: selected ? String(selected.site_id) : '',
            client_id: selected?.client_id ? String(selected.client_id) : '',
            location: selected?.address ?? selected?.location ?? '',
            planned_location: plannedLocationDraft(),
            expected_end_at: selected?.ends_at
                ? toDatetimeLocal(selected.ends_at)
                : current.expected_end_at,
            time_offset: selected?.ends_at
                ? workerTimeOffset(selected.ends_at)
                : current.time_offset,
        }));
    };
    if (createdId)
        return (
            <div className="space-y-4">
                <h2 className="text-section-title">Safety session started</h2>
                <p role="status" className="text-sm">
                    LW-{createdId} is recorded. Open the session to review its
                    check-ins and response.
                </p>
                <Button asChild className="frontline-tap">
                    <Link href={loneWorkerHref(createdId, { returnTo })}>
                        Open safety session
                    </Link>
                </Button>
                {!own && (
                    <Button
                        type="button"
                        variant="outline"
                        className="frontline-tap"
                        onClick={onAnother}
                        disabled={refreshing}
                    >
                        {refreshing
                            ? 'Loading next form…'
                            : 'Start another session'}
                    </Button>
                )}
                {refreshError && (
                    <p role="alert" className="text-sm text-status-critical">
                        {refreshError}
                    </p>
                )}
            </div>
        );
    return (
        <form
            className="space-y-5"
            onSubmit={async (event) => {
                event.preventDefault();
                if (busy) return;
                setError(null);
                const plan = plannedLocationInput(data.planned_location);
                if (!plan.ok) {
                    setError(plan.error);
                    return;
                }
                const instant = actualEventInstant(
                    data.expected_end_at,
                    data.time_offset,
                );
                if (
                    !instant ||
                    (own && !shift) ||
                    (!own && (!data.site_id || !data.user_id))
                ) {
                    setError(
                        'Choose the worker and approved Site or active shift, and a valid New Zealand expected end.',
                    );
                    return;
                }
                setBusy(true);
                try {
                    // JSON follows the canonical redirect without replacing this form.
                    // Navigate only after the exact source command proof is received.
                    const response = await axios.post(
                        `/health-safety/lone-workers/sessions${own ? '/start-own' : ''}`,
                        {
                            idempotency_key: key,
                            return_to: returnTo,
                            expected_end_at: instant,
                            shift_id: data.shift_id
                                ? Number(data.shift_id)
                                : null,
                            ...(!own
                                ? {
                                      user_id: Number(data.user_id),
                                      site_id: Number(data.site_id),
                                      client_id: data.client_id
                                          ? Number(data.client_id)
                                          : null,
                                  }
                                : {}),
                            check_in_interval_minutes: Number(
                                data.check_in_interval_minutes,
                            ),
                            activity_description:
                                data.activity_description || null,
                            location: data.location || null,
                            planned_location: plan.value,
                        },
                        { headers: { Accept: 'application/json' } },
                    );
                    const proof = response.data?.lone_worker_confirmation;
                    const record = response.data?.detail;
                    if (
                        !proof ||
                        proof.idempotency_key !== key ||
                        proof.command !== 'start' ||
                        proof.lock_version !== 1 ||
                        !Number.isSafeInteger(proof.id) ||
                        proof.id < 1 ||
                        record?.id !== proof.id
                    ) {
                        setError(
                            'Starting this session could not be confirmed. Your entries are still here. Check your sessions before retrying.',
                        );
                        return;
                    }
                    setSaved(true);
                    setCreatedId(proof.id);
                } catch (caught) {
                    const errors = axios.isAxiosError(caught)
                        ? caught.response?.data?.errors
                        : null;
                    const message =
                        errors && typeof errors === 'object'
                            ? Object.values(errors)
                                  .flat()
                                  .filter((value) => typeof value === 'string')
                                  .join(' ')
                            : '';
                    setError(
                        message ||
                            'The session could not be confirmed. Your entries are still here. Check your connection and try again.',
                    );
                } finally {
                    setBusy(false);
                }
            }}
        >
            <h2 className="text-section-title">Start safety session</h2>
            <Field label="Linked shift" required={own}>
                <SelectInput
                    frontline
                    value={data.shift_id || (own ? '' : 'standalone')}
                    onChange={(value) =>
                        chooseShift(value === 'standalone' ? '' : value)
                    }
                    placeholder="Choose an active shift"
                    options={[
                        ...(!own
                            ? [
                                  {
                                      value: 'standalone',
                                      label: 'Standalone session',
                                  },
                              ]
                            : []),
                        ...pickers.shifts.map((item) => ({
                            value: String(item.id),
                            label: `${item.worker?.name ?? 'Your shift'} · ${pickers.sites.find((site) => site.id === item.site_id)?.name ?? 'Approved Site'} · Ends ${formatDateTimeLong(item.ends_at, 'not recorded')}`,
                        })),
                    ]}
                />
            </Field>
            {own && pickers.shifts.length === 0 && (
                <p role="status" className="text-sm">
                    No eligible active shift is available. Start your assigned
                    shift before starting its safety session.
                </p>
            )}
            {!own && (
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Site" required>
                        {shift ? (
                            <Input
                                className="frontline-tap"
                                readOnly
                                value={
                                    pickers.sites.find(
                                        (site) => site.id === shift.site_id,
                                    )?.name ?? 'Approved Site'
                                }
                            />
                        ) : (
                            <SelectInput
                                frontline
                                value={data.site_id}
                                placeholder="Choose an approved Site"
                                onChange={(value) =>
                                    setData((current) => ({
                                        ...current,
                                        site_id: value,
                                        user_id: '',
                                        client_id: '',
                                        location:
                                            pickers.sites.find(
                                                (site) =>
                                                    String(site.id) === value,
                                            )?.address ?? '',
                                        planned_location:
                                            plannedLocationDraft(),
                                    }))
                                }
                                options={pickers.sites.map((site) => ({
                                    value: String(site.id),
                                    label: site.name,
                                }))}
                            />
                        )}
                    </Field>
                    <Field label="Worker" required>
                        {shift ? (
                            <Input
                                className="frontline-tap"
                                readOnly
                                value={shift.worker?.name ?? 'Assigned worker'}
                            />
                        ) : (
                            <SelectInput
                                frontline
                                value={data.user_id}
                                placeholder="Choose the worker"
                                onChange={(value) =>
                                    setData((current) => ({
                                        ...current,
                                        user_id: value,
                                    }))
                                }
                                options={pickers.workers
                                    .filter((worker) =>
                                        worker.site_ids.includes(
                                            Number(data.site_id),
                                        ),
                                    )
                                    .map((worker) => ({
                                        value: String(worker.id),
                                        label: worker.name,
                                    }))}
                            />
                        )}
                    </Field>
                    {!shift && (
                        <Field label="Client">
                            <SelectInput
                                frontline
                                value={data.client_id || 'none'}
                                placeholder="Choose a client if relevant"
                                onChange={(value) =>
                                    setData((current) => ({
                                        ...current,
                                        client_id:
                                            value === 'none' ? '' : value,
                                    }))
                                }
                                options={[
                                    { value: 'none', label: 'No client' },
                                    ...pickers.clients
                                        .filter(
                                            (client) =>
                                                client.site_id ===
                                                Number(data.site_id),
                                        )
                                        .map((client) => ({
                                            value: String(client.id),
                                            label: client.name,
                                        })),
                                ]}
                            />
                        </Field>
                    )}
                </div>
            )}
            <DrillTimeField
                label="Expected end"
                value={data.expected_end_at}
                offset={data.time_offset}
                onChange={(value, offset) =>
                    setData((current) => ({
                        ...current,
                        expected_end_at: value,
                        time_offset: offset,
                    }))
                }
            />
            <Field label="Check-in interval (minutes)" required>
                <Input
                    type="number"
                    className="frontline-tap"
                    min={15}
                    max={480}
                    step={1}
                    required
                    value={data.check_in_interval_minutes}
                    onChange={(event) =>
                        setData((current) => ({
                            ...current,
                            check_in_interval_minutes: event.target.value,
                        }))
                    }
                />
            </Field>
            <Field label="Activity">
                <Textarea
                    className="min-h-28"
                    maxLength={2000}
                    value={data.activity_description}
                    onChange={(event) =>
                        setData((current) => ({
                            ...current,
                            activity_description: event.target.value,
                        }))
                    }
                />
            </Field>
            <Field label="Location description">
                <Textarea
                    className="min-h-24"
                    maxLength={500}
                    value={data.location}
                    onChange={(event) =>
                        setData((current) => ({
                            ...current,
                            location: event.target.value,
                        }))
                    }
                />
            </Field>
            <p className="text-subtle">
                Starting sets the first checkpoint. A separate check-in records
                contact with the worker. Starting or ending monitoring does not
                change attendance.
            </p>
            <PlannedLocationFields
                value={data.planned_location}
                onChange={(planned_location) =>
                    setData((current) => ({ ...current, planned_location }))
                }
                site={site}
                shift={shift}
                canGeocode={pickers.can_geocode}
            />
            {error && (
                <p role="alert" className="text-sm text-status-critical">
                    {error}
                </p>
            )}
            <div className="flex flex-wrap gap-3">
                <Button
                    className="frontline-tap"
                    type="submit"
                    disabled={busy || (own && !pickers.shifts.length)}
                >
                    {busy ? 'Starting…' : 'Start session'}
                </Button>
                <Button
                    className="frontline-tap"
                    type="button"
                    variant="outline"
                    disabled={busy}
                    onClick={onCancel}
                >
                    Cancel
                </Button>
            </div>
        </form>
    );
}
