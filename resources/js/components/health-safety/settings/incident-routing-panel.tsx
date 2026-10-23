import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import {
    useSafetyDraftState,
    useSafetyForm,
} from '@/hooks/use-safety-workspace-drafts';
import {
    formatDateTimeLong,
    toDatetimeLocal,
    workerTimeOffset,
    workerTimeOffsets,
} from '@/lib/datetime';
import axios from 'axios';
import {
    AlertCircle,
    CheckCircle2,
    Plus,
    Settings,
    Trash2,
} from 'lucide-react';
import { useEffect, useId, useMemo, useRef, useState } from 'react';
import {
    confirmsRoutingReceipt,
    type RoutingCommand,
    type RoutingHistory,
    type RoutingRule,
    type RoutingSnapshot,
} from './incident-routing';

const selectClass =
    'frontline-tap frontline-focus w-full rounded-md border border-input bg-background px-3 text-subtle';
type Site = { id: number; name: string };
type Person = { id: number; name: string };

function SavedPerson({
    siteId,
    scope,
    value,
    actorId,
    readKey,
    allowed,
}: {
    siteId: number;
    scope: 'ordinary' | 'restricted';
    value: number;
    actorId: number;
    readKey: string;
    allowed: boolean;
}) {
    const binding = `${readKey}/${actorId}/${siteId}/${scope}/${value}`;
    const [result, setResult] = useState<{
        binding: string;
        name: string | null;
    } | null>(null);
    useEffect(() => {
        if (!allowed) return;
        const abort = new AbortController();
        axios
            .get<{ candidates: Person[]; viewer_id: number }>(
                '/health-safety/settings/incident-routing/candidates',
                {
                    params: { site_id: siteId, scope, selected_id: value },
                    signal: abort.signal,
                },
            )
            .then((response) => {
                if (!abort.signal.aborted)
                    setResult({
                        binding,
                        name:
                            response.data.viewer_id === actorId
                                ? (response.data.candidates.find(
                                      (person) => person.id === value,
                                  )?.name ?? null)
                                : null,
                    });
            })
            .catch(() => {
                if (!abort.signal.aborted) setResult({ binding, name: null });
            });
        return () => abort.abort();
    }, [allowed, binding, siteId, scope, value, actorId]);
    return (
        <span>
            {!allowed
                ? 'Settings access unavailable'
                : result?.binding !== binding
                  ? 'Checking saved person…'
                  : (result.name ?? 'Current recipient needs review')}
        </span>
    );
}

/** Exact saved references and search both use the same current approved-Site source endpoint. */
function PersonField({
    label,
    siteId,
    scope,
    value,
    disabled,
    actorId,
    readKey,
    onChange,
}: {
    label: string;
    siteId: number;
    scope: 'ordinary' | 'restricted' | 'triage';
    value: number | null;
    disabled: boolean;
    actorId: number;
    readKey: string;
    onChange: (id: number | null) => void;
}) {
    const id = useId();
    const [search, setSearch] = useState('');
    const [options, setOptions] = useState<Person[]>([]);
    const [selected, setSelected] = useState<Person | null>(null);
    const [failed, setFailed] = useState(false);
    const [loading, setLoading] = useState(false);
    const [loadedBinding, setLoadedBinding] = useState<string | null>(null);
    const binding = `${readKey}/${actorId}/${siteId}/${scope}/${value ?? ''}`;
    useEffect(() => {
        setOptions([]);
        setSelected(null);
        setFailed(false);
        setLoadedBinding(null);
        if (disabled || !siteId) {
            setLoading(false);
            return;
        }
        const abort = new AbortController();
        setLoading(true);
        const timer = window.setTimeout(async () => {
            try {
                const params = { site_id: siteId, scope, search };
                const [list, saved] = await Promise.all([
                    axios.get<{ candidates: Person[]; viewer_id: number }>(
                        '/health-safety/settings/incident-routing/candidates',
                        { params, signal: abort.signal },
                    ),
                    value
                        ? axios.get<{
                              candidates: Person[];
                              viewer_id: number;
                          }>(
                              '/health-safety/settings/incident-routing/candidates',
                              {
                                  params: {
                                      site_id: siteId,
                                      scope,
                                      selected_id: value,
                                  },
                                  signal: abort.signal,
                              },
                          )
                        : Promise.resolve(null),
                ]);
                if (abort.signal.aborted) return;
                if (
                    list.data.viewer_id !== actorId ||
                    (saved && saved.data.viewer_id !== actorId)
                )
                    throw new Error('The current account changed.');
                setOptions(list.data.candidates);
                setSelected(
                    saved?.data.candidates.find(
                        (person) => person.id === value,
                    ) ?? null,
                );
                setLoadedBinding(binding);
            } catch {
                if (!abort.signal.aborted) {
                    setFailed(true);
                    setOptions([]);
                    setSelected(null);
                }
            } finally {
                if (!abort.signal.aborted) setLoading(false);
            }
        }, 200);
        return () => {
            window.clearTimeout(timer);
            abort.abort();
        };
    }, [disabled, siteId, scope, search, value, actorId, binding]);
    // Never print names retained by a request started in an earlier scope.
    const choices =
        disabled || loadedBinding !== binding
            ? []
            : selected && !options.some((person) => person.id === selected.id)
              ? [selected, ...options]
              : options;
    return (
        <div className="space-y-2">
            <label htmlFor={id} className="text-subtle font-medium">
                {label}
            </label>
            <Input
                aria-label={`Search for ${label.toLowerCase()}`}
                className="frontline-tap frontline-focus"
                value={search}
                maxLength={100}
                disabled={disabled || !siteId}
                placeholder="Search by name"
                onChange={(event) => setSearch(event.target.value)}
            />
            <select
                id={id}
                className={selectClass}
                disabled={disabled || !siteId || loading || failed}
                value={disabled ? '' : (value ?? '')}
                onChange={(event) =>
                    onChange(
                        event.target.value ? Number(event.target.value) : null,
                    )
                }
            >
                <option value="">Choose a person</option>
                {!disabled &&
                value &&
                !choices.some((person) => person.id === value) ? (
                    <option value={value}>
                        Saved person; current eligibility needs review
                    </option>
                ) : null}
                {choices.map((person) => (
                    <option key={person.id} value={person.id}>
                        {person.name}
                    </option>
                ))}
            </select>
            {loading ? (
                <p role="status" className="text-caption text-muted-foreground">
                    Checking eligible people…
                </p>
            ) : null}
            {failed ? (
                <p
                    role="alert"
                    className="text-caption text-status-warning-foreground"
                >
                    Eligible people could not be loaded. Your saved reference
                    has been retained. Change the search to try again.
                </p>
            ) : null}
        </div>
    );
}

/** The shared NZ helpers require an explicit choice at a repeated daylight-saving time. */
function InstantField({
    label,
    value,
    disabled,
    onChange,
}: {
    label: string;
    value: string | null;
    disabled: boolean;
    onChange: (value: string | null) => void;
}) {
    const id = useId();
    const hasOffset = !!value && /(?:Z|[+-]\d{2}:\d{2})$/.test(value);
    const local = !value
        ? ''
        : hasOffset
          ? toDatetimeLocal(value)
          : value.slice(0, 16);
    const offsets = local ? workerTimeOffsets(local) : [];
    return (
        <div className="space-y-2">
            <label htmlFor={id} className="text-subtle font-medium">
                {label}
            </label>
            <Input
                id={id}
                type="datetime-local"
                className="frontline-tap frontline-focus"
                value={local}
                disabled={disabled}
                onChange={(event) => {
                    const next = event.target.value;
                    const possible = workerTimeOffsets(next);
                    onChange(
                        next
                            ? `${next}:00${possible.length === 1 ? possible[0] : ''}`
                            : null,
                    );
                }}
            />
            {offsets.length === 2 ? (
                <label className="text-caption block space-y-2">
                    This time occurs twice. Choose which occurrence.
                    <select
                        aria-label={`${label} occurrence`}
                        className={selectClass}
                        disabled={disabled}
                        value={hasOffset ? workerTimeOffset(value!) : ''}
                        onChange={(event) =>
                            onChange(`${local}:00${event.target.value}`)
                        }
                    >
                        <option value="">Choose an occurrence</option>
                        {offsets.map((offset, index) => (
                            <option key={offset} value={offset}>
                                {index === 0 ? 'First' : 'Second'} occurrence (
                                {offset})
                            </option>
                        ))}
                    </select>
                </label>
            ) : local && offsets.length === 0 ? (
                <p
                    role="alert"
                    className="text-caption text-status-warning-foreground"
                >
                    This time does not exist in Pacific/Auckland. Choose another
                    time.
                </p>
            ) : null}
        </div>
    );
}

function initialCommand(snapshot: RoutingSnapshot): RoutingCommand {
    return {
        configuration: {
            schema_version: 1,
            routes:
                snapshot.configuration?.routes.map((rule) => ({ ...rule })) ??
                [],
        },
        expected_revision: snapshot.revision ?? 0,
        request_uuid: crypto.randomUUID(),
        reason: '',
    };
}

function RoutingEditor({
    snapshot,
    canManage,
    sites,
    actorId,
    readKey,
    discard,
    onClose,
}: {
    snapshot: RoutingSnapshot;
    canManage: boolean;
    sites: Site[];
    actorId: number;
    readKey: string;
    discard: (action: () => void) => void;
    onClose: (saved: boolean) => void;
}) {
    const [initial] = useState(() => initialCommand(snapshot));
    const form = useSafetyForm<RoutingCommand>(initial);
    const [uncertain, setUncertain] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [triageSite, setTriageSite] = useState(0);
    const active = useRef(true);
    const currentRequest = useRef({
        actorId,
        canManage,
        uuid: form.data.request_uuid,
    });
    currentRequest.current = {
        actorId,
        canManage,
        uuid: form.data.request_uuid,
    };
    useEffect(() => {
        active.current = true;
        return () => {
            active.current = false;
        };
    }, []);
    const blocked = form.processing || !canManage || snapshot.revision === null;
    useSafetyDraftState({
        dirty: form.isDirty,
        busy: form.processing,
        uncertain,
    });
    const change = (
        configuration: RoutingCommand['configuration'],
        reason = form.data.reason,
    ) => {
        if (blocked || uncertain) return;
        if (
            JSON.stringify(configuration) ===
                JSON.stringify(form.data.configuration) &&
            reason === form.data.reason
        )
            return;
        form.setData({
            ...form.data,
            configuration,
            reason,
            request_uuid: crypto.randomUUID(),
        });
        form.clearErrors();
        setMessage(null);
    };
    const ruleChange = (index: number, update: Partial<RoutingRule>) =>
        change({
            ...form.data.configuration,
            routes: form.data.configuration.routes.map((rule, position) =>
                position === index ? { ...rule, ...update } : rule,
            ),
        });
    const triage = form.data.configuration.triage;
    const triageMode =
        triage === undefined ? 'keep' : triage === null ? 'clear' : 'set';
    return (
        <form
            className="space-y-5"
            onSubmit={(event) => {
                event.preventDefault();
                if (blocked) return;
                const submitted = structuredClone(form.data);
                const submittingActor = actorId;
                const acceptsResponse = () =>
                    active.current &&
                    currentRequest.current.actorId === submittingActor &&
                    currentRequest.current.canManage &&
                    currentRequest.current.uuid === submitted.request_uuid;
                let returned = false;
                setMessage(null);
                setUncertain(true);
                form.put('/health-safety/settings/incident-routing', {
                    preserveScroll: true,
                    onSuccess: (page) => {
                        if (!acceptsResponse()) return;
                        returned = true;
                        const responseActor = page.props.auth as
                            | { user?: { id?: number } }
                            | undefined;
                        if (
                            page.props.routing_viewer_id === submittingActor &&
                            responseActor?.user?.id === submittingActor &&
                            confirmsRoutingReceipt(
                                page.props.health_safety_routing_receipt,
                                submitted,
                                actorId,
                            )
                        ) {
                            setUncertain(false);
                            onClose(true);
                        } else {
                            setUncertain(true);
                            setMessage(
                                'The routing save could not be confirmed. Your entries and original request are retained; retry unchanged to check the same submission.',
                            );
                        }
                    },
                    onError: () => {
                        if (!acceptsResponse()) return;
                        returned = true;
                        setUncertain(false);
                        setMessage(
                            'Routing was not confirmed. Review the errors; your entries have been retained.',
                        );
                    },
                    onFinish: () => {
                        if (!acceptsResponse()) return;
                        if (!returned) {
                            setUncertain(true);
                            setMessage(
                                'The response was interrupted. The save may already be recorded. Your original request is retained; retry unchanged to confirm it.',
                            );
                        }
                    },
                });
            }}
        >
            <fieldset
                disabled={blocked || uncertain}
                className="min-w-0 space-y-5"
            >
                <div className="space-y-2">
                    <h3 className="text-section-title">
                        Edit incident routing
                    </h3>
                    <p className="text-subtle text-muted-foreground">
                        Times use Pacific/Auckland. Rules apply from their start
                        up to, but excluding, their end. Rules at sites outside
                        your current access are kept unchanged.
                    </p>
                </div>
                {!canManage ? (
                    <p
                        role="alert"
                        className="text-subtle text-status-warning-foreground"
                    >
                        Your settings access has changed. Editing is disabled;
                        your entries remain in this tab.
                    </p>
                ) : null}
                {snapshot.revision !== form.data.expected_revision ? (
                    <p
                        role="alert"
                        className="text-subtle text-status-warning-foreground"
                    >
                        The saved routing revision has changed. Your entries
                        remain; review the latest saved settings before starting
                        again.
                    </p>
                ) : null}
                {form.data.configuration.routes.map((rule, index) => (
                    <fieldset
                        key={index}
                        disabled={blocked}
                        className="space-y-4 rounded-lg border p-4"
                    >
                        <legend className="text-subtle px-1 font-medium">
                            Routing rule {index + 1}
                        </legend>
                        <div className="grid gap-4 md:grid-cols-2">
                            <label className="text-subtle space-y-2">
                                Site
                                <select
                                    className={selectClass}
                                    value={rule.site_id || ''}
                                    onChange={(event) =>
                                        ruleChange(index, {
                                            site_id: Number(event.target.value),
                                            lead_user_id: 0,
                                            cover_user_id: null,
                                            cover_from: null,
                                            cover_until: null,
                                        })
                                    }
                                >
                                    <option value="">Choose a site</option>
                                    {sites.map((site) => (
                                        <option key={site.id} value={site.id}>
                                            {site.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="text-subtle space-y-2">
                                Incident route
                                <select
                                    className={selectClass}
                                    value={rule.scope}
                                    onChange={(event) =>
                                        ruleChange(index, {
                                            scope: event.target
                                                .value as RoutingRule['scope'],
                                            lead_user_id: 0,
                                            cover_user_id: null,
                                            cover_from: null,
                                            cover_until: null,
                                        })
                                    }
                                >
                                    <option value="ordinary">
                                        Ordinary incidents
                                    </option>
                                    <option value="restricted">
                                        Restricted or specialist incidents
                                    </option>
                                </select>
                            </label>
                            <PersonField
                                label="Incident lead"
                                siteId={rule.site_id}
                                scope={rule.scope}
                                value={rule.lead_user_id}
                                disabled={
                                    blocked ||
                                    !sites.some(
                                        (site) => site.id === rule.site_id,
                                    )
                                }
                                actorId={actorId}
                                readKey={readKey}
                                onChange={(id) =>
                                    ruleChange(index, { lead_user_id: id ?? 0 })
                                }
                            />
                            <PersonField
                                label="Cover person"
                                siteId={rule.site_id}
                                scope={rule.scope}
                                value={rule.cover_user_id}
                                disabled={
                                    blocked ||
                                    !sites.some(
                                        (site) => site.id === rule.site_id,
                                    )
                                }
                                actorId={actorId}
                                readKey={readKey}
                                onChange={(id) =>
                                    ruleChange(index, {
                                        cover_user_id: id,
                                        ...(id === null
                                            ? {
                                                  cover_from: null,
                                                  cover_until: null,
                                              }
                                            : {}),
                                    })
                                }
                            />
                            <InstantField
                                label="Rule starts"
                                value={rule.effective_from}
                                disabled={blocked}
                                onChange={(value) =>
                                    ruleChange(index, {
                                        effective_from: value ?? '',
                                    })
                                }
                            />
                            <InstantField
                                label="Rule ends (optional)"
                                value={rule.effective_until}
                                disabled={blocked}
                                onChange={(value) =>
                                    ruleChange(index, {
                                        effective_until: value,
                                    })
                                }
                            />
                            {rule.cover_user_id ? (
                                <>
                                    <InstantField
                                        label="Cover starts"
                                        value={rule.cover_from}
                                        disabled={blocked}
                                        onChange={(value) =>
                                            ruleChange(index, {
                                                cover_from: value,
                                            })
                                        }
                                    />
                                    <InstantField
                                        label="Cover ends"
                                        value={rule.cover_until}
                                        disabled={blocked}
                                        onChange={(value) =>
                                            ruleChange(index, {
                                                cover_until: value,
                                            })
                                        }
                                    />
                                </>
                            ) : null}
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            className="frontline-tap frontline-focus"
                            onClick={() =>
                                change({
                                    ...form.data.configuration,
                                    routes: form.data.configuration.routes.filter(
                                        (_, position) => position !== index,
                                    ),
                                })
                            }
                        >
                            <Trash2 aria-hidden className="size-4" />
                            Remove rule
                        </Button>
                    </fieldset>
                ))}
                <Button
                    type="button"
                    variant="outline"
                    disabled={
                        blocked || form.data.configuration.routes.length >= 500
                    }
                    className="frontline-tap frontline-focus"
                    onClick={() =>
                        change({
                            ...form.data.configuration,
                            routes: [
                                ...form.data.configuration.routes,
                                {
                                    site_id: 0,
                                    scope: 'ordinary',
                                    lead_user_id: 0,
                                    effective_from: '',
                                    effective_until: null,
                                    cover_user_id: null,
                                    cover_from: null,
                                    cover_until: null,
                                },
                            ],
                        })
                    }
                >
                    <Plus aria-hidden className="size-4" />
                    Add routing rule
                </Button>
                <fieldset
                    disabled={blocked}
                    className="space-y-4 rounded-lg border p-4"
                >
                    <legend className="text-subtle px-1 font-medium">
                        Monitored triage and escalation
                    </legend>
                    <p className="text-subtle text-muted-foreground">
                        Triage receives incidents that need an owner. It does
                        not become the incident owner. Escalation uses the
                        interval you explicitly configure.
                    </p>
                    <label className="text-subtle block space-y-2">
                        Triage decision
                        <select
                            className={selectClass}
                            value={triageMode}
                            onChange={(event) => {
                                const config = { ...form.data.configuration };
                                if (event.target.value === 'keep')
                                    delete config.triage;
                                else
                                    config.triage =
                                        event.target.value === 'clear'
                                            ? null
                                            : snapshot.configuration?.triage
                                                    .visibility ===
                                                    'readable' &&
                                                snapshot.configuration.triage
                                                    .values
                                              ? {
                                                    ...snapshot.configuration
                                                        .triage.values,
                                                }
                                              : {
                                                    user_id: 0,
                                                    escalation_user_id: 0,
                                                    escalation_after_minutes: 0,
                                                };
                                change(config);
                            }}
                        >
                            <option value="keep">
                                Keep saved triage unchanged
                            </option>
                            <option value="set">
                                Choose triage and escalation
                            </option>
                            <option value="clear">
                                Clear configured triage
                            </option>
                        </select>
                    </label>
                    {snapshot.configuration?.triage.visibility ===
                    'restricted' ? (
                        <p className="text-subtle text-muted-foreground">
                            Saved triage references are restricted. Keeping them
                            unchanged preserves them. Replacing or clearing them
                            requires independent organisation site access.
                        </p>
                    ) : null}
                    {triageMode === 'clear' ? (
                        <p
                            role="alert"
                            className="text-subtle text-status-warning-foreground"
                        >
                            Eligible Site leads and cover can still receive
                            incidents. Reports without an eligible owner will
                            need setup review and will have no monitored triage
                            or escalation fallback.
                        </p>
                    ) : null}
                    {triage ? (
                        <>
                            <label className="text-subtle block space-y-2">
                                Site for recipient selection
                                <select
                                    className={selectClass}
                                    value={triageSite || ''}
                                    onChange={(event) =>
                                        setTriageSite(
                                            Number(event.target.value),
                                        )
                                    }
                                >
                                    <option value="">
                                        Choose an approved site
                                    </option>
                                    {sites.map((site) => (
                                        <option key={site.id} value={site.id}>
                                            {site.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <div className="grid gap-4 md:grid-cols-2">
                                <PersonField
                                    label="Triage coordinator"
                                    siteId={triageSite}
                                    scope="triage"
                                    value={triage.user_id}
                                    disabled={
                                        blocked ||
                                        !sites.some(
                                            (site) => site.id === triageSite,
                                        )
                                    }
                                    actorId={actorId}
                                    readKey={readKey}
                                    onChange={(id) =>
                                        change({
                                            ...form.data.configuration,
                                            triage: {
                                                ...triage,
                                                user_id: id ?? 0,
                                            },
                                        })
                                    }
                                />
                                <PersonField
                                    label="Escalation recipient"
                                    siteId={triageSite}
                                    scope="triage"
                                    value={triage.escalation_user_id}
                                    disabled={
                                        blocked ||
                                        !sites.some(
                                            (site) => site.id === triageSite,
                                        )
                                    }
                                    actorId={actorId}
                                    readKey={readKey}
                                    onChange={(id) =>
                                        change({
                                            ...form.data.configuration,
                                            triage: {
                                                ...triage,
                                                escalation_user_id: id ?? 0,
                                            },
                                        })
                                    }
                                />
                                <label className="text-subtle space-y-2">
                                    Escalate after (minutes)
                                    <Input
                                        className="frontline-tap frontline-focus"
                                        type="number"
                                        min={1}
                                        step={1}
                                        value={
                                            triage.escalation_after_minutes ||
                                            ''
                                        }
                                        onChange={(event) =>
                                            change({
                                                ...form.data.configuration,
                                                triage: {
                                                    ...triage,
                                                    escalation_after_minutes:
                                                        Number(
                                                            event.target.value,
                                                        ),
                                                },
                                            })
                                        }
                                    />
                                </label>
                            </div>
                        </>
                    ) : null}
                </fieldset>
                <label className="text-subtle block space-y-2">
                    Reason for this organisation decision
                    <Textarea
                        className="frontline-focus"
                        disabled={blocked}
                        value={form.data.reason}
                        required
                        maxLength={4000}
                        onChange={(event) =>
                            change(form.data.configuration, event.target.value)
                        }
                    />
                </label>
                {Object.values(form.errors).length ? (
                    <ul
                        role="alert"
                        className="text-subtle space-y-1 text-status-warning-foreground"
                    >
                        {Object.entries(form.errors).map(([field, error]) => (
                            <li key={field}>{error}</li>
                        ))}
                    </ul>
                ) : null}
                {message ? (
                    <p
                        role="alert"
                        className="text-subtle text-status-warning-foreground"
                    >
                        {message}
                    </p>
                ) : null}
            </fieldset>
            <div className="flex flex-wrap gap-3">
                <Button
                    type="submit"
                    className="frontline-tap frontline-focus"
                    disabled={blocked}
                >
                    {form.processing ? 'Saving…' : 'Save routing'}
                </Button>
                <Button
                    type="button"
                    className="frontline-tap frontline-focus"
                    variant="outline"
                    disabled={form.processing}
                    onClick={() => discard(() => onClose(false))}
                >
                    Cancel editing
                </Button>
            </div>
        </form>
    );
}

type IncidentRoutingPanelProps = {
    snapshot: RoutingSnapshot;
    history: RoutingHistory;
    canManage: boolean;
    sites: Site[];
    actorId: number;
    discard: (action: () => void) => void;
};

export function IncidentRoutingPanel(props: IncidentRoutingPanelProps) {
    return <ActorRoutingPanel key={props.actorId} {...props} />;
}

function ActorRoutingPanel({
    snapshot,
    history,
    canManage,
    sites,
    actorId,
    discard,
}: IncidentRoutingPanelProps) {
    const [editing, setEditing] = useState(false);
    const [saved, setSaved] = useState(false);
    const readKey = useMemo(
        () => crypto.randomUUID(),
        [snapshot, sites, actorId, canManage],
    );
    return (
        <section aria-label="Incident routing" className="space-y-5">
            <Card>
                <CardContent className="space-y-5 p-5">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="space-y-2">
                            <h2 className="text-section-title">
                                Incident ownership routing
                            </h2>
                            <StatusBadge
                                variant={
                                    snapshot.state === 'configured'
                                        ? 'info'
                                        : 'warning'
                                }
                            >
                                {snapshot.state === 'configured' ? (
                                    <CheckCircle2
                                        aria-hidden
                                        className="size-3"
                                    />
                                ) : (
                                    <AlertCircle
                                        aria-hidden
                                        className="size-3"
                                    />
                                )}
                                {snapshot.state === 'configured'
                                    ? 'Configuration recorded'
                                    : snapshot.state === 'unconfigured'
                                      ? 'Not configured'
                                      : 'Configuration needs repair'}
                            </StatusBadge>
                        </div>
                        <Button
                            variant="outline"
                            className="frontline-tap frontline-focus"
                            disabled={
                                !canManage ||
                                snapshot.revision === null ||
                                editing
                            }
                            onClick={() => {
                                setSaved(false);
                                setEditing(true);
                            }}
                        >
                            <Settings aria-hidden className="size-4" />
                            Edit routing
                        </Button>
                    </div>
                    <p className="text-subtle text-muted-foreground">
                        New reports use the incident’s recorded site and any
                        required specialist route. Named cover applies only for
                        its saved interval. Existing incident owners are
                        preserved; changing a task assignee does not change the
                        incident owner.
                    </p>
                    <p className="text-subtle text-muted-foreground">
                        A recorded configuration does not confirm current
                        staffing, access or complete site coverage. Each
                        incident rechecks those conditions. An unavailable route
                        keeps the report saved and shows that it needs an owner
                        or setup review.
                    </p>
                    {saved ? (
                        <p role="status" className="text-subtle">
                            Routing configuration saved.
                        </p>
                    ) : null}
                    {snapshot.recorded_at ? (
                        <p className="text-caption text-muted-foreground">
                            Revision {snapshot.revision} ·{' '}
                            {formatDateTimeLong(snapshot.recorded_at)}
                        </p>
                    ) : null}
                    {snapshot.configuration ? (
                        <div className="space-y-3">
                            <h3 className="text-section-title">
                                Saved rules in your approved sites
                            </h3>
                            {snapshot.configuration.routes.length ? (
                                <ul className="space-y-3">
                                    {snapshot.configuration.routes.map(
                                        (rule, index) => (
                                            <li
                                                key={index}
                                                className="text-subtle rounded-lg border p-3"
                                            >
                                                <p className="font-medium">
                                                    {sites.find(
                                                        (site) =>
                                                            site.id ===
                                                            rule.site_id,
                                                    )?.name ??
                                                        'Site access needs review'}{' '}
                                                    ·{' '}
                                                    {rule.scope === 'restricted'
                                                        ? 'Restricted or specialist incidents'
                                                        : 'Ordinary incidents'}
                                                </p>
                                                <p>
                                                    Incident lead:{' '}
                                                    <SavedPerson
                                                        siteId={rule.site_id}
                                                        scope={rule.scope}
                                                        value={
                                                            rule.lead_user_id
                                                        }
                                                        actorId={actorId}
                                                        readKey={readKey}
                                                        allowed={
                                                            canManage &&
                                                            sites.some(
                                                                (site) =>
                                                                    site.id ===
                                                                    rule.site_id,
                                                            )
                                                        }
                                                    />
                                                </p>
                                                {rule.cover_user_id ? (
                                                    <p>
                                                        Cover person:{' '}
                                                        <SavedPerson
                                                            siteId={
                                                                rule.site_id
                                                            }
                                                            scope={rule.scope}
                                                            value={
                                                                rule.cover_user_id
                                                            }
                                                            actorId={actorId}
                                                            readKey={readKey}
                                                            allowed={
                                                                canManage &&
                                                                sites.some(
                                                                    (site) =>
                                                                        site.id ===
                                                                        rule.site_id,
                                                                )
                                                            }
                                                        />
                                                    </p>
                                                ) : null}
                                                <p className="text-caption text-muted-foreground">
                                                    {formatDateTimeLong(
                                                        rule.effective_from,
                                                    )}{' '}
                                                    to{' '}
                                                    {rule.effective_until
                                                        ? formatDateTimeLong(
                                                              rule.effective_until,
                                                          )
                                                        : 'no end recorded'}
                                                    {rule.cover_user_id
                                                        ? ' · Named cover recorded'
                                                        : ''}
                                                </p>
                                            </li>
                                        ),
                                    )}
                                </ul>
                            ) : (
                                <p className="text-subtle text-muted-foreground">
                                    No routing rules are recorded for your
                                    current approved sites.
                                </p>
                            )}
                            <p className="text-subtle">
                                {snapshot.configuration.triage.visibility ===
                                'restricted'
                                    ? 'Monitored triage references are restricted.'
                                    : snapshot.configuration.triage.values
                                      ? 'Triage and escalation references are recorded; current eligibility is checked per incident.'
                                      : 'Monitored triage is not configured.'}
                            </p>
                            {canManage &&
                            snapshot.configuration.triage.visibility ===
                                'readable' &&
                            snapshot.configuration.triage.display_names ? (
                                <dl className="text-subtle grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Triage coordinator
                                        </dt>
                                        <dd>
                                            {
                                                snapshot.configuration.triage
                                                    .display_names.user
                                            }
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Escalation recipient
                                        </dt>
                                        <dd>
                                            {
                                                snapshot.configuration.triage
                                                    .display_names.escalation
                                            }
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Escalation interval
                                        </dt>
                                        <dd>
                                            {
                                                snapshot.configuration.triage
                                                    .values
                                                    ?.escalation_after_minutes
                                            }{' '}
                                            minutes
                                        </dd>
                                    </div>
                                </dl>
                            ) : null}
                        </div>
                    ) : null}
                    {editing ? (
                        <RoutingEditor
                            snapshot={snapshot}
                            canManage={canManage}
                            sites={sites}
                            actorId={actorId}
                            readKey={readKey}
                            discard={discard}
                            onClose={(confirmed) => {
                                setEditing(false);
                                setSaved(confirmed);
                            }}
                        />
                    ) : null}
                </CardContent>
            </Card>
            <section
                aria-label="Incident routing change history"
                className="space-y-3"
            >
                <h2 className="text-section-title">Routing change history</h2>
                {history.data.length ? (
                    <ul className="space-y-3">
                        {history.data.map((entry) => (
                            <li
                                key={entry.id}
                                className="rounded-lg border p-4"
                            >
                                <h3 className="text-subtle font-medium">
                                    Revision {entry.revision} ·{' '}
                                    {entry.integrity === 'repair_required'
                                        ? 'Retained decision needs repair'
                                        : entry.action === 'kept'
                                          ? 'Configuration kept'
                                          : 'Configuration changed'}
                                </h3>
                                {entry.integrity === 'recorded' ? (
                                    <>
                                        <p className="text-caption text-muted-foreground">
                                            {formatDateTimeLong(
                                                entry.recorded_at,
                                            )}{' '}
                                            ·{' '}
                                            {entry.actor?.name ??
                                                'Decision-maker not recorded'}
                                        </p>
                                        <p className="text-subtle whitespace-pre-wrap">
                                            {entry.reason}
                                        </p>
                                        <p className="text-caption text-muted-foreground">
                                            {entry.configuration?.routes
                                                .length ?? 0}{' '}
                                            saved rules visible in your approved
                                            sites.
                                        </p>
                                    </>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-subtle text-muted-foreground">
                        No routing decisions are recorded.
                    </p>
                )}
                <LaravelPagination
                    links={history.links}
                    lastPage={history.last_page}
                    preserveScroll
                    only={[
                        'routing_configuration',
                        'routing_history',
                        'routing_sites',
                        'can_manage_routing',
                        'health_safety_routing_receipt',
                    ]}
                />
            </section>
        </section>
    );
}
