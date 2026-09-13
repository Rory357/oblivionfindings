import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    EntityCard,
    EntityCardGrid,
    EntityChip,
    EntityContextMenu,
    EntityStatusChip,
    EntityTable,
    compactMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput, StepHead } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import { formatDateTimeLong as formatDate } from '@/lib/datetime';
import axios from 'axios';
import {
    Check,
    ClipboardCheck,
    Copy,
    KeyRound,
    Loader2,
    Pencil,
    Plus,
    RefreshCw,
    RotateCw,
    ShieldCheck,
    ShieldX,
    Trash2,
    UserRound,
} from 'lucide-react';
import {
    useEffect,
    useRef,
    useState,
    type FormEvent,
    type ReactNode,
} from 'react';
import {
    API_IDENTITY_ABILITIES,
    API_IDENTITY_CREATE_FIELDS,
    API_IDENTITY_READ_FIELDS,
    API_IDENTITY_UPDATE_FIELDS,
    API_IDENTITY_WORK_TYPES,
    draftForApiIdentity,
    emptyApiIdentityDraft,
    newApiIdentityRequestUuid,
    normalizeApiIdentityDraft,
    type ApiIdentityDraft,
    type ApiIdentityOption,
    type ApiIdentityRecord,
    type OneTimeApiCredential,
} from './it-api-identity-contract';

export type ItApiIdentity = ApiIdentityRecord;
export type { OneTimeApiCredential } from './it-api-identity-contract';

type IdentityOperation = 'issue' | 'update' | 'rotate' | 'revoke';
type Editor =
    | { mode: 'issue' }
    | { mode: 'edit'; identity: ItApiIdentity }
    | null;
type ApiIdentityCommand = {
    operation: IdentityOperation;
    requestUuid: string;
    url: string;
    method: 'post' | 'patch';
    payload: Record<string, unknown>;
    identityId: number | null;
};
type ConfirmedResult = {
    viewer_user_id: number;
    request_uuid: string;
    operation: IdentityOperation;
    state: 'confirmed';
    identity_id: number;
    configuration_version: number;
    replayed: boolean;
    credential?: OneTimeApiCredential | null;
    credential_unavailable?: boolean;
};
type SuccessResult = Omit<ConfirmedResult, 'credential'>;
type CancelledResult = {
    viewer_user_id: number;
    request_uuid: string;
    operation: null;
    state: 'cancelled';
};
type NotFoundResult = {
    viewer_user_id: number;
    request_uuid: string;
    state: 'not_found';
};
type CommandResult = ConfirmedResult | CancelledResult | NotFoundResult;
type Props = {
    identities: ItApiIdentity[];
    agents: ApiIdentityOption[];
    sites: ApiIdentityOption[];
    viewerUserId?: number;
    canManage?: boolean;
    initialCredential?: OneTimeApiCredential | null;
    layout?: 'cards' | 'table';
    query?: string;
};

const steps = [
    {
        key: 'identity',
        label: 'Identity',
        blurb: 'Name, purpose and account',
        icon: UserRound,
    },
    {
        key: 'access',
        label: 'Operations',
        blurb: 'Work types and capabilities',
        icon: ShieldCheck,
    },
    {
        key: 'scope',
        label: 'Scope and fields',
        blurb: 'Sites and delegated fields',
        icon: KeyRound,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check before saving',
        icon: ClipboardCheck,
    },
] as const;

const isRecord = (value: unknown): value is Record<string, unknown> =>
    !!value && typeof value === 'object' && !Array.isArray(value);
const commandResult = (value: unknown): value is CommandResult => {
    if (
        !isRecord(value) ||
        typeof value.viewer_user_id !== 'number' ||
        typeof value.request_uuid !== 'string'
    )
        return false;

    if (value.state === 'confirmed')
        return (
            ['issue', 'update', 'rotate', 'revoke'].includes(
                String(value.operation),
            ) &&
            Number.isSafeInteger(value.identity_id) &&
            Number.isSafeInteger(value.configuration_version) &&
            typeof value.replayed === 'boolean'
        );

    if (value.state === 'cancelled') return value.operation === null;
    return value.state === 'not_found' && !('operation' in value);
};
const directCredentialIsValid = (value: unknown, identityId: number) => {
    if (!isRecord(value)) return false;
    return (
        value.identity_id === identityId &&
        typeof value.name === 'string' &&
        value.name.trim().length > 0 &&
        typeof value.token === 'string' &&
        value.token.trim().length > 0
    );
};
const jsonResponse = (response: unknown, expectedPath: string) => {
    if (!isRecord(response) || !isRecord(response.headers)) return false;
    const contentType = response.headers['content-type'];
    if (
        typeof contentType !== 'string' ||
        !contentType.toLocaleLowerCase().includes('application/json')
    )
        return false;
    const responseUrl = isRecord(response.request)
        ? response.request.responseURL
        : undefined;
    if (typeof responseUrl !== 'string' || !responseUrl) return false;
    try {
        const url = new URL(responseUrl, window.location.origin);
        return (
            url.origin === window.location.origin &&
            url.pathname === expectedPath
        );
    } catch {
        return false;
    }
};
const expiresAtIso = (value: string) => {
    if (!value) return null;
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? value : date.toISOString();
};
const normalizedCommandErrorKey = (key: string) => {
    const root = key.split('.')[0];
    if (['allowed_site_ids', 'allowed_sites', 'sites'].includes(root))
        return 'allowed_site_ids';
    if (['abilities', 'capabilities'].includes(root)) return 'abilities';
    if (['allowed_work_types', 'work_types'].includes(root))
        return 'allowed_work_types';
    if (key.startsWith('allowed_fields.create')) return 'create_fields';
    if (key.startsWith('allowed_fields.read')) return 'read_fields';
    if (key.startsWith('allowed_fields.update')) return 'update_fields';
    return root;
};
const validationStepFor = (keys: string[]) => {
    if (
        keys.some((key) =>
            ['name', 'actor_user_id', 'description'].includes(key),
        )
    )
        return 0;
    if (keys.some((key) => ['abilities', 'allowed_work_types'].includes(key)))
        return 1;
    if (
        keys.some((key) =>
            [
                'allowed_site_ids',
                'create_fields',
                'read_fields',
                'update_fields',
                'rate_limit_per_minute',
                'expires_at',
                'require_signature',
            ].includes(key),
        )
    )
        return 2;
    return 3;
};
const identitiesResult = (
    value: unknown,
): value is {
    viewer_user_id: number;
    can_manage: boolean;
    identities: ItApiIdentity[];
    agents: ApiIdentityOption[];
    sites: ApiIdentityOption[];
} =>
    isRecord(value) &&
    typeof value.viewer_user_id === 'number' &&
    typeof value.can_manage === 'boolean' &&
    Array.isArray(value.identities) &&
    Array.isArray(value.agents) &&
    Array.isArray(value.sites);
const siteNames = (ids: number[], sites: ApiIdentityOption[]) =>
    ids.length
        ? ids
              .map(
                  (id) =>
                      sites.find((site) => site.id === id)?.name ??
                      `Site ${id} (unavailable)`,
              )
              .join(', ')
        : 'No Site-linked work';
const toggle = (values: string[], value: string, checked: boolean) =>
    checked
        ? values.includes(value)
            ? values
            : [...values, value]
        : values.filter((entry) => entry !== value);
function errorMessage(error: unknown) {
    const data = axios.isAxiosError(error) ? error.response?.data : null;
    return isRecord(data) && typeof data.message === 'string'
        ? data.message
        : 'The command could not be confirmed. Recover the same request before trying again.';
}

function validate(
    draft: ApiIdentityDraft,
    mode: 'issue' | 'edit',
    step?: number,
): Record<string, string> {
    const errors: Record<string, string> = {};
    const include = (index: number) => step === undefined || step === index;
    if (include(0)) {
        if (!draft.name.trim()) errors.name = 'Enter an identity name.';
        if (mode === 'issue' && !draft.actor_user_id)
            errors.actor_user_id = 'Choose a current execution account.';
    }
    if (include(1)) {
        if (!draft.abilities.length)
            errors.abilities = 'Choose at least one operation.';
        if (!draft.allowed_work_types.length)
            errors.allowed_work_types = 'Choose at least one work type.';
    }
    if (
        include(2) &&
        draft.abilities.includes('work:update') &&
        !draft.update_fields.length
    )
        errors.update_fields =
            'Choose the triage fields this identity may update.';
    return errors;
}

export function ItApiIdentities({
    identities: initialIdentities,
    agents: initialAgents,
    sites: initialSites,
    viewerUserId = 0,
    canManage = true,
    initialCredential = null,
    layout = 'cards',
    query = '',
}: Props) {
    const [identities, setIdentities] = useState(initialIdentities);
    const [agents, setAgents] = useState(initialAgents);
    const [sites, setSites] = useState(initialSites);
    const [editor, setEditor] = useState<Editor>(null);
    const [revokeTarget, setRevokeTarget] = useState<ItApiIdentity | null>(
        null,
    );
    const [rotateTarget, setRotateTarget] = useState<ItApiIdentity | null>(
        null,
    );
    const [command, setCommand] = useState<ApiIdentityCommand | null>(null);
    const [unknown, setUnknown] = useState<ApiIdentityCommand | null>(null);
    const [message, setMessage] = useState<string | null>(null);
    const [commandErrors, setCommandErrors] = useState<Record<string, string>>(
        {},
    );
    const [credential, setCredential] = useState<OneTimeApiCredential | null>(
        initialCredential ?? null,
    );
    const [credentialUnavailable, setCredentialUnavailable] = useState(false);
    const [success, setSuccess] = useState<SuccessResult | null>(
        initialCredential
            ? {
                  viewer_user_id: viewerUserId,
                  request_uuid: 'initial',
                  operation: 'issue',
                  state: 'confirmed',
                  identity_id: initialCredential.identity_id,
                  configuration_version: 1,
                  replayed: false,
              }
            : null,
    );
    const [refreshFailed, setRefreshFailed] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [accessLatched, setAccessLatched] = useState(false);
    const [editConflict, setEditConflict] = useState<ItApiIdentity | null>(
        null,
    );
    const viewer = useRef(viewerUserId);
    const epoch = useRef(0);
    const abort = useRef<AbortController | null>(null);
    const busy = useRef(false);
    const concealed =
        accessLatched ||
        viewer.current !== viewerUserId ||
        !canManage ||
        !Number.isSafeInteger(viewerUserId) ||
        viewerUserId < 1;
    const clearPrivate = (text: string) => {
        epoch.current += 1;
        busy.current = false;
        abort.current?.abort();
        setIdentities([]);
        setAgents([]);
        setSites([]);
        setCommand(null);
        setUnknown(null);
        setEditor(null);
        setRotateTarget(null);
        setRevokeTarget(null);
        setCredential(null);
        setSuccess(null);
        setEditConflict(null);
        setRefreshFailed(false);
        setRefreshing(false);
        setAccessLatched(true);
        setMessage(text);
    };
    useEffect(() => {
        if (viewer.current === viewerUserId) return;
        viewer.current = viewerUserId;
        clearPrivate(
            'Your account changed. Current API identity details were cleared.',
        );
    }, [viewerUserId]);
    useEffect(() => {
        if (concealed)
            clearPrivate(
                'Current access could not be confirmed. Identity details were concealed.',
            );
    }, [concealed]);
    useEffect(() => () => abort.current?.abort(), []);

    const refresh = async (
        alreadyBusy = false,
    ): Promise<ItApiIdentity[] | null> => {
        if (concealed || (!alreadyBusy && busy.current)) return null;
        if (!alreadyBusy) busy.current = true;
        const token = ++epoch.current;
        setRefreshFailed(false);
        setRefreshing(true);
        try {
            const response = await axios.get('/it/setup/api-identities', {
                headers: { Accept: 'application/json' },
            });
            if (
                token !== epoch.current ||
                !jsonResponse(response, '/it/setup/api-identities') ||
                !identitiesResult(response.data)
            )
                throw new Error('Unconfirmed identity review response');
            if (
                response.data.viewer_user_id !== viewer.current ||
                response.data.can_manage !== true
            ) {
                clearPrivate(
                    'Your account changed. Identity details were concealed.',
                );
                return null;
            }
            setIdentities(response.data.identities);
            setAgents(response.data.agents);
            setSites(response.data.sites);
            return response.data.identities;
        } catch (error) {
            if (token === epoch.current) {
                const status = axios.isAxiosError(error)
                    ? error.response?.status
                    : undefined;
                if ([401, 403, 404, 419].includes(status ?? 0))
                    clearPrivate(
                        'Current access could not be confirmed. Identity details were concealed.',
                    );
                else {
                    setRefreshFailed(true);
                    setMessage(
                        'The command result is known, but current identity details could not be refreshed. Refresh before another change.',
                    );
                }
            }
            return null;
        } finally {
            if (token === epoch.current) {
                busy.current = false;
                setRefreshing(false);
            }
        }
    };
    const reconcileConfirmed = async (
        result: SuccessResult,
        alreadyBusy = false,
    ) => {
        if (!alreadyBusy && busy.current) return;
        busy.current = true;
        if (result.viewer_user_id !== viewer.current) {
            clearPrivate(
                'Your account changed. Identity details were concealed.',
            );
            return;
        }
        const current = await refresh(true);
        if (!current) return;
        const found = current.find(
            (identity) => identity.id === result.identity_id,
        );
        if (!found) {
            clearPrivate(
                'Current access to this identity could not be confirmed. Its credential and details were concealed.',
            );
            return;
        }
        if (found.configuration_version !== result.configuration_version) {
            setCredential(null);
            setCredentialUnavailable(
                result.operation === 'issue' || result.operation === 'rotate',
            );
            setMessage(
                'This identity changed again after the command. The current register was refreshed and any one-time credential was cleared.',
            );
            return;
        }
    };
    const run = async (next: ApiIdentityCommand, retry = false) => {
        if (
            busy.current ||
            command ||
            (unknown !== null && (!retry || next !== unknown)) ||
            concealed ||
            refreshFailed ||
            refreshing
        )
            return;
        busy.current = true;
        const token = ++epoch.current;
        const controller = new AbortController();
        abort.current = controller;
        setCommand(next);
        setCommandErrors({});
        setMessage(null);
        try {
            const response = await axios.request({
                method: next.method,
                url: next.url,
                data: next.payload,
                signal: controller.signal,
                headers: { Accept: 'application/json' },
            });
            if (
                token !== epoch.current ||
                !jsonResponse(response, next.url) ||
                !commandResult(response.data) ||
                response.data.request_uuid !== next.requestUuid
            )
                throw new Error('Unconfirmed identity command response');
            if (response.data.viewer_user_id !== viewer.current) {
                clearPrivate(
                    'Your account changed. Identity details were concealed.',
                );
                return;
            }
            setCommand(null);
            if (response.data.state === 'confirmed') {
                const result = response.data;
                if (
                    result.operation !== next.operation ||
                    (next.identityId !== null &&
                        result.identity_id !== next.identityId)
                )
                    throw new Error('Unexpected identity command result');
                if (
                    (next.operation === 'issue' ||
                        next.operation === 'rotate') &&
                    !result.replayed &&
                    result.credential_unavailable !== true &&
                    !directCredentialIsValid(
                        result.credential,
                        result.identity_id,
                    )
                )
                    throw new Error('Malformed one-time credential response');
                if (next.operation === 'issue' || next.operation === 'rotate') {
                    setCredential(
                        result.replayed ? null : (result.credential ?? null),
                    );
                    setCredentialUnavailable(
                        result.replayed ||
                            result.credential_unavailable === true,
                    );
                }
                setUnknown(null);
                const { credential: _credential, ...safeResult } = result;
                setSuccess(safeResult);
                setMessage(
                    result.replayed
                        ? 'The earlier command was confirmed.'
                        : null,
                );
                void reconcileConfirmed(safeResult, true);
                return;
            }
            if (response.data.state === 'cancelled') {
                setUnknown(null);
                busy.current = false;
                setMessage(
                    'The unconfirmed command was cancelled. No saved identity was cancelled.',
                );
                return;
            }
            setUnknown(next);
            busy.current = false;
            setMessage(
                'No saved result was found for this request. Retry uses the same request reference and unchanged entries.',
            );
        } catch (error) {
            if (token !== epoch.current) return;
            const response = axios.isAxiosError(error)
                ? error.response
                : undefined;
            const status = response?.status;
            if ([401, 403, 404, 419].includes(status ?? 0)) {
                clearPrivate(
                    'Current access could not be confirmed. Identity details were concealed.',
                );
                return;
            }
            setCommand(null);
            if (
                status === 422 &&
                isRecord(response?.data) &&
                isRecord(response.data.errors)
            ) {
                setUnknown(null);
                setCommandErrors(
                    Object.fromEntries(
                        Object.entries(response.data.errors).map(
                            ([key, value]) => [
                                normalizedCommandErrorKey(key),
                                Array.isArray(value)
                                    ? String(value[0] ?? '')
                                    : String(value),
                            ],
                        ),
                    ),
                );
                setMessage(
                    'Review the highlighted identity settings and try again.',
                );
                busy.current = false;
                return;
            }
            if (status === 409) {
                setUnknown(null);
                busy.current = false;
                const current = await refresh();
                if (next.operation === 'update' && next.identityId !== null) {
                    const latest = current?.find(
                        (identity) => identity.id === next.identityId,
                    );
                    if (latest) setEditConflict(latest);
                }
                setMessage(errorMessage(error));
                return;
            }
            setUnknown(next);
            setMessage(errorMessage(error));
            busy.current = false;
        }
    };
    const settleUnknown = async (kind: 'recover' | 'cancel') => {
        if (!unknown || command || concealed || busy.current) return;
        const frozen = unknown;
        busy.current = true;
        const token = ++epoch.current;
        setCommand(frozen);
        try {
            const response = await axios.post(
                `/it/setup/api-identities/commands/${kind}`,
                {
                    request_uuid: frozen.requestUuid,
                    viewer_user_id: viewer.current,
                },
                { headers: { Accept: 'application/json' } },
            );
            if (
                token !== epoch.current ||
                !jsonResponse(
                    response,
                    `/it/setup/api-identities/commands/${kind}`,
                ) ||
                !commandResult(response.data) ||
                response.data.request_uuid !== frozen.requestUuid
            )
                throw new Error('Unconfirmed recovery response');
            if (response.data.viewer_user_id !== viewer.current) {
                clearPrivate(
                    'Your account changed. Identity details were concealed.',
                );
                return;
            }
            setCommand(null);
            if (response.data.state === 'confirmed') {
                if (
                    response.data.operation !== frozen.operation ||
                    (frozen.identityId !== null &&
                        response.data.identity_id !== frozen.identityId)
                )
                    throw new Error('Unexpected recovered identity command');
                setUnknown(null);
                if (
                    frozen.operation === 'issue' ||
                    frozen.operation === 'rotate'
                ) {
                    setCredential(null);
                    setCredentialUnavailable(true);
                }
                const { credential: _credential, ...safeResult } =
                    response.data;
                setSuccess(safeResult);
                setMessage('The earlier command was confirmed.');
                void reconcileConfirmed(safeResult, true);
                return;
            }
            if (response.data.state === 'cancelled') {
                setUnknown(null);
                busy.current = false;
                setMessage(
                    'The unconfirmed command was cancelled. No saved identity was cancelled.',
                );
                return;
            }
            setUnknown(frozen);
            busy.current = false;
            setMessage(
                'This command has not been found. Retry uses the exact same request reference and unchanged entries.',
            );
        } catch (error) {
            if (token !== epoch.current) return;
            setCommand(null);
            const status = axios.isAxiosError(error)
                ? error.response?.status
                : undefined;
            if ([401, 403, 404, 419].includes(status ?? 0))
                clearPrivate(
                    'Current access could not be confirmed. Identity details were concealed.',
                );
            else setMessage(errorMessage(error));
            busy.current = false;
        }
    };
    const issue = (draft: ApiIdentityDraft) => {
        const requestUuid = newApiIdentityRequestUuid();
        void run({
            operation: 'issue',
            requestUuid,
            method: 'post',
            url: '/it/setup/api-identities',
            identityId: null,
            payload: {
                ...normalizeApiIdentityDraft(draft),
                expires_at: expiresAtIso(draft.expires_at),
                request_uuid: requestUuid,
                viewer_user_id: viewer.current,
            },
        });
    };
    const update = (identity: ItApiIdentity, draft: ApiIdentityDraft) => {
        const requestUuid = newApiIdentityRequestUuid();
        const { actor_user_id: _actor, ...payload } =
            normalizeApiIdentityDraft(draft);
        void run({
            operation: 'update',
            requestUuid,
            method: 'patch',
            url: `/it/setup/api-identities/${identity.id}`,
            identityId: identity.id,
            payload: {
                ...payload,
                expires_at: expiresAtIso(draft.expires_at),
                expected_version: identity.configuration_version,
                request_uuid: requestUuid,
                viewer_user_id: viewer.current,
            },
        });
    };
    const mutate = (
        operation: 'rotate' | 'revoke',
        identity: ItApiIdentity,
    ) => {
        const requestUuid = newApiIdentityRequestUuid();
        void run({
            operation,
            requestUuid,
            method: 'post',
            url: `/it/setup/api-identities/${identity.id}/${operation}`,
            identityId: identity.id,
            payload: {
                expected_version: identity.configuration_version,
                request_uuid: requestUuid,
                viewer_user_id: viewer.current,
            },
        });
    };
    const context = useEntityContextMenu<ItApiIdentity>();
    const mutationsBlocked =
        command !== null ||
        unknown !== null ||
        refreshing ||
        refreshFailed ||
        accessLatched;
    const actionsFor = (identity: ItApiIdentity): MenuItem[] =>
        mutationsBlocked
            ? []
            : compactMenu([
                  identity.revoked_at === null && {
                      label: 'Edit grants',
                      icon: Pencil,
                      onClick: () => setEditor({ mode: 'edit', identity }),
                  },
                  identity.is_active && {
                      label: 'Rotate credential',
                      icon: RotateCw,
                      onClick: () => setRotateTarget(identity),
                  },
                  identity.revoked_at === null && { separator: true },
                  identity.revoked_at === null && {
                      label: 'Revoke identity',
                      icon: Trash2,
                      danger: true,
                      onClick: () => setRevokeTarget(identity),
                  },
              ]);
    const editing = editor?.mode === 'edit' ? editor.identity : null;
    const normalizedQuery = query.trim().toLocaleLowerCase();
    const visibleIdentities = normalizedQuery
        ? identities.filter((identity) =>
              [
                  identity.name,
                  identity.description,
                  identity.public_id,
                  identity.actor?.name,
                  ...identity.abilities,
              ]
                  .filter(Boolean)
                  .join(' ')
                  .toLocaleLowerCase()
                  .includes(normalizedQuery),
          )
        : identities;
    const statusPanel = message ? (
        <Alert role="status">
            <AlertTitle>API identity status</AlertTitle>
            <AlertDescription className="space-y-3">
                <p>{message}</p>
                {unknown ? (
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            disabled={command !== null}
                            onClick={() => void settleUnknown('recover')}
                        >
                            <RefreshCw className="size-4" /> Recover request
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={command !== null}
                            onClick={() => void run(unknown, true)}
                        >
                            Retry same request
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={command !== null}
                            onClick={() => void settleUnknown('cancel')}
                        >
                            Cancel unconfirmed request
                        </Button>
                    </div>
                ) : refreshFailed ? (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() =>
                            success
                                ? void reconcileConfirmed(success)
                                : void refresh()
                        }
                    >
                        <RefreshCw className="size-4" /> Refresh current
                        identities
                    </Button>
                ) : null}
            </AlertDescription>
        </Alert>
    ) : null;
    if (concealed)
        return (
            <section
                aria-label="API identities"
                className="rounded-2xl border border-border bg-card p-5"
            >
                <EmptyState
                    variant="compact"
                    icon={ShieldX}
                    title="API identity details are unavailable"
                    description="Refresh current IT setup access before managing machine credentials."
                />
            </section>
        );
    return (
        <section
            aria-labelledby="api-identities"
            className="space-y-4"
            aria-busy={command !== null || refreshing}
        >
            {command && !editor ? (
                <p
                    role="status"
                    className="text-subtle flex items-center gap-2"
                >
                    <Loader2
                        className="size-4 animate-spin"
                        aria-hidden="true"
                    />
                    Checking the identity change…
                </p>
            ) : null}
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 id="api-identities" className="text-section-title">
                        API identities
                    </h2>
                    <p className="text-subtle">
                        Named machine credentials with explicit operations,
                        Sites, fields, expiry, signatures and request limits.
                    </p>
                </div>
                <Button
                    type="button"
                    disabled={mutationsBlocked}
                    onClick={() => {
                        setSuccess(null);
                        setCredential(null);
                        setCredentialUnavailable(false);
                        setCommandErrors({});
                        setEditConflict(null);
                        setEditor({ mode: 'issue' });
                    }}
                >
                    <Plus className="size-4" /> New API identity
                </Button>
            </div>
            {!editor && !success ? statusPanel : null}
            {visibleIdentities.length ? (
                layout === 'table' ? (
                    <EntityTable
                        rows={visibleIdentities}
                        rowKey={(identity) => identity.id}
                        identity={(identity) => ({
                            icon: KeyRound,
                            name: identity.name,
                            subline: `${identity.public_id} · ${identity.actor?.name ?? 'Execution account unavailable'}`,
                            extra: (
                                <EntityStatusChip
                                    variant={
                                        identity.is_active
                                            ? 'success'
                                            : 'neutral'
                                    }
                                >
                                    {identity.is_active ? 'Active' : 'Inactive'}
                                </EntityStatusChip>
                            ),
                        })}
                        columns={[
                            {
                                key: 'grants',
                                label: 'Operations',
                                width: '1.35fr',
                                cell: (identity) => (
                                    <EntityChip>
                                        {identity.abilities.join(', ')}
                                    </EntityChip>
                                ),
                            },
                            {
                                key: 'scope',
                                label: 'Scope',
                                width: '0.9fr',
                                cell: (identity) => (
                                    <EntityChip>
                                        {identity.allowed_site_ids.length
                                            ? `${identity.allowed_site_ids.length} Sites`
                                            : 'No Site scope'}
                                    </EntityChip>
                                ),
                            },
                            {
                                key: 'usage',
                                label: 'Last used',
                                width: '0.9fr',
                                cell: (identity) =>
                                    formatDate(identity.last_used_at, 'Never'),
                            },
                        ]}
                        actionsFor={actionsFor}
                        onRowContextMenu={(event, identity) => {
                            if (actionsFor(identity).length)
                                context.open(event, identity);
                        }}
                        mutedFor={(identity) => !identity.is_active}
                    />
                ) : (
                    <EntityCardGrid>
                        {visibleIdentities.map((identity) => (
                            <EntityCard
                                key={identity.id}
                                meridian={
                                    identity.is_active ? 'success' : 'critical'
                                }
                                icon={KeyRound}
                                name={identity.name}
                                subline={`${identity.public_id} · ${identity.actor?.name ?? 'Execution account unavailable'}`}
                                actions={actionsFor(identity)}
                                onContextMenu={(event) => {
                                    if (actionsFor(identity).length)
                                        context.open(event, identity);
                                }}
                                muted={!identity.is_active}
                                chips={
                                    <>
                                        <EntityStatusChip
                                            variant={
                                                identity.is_active
                                                    ? 'success'
                                                    : 'neutral'
                                            }
                                        >
                                            {identity.is_active
                                                ? 'Active'
                                                : 'Inactive'}
                                        </EntityStatusChip>
                                        {identity.require_signature ? (
                                            <EntityStatusChip variant="info">
                                                Signed
                                            </EntityStatusChip>
                                        ) : null}
                                        <EntityChip>
                                            {identity.allowed_site_ids.length
                                                ? `${identity.allowed_site_ids.length} Sites`
                                                : 'No Site scope'}
                                        </EntityChip>
                                    </>
                                }
                                metric={{
                                    label: 'Request limit',
                                    value: `${identity.rate_limit_per_minute}/minute`,
                                    percent: Math.min(
                                        100,
                                        (identity.rate_limit_per_minute / 300) *
                                            100,
                                    ),
                                }}
                                alerts={
                                    identity.expires_at ? (
                                        <EntityStatusChip variant="warning">
                                            Expires{' '}
                                            {formatDate(
                                                identity.expires_at,
                                                'Unknown',
                                            )}
                                        </EntityStatusChip>
                                    ) : undefined
                                }
                                footer={{
                                    personName: identity.actor?.name,
                                    primary:
                                        identity.actor?.name ??
                                        'Execution account unavailable',
                                    secondary: `Last used ${formatDate(identity.last_used_at, 'never')} · Rotated ${formatDate(identity.last_rotated_at, 'never')}`,
                                }}
                            />
                        ))}
                    </EntityCardGrid>
                )
            ) : (
                <EmptyState
                    icon={KeyRound}
                    title={
                        identities.length
                            ? 'No API identities match this search'
                            : 'No API identities configured'
                    }
                    description={
                        identities.length
                            ? 'Clear or change the setup search to review current identities.'
                            : 'Create one only for an approved system that needs controlled IT work access.'
                    }
                    action={
                        <Button
                            type="button"
                            onClick={() => setEditor({ mode: 'issue' })}
                        >
                            <Plus className="size-4" /> New API identity
                        </Button>
                    }
                />
            )}
            {context.ctx ? (
                <EntityContextMenu
                    x={context.ctx.x}
                    y={context.ctx.y}
                    icon={KeyRound}
                    title={context.ctx.record.name}
                    items={actionsFor(context.ctx.record)}
                    onClose={context.close}
                />
            ) : null}
            {editor ? (
                <IdentityWizard
                    key={
                        editor.mode === 'issue'
                            ? 'issue'
                            : `edit-${editing?.id}-${editing?.configuration_version}`
                    }
                    mode={editor.mode}
                    identity={editing}
                    agents={agents}
                    sites={sites}
                    pending={command !== null || refreshing}
                    unknown={unknown !== null}
                    status={statusPanel}
                    commandErrors={commandErrors}
                    success={success}
                    credential={credential}
                    credentialUnavailable={credentialUnavailable}
                    conflict={editConflict}
                    onIssue={issue}
                    onUpdate={update}
                    onClose={() => {
                        if (!command && !unknown) {
                            setEditor(null);
                            setSuccess(null);
                            setCredential(null);
                            setCredentialUnavailable(false);
                            setCommandErrors({});
                            setEditConflict(null);
                        }
                    }}
                    onClearCredential={() => {
                        setCredential(null);
                        setCredentialUnavailable(false);
                    }}
                    onAdoptCurrent={() => {
                        if (!editConflict) return;
                        setEditor({ mode: 'edit', identity: editConflict });
                        setEditConflict(null);
                        setCommandErrors({});
                        setMessage(null);
                    }}
                />
            ) : null}
            {success && !editor ? (
                <WizardShell
                    open
                    onClose={() => {
                        setSuccess(null);
                        setCredential(null);
                        setCredentialUnavailable(false);
                    }}
                    title="API identity command confirmed"
                    description="The requested API identity change has been confirmed."
                    railIcon={KeyRound}
                    railTitle="API identity"
                    railSub="Confirmed command"
                    steps={[]}
                    stepIndex={0}
                    onStepClick={() => undefined}
                    success={
                        <>
                            {statusPanel}
                            <CredentialSuccess
                                operation={success.operation}
                                credential={
                                    success.operation === 'issue' ||
                                    success.operation === 'rotate'
                                        ? credential
                                        : null
                                }
                                unavailable={credentialUnavailable}
                                onClear={() => {
                                    setCredential(null);
                                    setCredentialUnavailable(false);
                                }}
                                onDone={() => {
                                    setSuccess(null);
                                    setCredential(null);
                                    setCredentialUnavailable(false);
                                }}
                            />
                        </>
                    }
                />
            ) : null}
            <ConfirmDialog
                open={rotateTarget !== null}
                onClose={() => setRotateTarget(null)}
                onConfirm={() => {
                    if (rotateTarget) {
                        setSuccess(null);
                        setCredential(null);
                        setCredentialUnavailable(false);
                        mutate('rotate', rotateTarget);
                    }
                }}
                title="Rotate API credential?"
                description={`${rotateTarget?.name ?? 'This identity'} receives a new reusable credential. The former credential stops working once rotation is confirmed.`}
                confirmText="Rotate credential"
                variant="default"
            />
            <ConfirmDialog
                open={revokeTarget !== null}
                onClose={() => setRevokeTarget(null)}
                onConfirm={() => {
                    if (revokeTarget) mutate('revoke', revokeTarget);
                }}
                title="Revoke API identity?"
                description={`${revokeTarget?.name ?? 'This identity'} will stop working immediately. Existing audit history is retained.`}
                confirmText="Revoke identity"
            />
        </section>
    );
}

function IdentityWizard({
    mode,
    identity,
    agents,
    sites,
    pending,
    unknown,
    status,
    commandErrors,
    success,
    credential,
    credentialUnavailable,
    conflict,
    onIssue,
    onUpdate,
    onClose,
    onClearCredential,
    onAdoptCurrent,
}: {
    mode: 'issue' | 'edit';
    identity: ItApiIdentity | null;
    agents: ApiIdentityOption[];
    sites: ApiIdentityOption[];
    pending: boolean;
    unknown: boolean;
    status: ReactNode;
    commandErrors: Record<string, string>;
    success: SuccessResult | null;
    credential: OneTimeApiCredential | null;
    credentialUnavailable: boolean;
    onIssue: (draft: ApiIdentityDraft) => void;
    onUpdate: (identity: ItApiIdentity, draft: ApiIdentityDraft) => void;
    onClose: () => void;
    onClearCredential: () => void;
    conflict: ItApiIdentity | null;
    onAdoptCurrent: () => void;
}) {
    const base =
        mode === 'edit' && identity
            ? draftForApiIdentity(identity)
            : emptyApiIdentityDraft(String(agents[0]?.id ?? ''));
    const [draft, setDraft] = useState(base);
    const [step, setStep] = useState(0);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const dirty = JSON.stringify(draft) !== JSON.stringify(base);
    const leave = useSettingsLeaveConfirmation(
        dirty || pending || unknown,
        'Discard unsaved API identity settings?',
    );
    const allErrors = { ...errors, ...commandErrors };
    useEffect(() => {
        const keys = Object.keys(commandErrors);
        if (keys.length) setStep(validationStepFor(keys));
    }, [commandErrors]);
    const completion = [
        draft.name.trim(),
        draft.actor_user_id,
        draft.abilities.length ? 'yes' : '',
        draft.allowed_work_types.length ? 'yes' : '',
        draft.abilities.includes('work:update')
            ? draft.update_fields.length
                ? 'yes'
                : ''
            : 'not-needed',
        String(draft.rate_limit_per_minute),
    ].filter(Boolean).length;
    const change = <K extends keyof ApiIdentityDraft>(
        key: K,
        value: ApiIdentityDraft[K],
    ) => {
        setDraft((current) =>
            normalizeApiIdentityDraft({ ...current, [key]: value }),
        );
        setErrors((current) => ({ ...current, [key]: '' }));
    };
    const advance = () => {
        const next = validate(draft, mode, step);
        if (Object.keys(next).length) {
            setErrors(next);
            return;
        }
        setErrors({});
        setStep((value) => Math.min(3, value + 1));
    };
    const submit = (event?: FormEvent) => {
        event?.preventDefault();
        const next = validate(draft, mode);
        if (Object.keys(next).length) {
            setErrors(next);
            setStep(
                Object.keys(next).some((key) =>
                    ['name', 'actor_user_id'].includes(key),
                )
                    ? 0
                    : Object.keys(next).some((key) =>
                            ['abilities', 'allowed_work_types'].includes(key),
                        )
                      ? 1
                      : 2,
            );
            return;
        }
        if (mode === 'issue') onIssue(normalizeApiIdentityDraft(draft));
        else if (identity && !conflict)
            onUpdate(identity, normalizeApiIdentityDraft(draft));
    };
    const secretExpected =
        success?.operation === 'issue' || success?.operation === 'rotate';
    return (
        <>
            <WizardShell
                open
                onClose={() => leave.request(onClose)}
                title={
                    mode === 'issue'
                        ? 'New API identity'
                        : 'Edit API identity grants'
                }
                description="Set the execution account, capability grants, approved Site scope, and credential safeguards before review."
                railIcon={KeyRound}
                railTitle={
                    mode === 'issue' ? 'API identity' : 'Identity grants'
                }
                railSub={
                    mode === 'issue'
                        ? 'New machine credential'
                        : (identity?.name ?? 'Current identity')
                }
                steps={steps}
                stepIndex={step}
                onStepClick={(index) => {
                    if (!pending && !unknown) setStep(index);
                }}
                pct={Math.round((completion / 6) * 100)}
                pctLabel="Identity completeness"
                footerStart={
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => leave.request(onClose)}
                    >
                        Cancel
                    </Button>
                }
                footerEnd={
                    success ? undefined : (
                        <>
                            {step > 0 ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={
                                        pending || unknown || conflict !== null
                                    }
                                    onClick={() =>
                                        setStep((value) => value - 1)
                                    }
                                >
                                    Back
                                </Button>
                            ) : null}
                            {step < 3 ? (
                                <Button
                                    type="button"
                                    disabled={
                                        pending || unknown || conflict !== null
                                    }
                                    onClick={advance}
                                >
                                    Continue
                                </Button>
                            ) : (
                                <Button
                                    type="button"
                                    disabled={
                                        pending || unknown || conflict !== null
                                    }
                                    aria-busy={pending}
                                    onClick={() => submit()}
                                >
                                    {pending ? (
                                        <Loader2
                                            className="size-4 animate-spin"
                                            aria-hidden="true"
                                        />
                                    ) : null}
                                    {pending
                                        ? 'Saving…'
                                        : mode === 'issue'
                                          ? 'Issue identity'
                                          : 'Save grants'}
                                </Button>
                            )}
                        </>
                    )
                }
                success={
                    success ? (
                        <>
                            {status}
                            <CredentialSuccess
                                operation={success.operation}
                                credential={secretExpected ? credential : null}
                                unavailable={credentialUnavailable}
                                onClear={onClearCredential}
                                onDone={onClose}
                            />
                        </>
                    ) : undefined
                }
            >
                <form onSubmit={submit}>
                    {status}
                    {Object.keys(commandErrors).length ? (
                        <Alert role="alert">
                            <AlertTitle>
                                Review the identity settings
                            </AlertTitle>
                            <AlertDescription>
                                <ul className="mt-2 list-disc space-y-1 pl-5">
                                    {Object.entries(commandErrors).map(
                                        ([key, message]) => (
                                            <li key={key}>{message}</li>
                                        ),
                                    )}
                                </ul>
                            </AlertDescription>
                        </Alert>
                    ) : null}
                    {step === 0 ? (
                        <WizardStepPane>
                            <StepHead
                                icon={UserRound}
                                title="Identity and execution account"
                                blurb="Use a meaningful name and an account that is currently approved for this work."
                            />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    label="Identity name"
                                    htmlFor="api-identity-name"
                                    required
                                    error={allErrors.name}
                                >
                                    <Input
                                        id="api-identity-name"
                                        value={draft.name}
                                        onChange={(event) =>
                                            change('name', event.target.value)
                                        }
                                    />
                                </Field>
                                {mode === 'issue' ? (
                                    <Field
                                        label="Execution account"
                                        required
                                        error={allErrors.actor_user_id}
                                    >
                                        <SelectInput
                                            value={draft.actor_user_id}
                                            onChange={(value) =>
                                                change('actor_user_id', value)
                                            }
                                            placeholder="Choose an approved account"
                                            options={agents.map((agent) => ({
                                                value: String(agent.id),
                                                label: agent.name,
                                            }))}
                                            ariaLabel="Execution account"
                                        />
                                    </Field>
                                ) : (
                                    <Field label="Execution account">
                                        <Input
                                            value={
                                                identity?.actor?.name ??
                                                'Unavailable'
                                            }
                                            readOnly
                                        />
                                    </Field>
                                )}
                                <Field
                                    label="Purpose and owner notes"
                                    error={allErrors.description}
                                    span
                                >
                                    <Textarea
                                        value={draft.description}
                                        onChange={(event) =>
                                            change(
                                                'description',
                                                event.target.value,
                                            )
                                        }
                                        rows={3}
                                    />
                                </Field>
                            </div>
                        </WizardStepPane>
                    ) : null}
                    {step === 1 ? (
                        <WizardStepPane>
                            <StepHead
                                icon={ShieldCheck}
                                title="Operations and work types"
                                blurb="Grant only the operations this integration needs. Sensitive and application-wide access remain explicit."
                            />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Choices
                                    legend="Allowed operations"
                                    options={API_IDENTITY_ABILITIES}
                                    selected={draft.abilities}
                                    onChange={(value) =>
                                        change('abilities', value)
                                    }
                                    error={allErrors.abilities}
                                />
                                <Choices
                                    legend="Allowed work types"
                                    options={API_IDENTITY_WORK_TYPES}
                                    selected={draft.allowed_work_types}
                                    onChange={(value) =>
                                        change('allowed_work_types', value)
                                    }
                                    error={allErrors.allowed_work_types}
                                />
                            </div>
                        </WizardStepPane>
                    ) : null}
                    {step === 2 ? (
                        <WizardStepPane>
                            <StepHead
                                icon={KeyRound}
                                title="Sites and delegated fields"
                                blurb="Scope this identity to current approved Sites and exactly the fields it may send or receive."
                            />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Choices
                                    legend="Fields this identity may send when creating"
                                    options={API_IDENTITY_CREATE_FIELDS}
                                    selected={draft.create_fields}
                                    onChange={(value) =>
                                        change('create_fields', value)
                                    }
                                    error={allErrors.create_fields}
                                />
                                <Choices
                                    legend="Extra fields returned when reading"
                                    options={API_IDENTITY_READ_FIELDS}
                                    selected={draft.read_fields}
                                    onChange={(value) =>
                                        change('read_fields', value)
                                    }
                                    hint="Status, reference, title and priority are always returned."
                                    error={allErrors.read_fields}
                                />
                                {draft.abilities.includes('work:update') ? (
                                    <Choices
                                        legend="Triage fields this identity may update"
                                        options={API_IDENTITY_UPDATE_FIELDS}
                                        selected={draft.update_fields}
                                        onChange={(value) =>
                                            change('update_fields', value)
                                        }
                                        error={allErrors.update_fields}
                                        hint="Updates do not grant ownership or lifecycle control."
                                    />
                                ) : (
                                    <Alert>
                                        <AlertTitle>
                                            Update fields are unavailable
                                        </AlertTitle>
                                        <AlertDescription>
                                            Select “Update delegated triage
                                            fields” under Operations before
                                            granting category, priority, impact
                                            or urgency changes.
                                        </AlertDescription>
                                    </Alert>
                                )}
                                <fieldset className="rounded-xl border border-border p-3 sm:col-span-2">
                                    <legend className="px-1 text-sm font-medium">
                                        Allowed Sites
                                    </legend>
                                    <p className="mb-2 text-xs text-muted-foreground">
                                        Only Sites currently approved for both
                                        the manager and execution account can be
                                        saved.
                                    </p>
                                    <div className="grid max-h-48 gap-1 overflow-y-auto sm:grid-cols-2">
                                        {sites.map((site) => (
                                            <Choice
                                                key={site.id}
                                                label={site.name}
                                                checked={draft.allowed_site_ids.includes(
                                                    site.id,
                                                )}
                                                onChange={(checked) =>
                                                    change(
                                                        'allowed_site_ids',
                                                        checked
                                                            ? [
                                                                  ...draft.allowed_site_ids,
                                                                  site.id,
                                                              ]
                                                            : draft.allowed_site_ids.filter(
                                                                  (id) =>
                                                                      id !==
                                                                      site.id,
                                                              ),
                                                    )
                                                }
                                            />
                                        ))}
                                    </div>
                                    {allErrors.allowed_site_ids ? (
                                        <p
                                            role="alert"
                                            className="mt-2 text-sm text-destructive"
                                        >
                                            {allErrors.allowed_site_ids}
                                        </p>
                                    ) : null}
                                </fieldset>
                                <Field
                                    label="Requests per minute"
                                    error={allErrors.rate_limit_per_minute}
                                >
                                    <Input
                                        type="number"
                                        min={1}
                                        max={300}
                                        value={draft.rate_limit_per_minute}
                                        onChange={(event) =>
                                            change(
                                                'rate_limit_per_minute',
                                                Number(event.target.value),
                                            )
                                        }
                                    />
                                </Field>
                                <Field
                                    label="Expires at (optional, browser local time)"
                                    error={allErrors.expires_at}
                                >
                                    <Input
                                        type="datetime-local"
                                        value={draft.expires_at}
                                        onChange={(event) =>
                                            change(
                                                'expires_at',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Choice
                                    label="Require a timestamped HMAC signature on every request"
                                    checked={draft.require_signature}
                                    onChange={(checked) =>
                                        change('require_signature', checked)
                                    }
                                />
                                {allErrors.require_signature ? (
                                    <p
                                        role="alert"
                                        className="text-sm text-destructive"
                                    >
                                        {allErrors.require_signature}
                                    </p>
                                ) : null}
                            </div>
                        </WizardStepPane>
                    ) : null}
                    {step === 3 ? (
                        <WizardStepPane>
                            <StepHead
                                icon={ClipboardCheck}
                                title="Review identity grants"
                                blurb="The secret is returned only after a confirmed issue or rotation response."
                            />
                            <div className="grid gap-4">
                                {conflict ? (
                                    <Alert>
                                        <AlertTitle>
                                            Current grants changed
                                        </AlertTitle>
                                        <AlertDescription className="space-y-3">
                                            <p>
                                                You reviewed version{' '}
                                                {
                                                    identity?.configuration_version
                                                }
                                                ; the current saved identity is
                                                version{' '}
                                                {conflict.configuration_version}
                                                . Compare them before submitting
                                                a new command.
                                            </p>
                                            <ReviewCard
                                                icon={ShieldCheck}
                                                title="Current saved identity"
                                            >
                                                <ReviewRow
                                                    label="Name"
                                                    value={conflict.name}
                                                />
                                                <ReviewRow
                                                    label="Purpose"
                                                    value={
                                                        conflict.description ||
                                                        'No purpose notes'
                                                    }
                                                />
                                                <ReviewRow
                                                    label="Operations"
                                                    value={conflict.abilities.join(
                                                        ', ',
                                                    )}
                                                />
                                                <ReviewRow
                                                    label="Work types"
                                                    value={
                                                        conflict.allowed_work_types.join(
                                                            ', ',
                                                        ) || 'None'
                                                    }
                                                />
                                                <ReviewRow
                                                    label="Sites"
                                                    value={siteNames(
                                                        conflict.allowed_site_ids,
                                                        sites,
                                                    )}
                                                />
                                                <ReviewRow
                                                    label="Create fields"
                                                    value={
                                                        conflict.allowed_fields.create?.join(
                                                            ', ',
                                                        ) || 'None'
                                                    }
                                                />
                                                <ReviewRow
                                                    label="Read fields"
                                                    value={
                                                        conflict.allowed_fields.read?.join(
                                                            ', ',
                                                        ) || 'Safe fields only'
                                                    }
                                                />
                                                <ReviewRow
                                                    label="Update fields"
                                                    value={
                                                        conflict.allowed_fields.update?.join(
                                                            ', ',
                                                        ) || 'None'
                                                    }
                                                />
                                                <ReviewRow
                                                    label="Request safeguards"
                                                    value={`${conflict.require_signature ? 'Signed requests required' : 'Signature not required'} · ${conflict.rate_limit_per_minute}/minute`}
                                                />
                                                <ReviewRow
                                                    label="Expiry"
                                                    value={formatDate(
                                                        conflict.expires_at,
                                                        'No expiry',
                                                    )}
                                                />
                                            </ReviewCard>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                onClick={onAdoptCurrent}
                                            >
                                                Use current saved grants
                                            </Button>
                                        </AlertDescription>
                                    </Alert>
                                ) : null}
                                <ReviewCard
                                    icon={UserRound}
                                    title="Identity"
                                    onEdit={
                                        pending || unknown
                                            ? undefined
                                            : () => setStep(0)
                                    }
                                >
                                    <ReviewRow
                                        label="Name"
                                        value={draft.name || 'Not set'}
                                    />
                                    <ReviewRow
                                        label="Execution account"
                                        value={
                                            mode === 'issue'
                                                ? (agents.find(
                                                      (agent) =>
                                                          String(agent.id) ===
                                                          draft.actor_user_id,
                                                  )?.name ?? 'Not selected')
                                                : (identity?.actor?.name ??
                                                  'Unavailable')
                                        }
                                    />
                                    <ReviewRow
                                        label="Purpose"
                                        value={
                                            draft.description ||
                                            'No purpose notes'
                                        }
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={ShieldCheck}
                                    title="Capabilities and scope"
                                    onEdit={
                                        pending || unknown
                                            ? undefined
                                            : () => setStep(1)
                                    }
                                >
                                    <ReviewRow
                                        label="Operations"
                                        value={
                                            draft.abilities.join(', ') || 'None'
                                        }
                                    />
                                    <ReviewRow
                                        label="Work types"
                                        value={draft.allowed_work_types.join(
                                            ', ',
                                        )}
                                    />
                                    <ReviewRow
                                        label="Sites"
                                        value={siteNames(
                                            draft.allowed_site_ids,
                                            sites,
                                        )}
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={KeyRound}
                                    title="Field grants"
                                    onEdit={
                                        pending || unknown
                                            ? undefined
                                            : () => setStep(2)
                                    }
                                >
                                    <ReviewRow
                                        label="Create"
                                        value={
                                            draft.create_fields.join(', ') ||
                                            'None'
                                        }
                                    />
                                    <ReviewRow
                                        label="Read"
                                        value={
                                            draft.read_fields.join(', ') ||
                                            'Safe fields only'
                                        }
                                    />
                                    <ReviewRow
                                        label="Update"
                                        value={
                                            draft.update_fields.join(', ') ||
                                            'None'
                                        }
                                    />
                                    <ReviewRow
                                        label="Credential safeguards"
                                        value={`${draft.require_signature ? 'Signed requests required' : 'Signature not required'} · ${draft.rate_limit_per_minute}/minute${draft.expires_at ? ` · Expires ${formatDate(draft.expires_at, 'Unknown')}` : ''}`}
                                    />
                                </ReviewCard>
                            </div>
                        </WizardStepPane>
                    ) : null}
                </form>
            </WizardShell>
            {leave.confirmation}
        </>
    );
}

function CredentialSuccess({
    operation,
    credential,
    unavailable,
    onClear,
    onDone,
}: {
    operation: IdentityOperation;
    credential: OneTimeApiCredential | null;
    unavailable: boolean;
    onClear: () => void;
    onDone: () => void;
}) {
    const [copied, setCopied] = useState(false);
    const [copyError, setCopyError] = useState<string | null>(null);
    const copy = async () => {
        if (!credential) return;
        setCopied(false);
        setCopyError(null);
        try {
            await navigator.clipboard.writeText(credential.token);
            setCopied(true);
            setCopyError(null);
        } catch {
            setCopyError(
                'Copy was blocked by the browser. Select the credential and copy it before clearing this pane.',
            );
        }
    };
    const title =
        operation === 'update'
            ? 'API identity grants saved'
            : operation === 'revoke'
              ? 'API identity revoked'
              : operation === 'rotate'
                ? 'API credential rotated'
                : 'API identity issued';
    return (
        <WizardSuccessPane
            title={title}
            blurb={
                credential ? (
                    <div className="space-y-3 text-left">
                        <p>
                            This credential is shown only in this confirmed
                            response. Store it in the approved secret manager
                            before clearing it.
                        </p>
                        <Input
                            aria-label="One-time API credential"
                            value={credential.token}
                            readOnly
                            onFocus={(event) => event.currentTarget.select()}
                            className="font-mono text-xs"
                        />
                        {copyError ? (
                            <p role="alert" className="text-status-critical">
                                {copyError}
                            </p>
                        ) : null}
                    </div>
                ) : unavailable ? (
                    'The command was confirmed, but its one-time credential is unavailable here. Review the current identity before rotating.'
                ) : (
                    'The confirmed identity is available in the current register.'
                )
            }
            actions={
                <>
                    {credential ? (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => void copy()}
                        >
                            <Copy className="size-4" />{' '}
                            {copied ? 'Copied' : 'Copy credential'}
                        </Button>
                    ) : null}
                    {credential ? (
                        <Button
                            type="button"
                            onClick={() => {
                                onClear();
                                onDone();
                            }}
                        >
                            Clear credential and done
                        </Button>
                    ) : (
                        <Button type="button" onClick={onDone}>
                            <Check className="size-4" /> Done
                        </Button>
                    )}
                </>
            }
        />
    );
}

function Choices({
    legend,
    options,
    selected,
    onChange,
    hint,
    error,
}: {
    legend: string;
    options: ReadonlyArray<readonly [string, string]>;
    selected: string[];
    onChange: (values: string[]) => void;
    hint?: string;
    error?: string;
}) {
    return (
        <fieldset className="rounded-xl border border-border p-3">
            <legend className="px-1 text-sm font-medium">{legend}</legend>
            {hint ? (
                <p className="mb-1 text-xs text-muted-foreground">{hint}</p>
            ) : null}
            <div className="space-y-1">
                {options.map(([value, label]) => (
                    <Choice
                        key={value}
                        label={label}
                        checked={selected.includes(value)}
                        onChange={(checked) =>
                            onChange(toggle(selected, value, checked))
                        }
                    />
                ))}
            </div>
            {error ? (
                <p role="alert" className="mt-2 text-xs text-status-critical">
                    {error}
                </p>
            ) : null}
        </fieldset>
    );
}
function Choice({
    label,
    checked,
    onChange,
}: {
    label: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    return (
        <label className="flex min-h-11 items-center gap-2 rounded-md px-2 text-sm hover:bg-muted/50">
            <Checkbox
                checked={checked}
                onCheckedChange={(value) => onChange(value === true)}
            />
            {label}
        </label>
    );
}
