import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    ItApiIdentities,
    type ItApiIdentity,
    type OneTimeApiCredential,
} from '@/components/it/it-api-identities';
import {
    ItCatalogueManagement,
    type CatalogManagementItem,
} from '@/components/it/it-catalogue-management';
import { ItModuleShell } from '@/components/it/it-module-shell';
import {
    ItProvisioningTemplates,
    type ProvisioningTemplate,
} from '@/components/it/it-provisioning-templates';
import {
    ItServiceOperations,
    type AutomationDefinition,
    type AutomationRunRow,
    type EmailDeliveryRow,
    type OperationsAudit,
} from '@/components/it/it-service-operations';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    ClipboardCheck,
    Network,
    Pencil,
    Route,
    UsersRound,
} from 'lucide-react';
import {
    useEffect,
    useRef,
    useState,
    type FormEvent,
    type ReactNode,
} from 'react';

import { SetupCreateRecovery } from './_create-recovery';
import { SetupRecordWizard } from './_dialogs';
import { SETUP_TABS, SetupHeader, type SetupTab } from './_header';
import { SetupMemoryRecovery } from './_memory-recovery';
import { SetupRegister, setupNeedsAttention, setupSearch } from './_registers';
import type { Agent, Queue, RoutingAgent, Service, Team } from './_types';
import {
    useSetupCreateCommand,
    type SetupCommandOutcome,
    type SetupCreated,
} from './use-setup-create-command';
import { useSetupMemory, type SetupFields } from './use-setup-memory';

interface Props {
    teams: Team[];
    queues: Queue[];
    services: Service[];
    catalogItems?: CatalogManagementItem[];
    agents: RoutingAgent[];
    sites: Agent[];
    positionRoles?: string[];
    apiIdentities: ItApiIdentity[];
    apiIdentityViewerUserId?: number;
    canManageApiIdentities?: boolean;
    oneTimeApiCredential?: OneTimeApiCredential | null;
    provisioningTemplates: ProvisioningTemplate[];
    operationsAudit?: OperationsAudit;
    emailDeliveries?: EmailDeliveryRow[];
    emailDeliveryFilter?: {
        comment_id: number;
        ticket_reference: string;
        total: number;
        page: number;
        last_page: number;
        shown: number;
    } | null;
    automationDefinitions?: AutomationDefinition[];
    automationRuns?: AutomationRunRow[];
    generatedAt?: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'IT & Support', href: '/it' },
    { title: 'Teams, queues & services', href: '/it/setup' },
];
const labels = (value: string) =>
    value
        .replace(/_/g, ' ')
        .replace(/^\w/, (character) => character.toUpperCase());
const WORK_TYPES = [
    'incident',
    'service_request',
    'security_request',
    'problem',
    'change',
    'task',
    'major_incident',
];
const CATEGORIES = ['hardware', 'account', 'network', 'other'];
const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

function isQueueReview(value: unknown): value is Queue {
    if (!value || typeof value !== 'object') return false;
    const queue = value as Partial<Queue>;
    const person = (entry: unknown) =>
        entry === null ||
        (!!entry &&
            typeof entry === 'object' &&
            typeof (entry as Agent).id === 'number' &&
            typeof (entry as Agent).name === 'string');
    const rules = queue.filter_rules;
    return (
        typeof queue.id === 'number' &&
        typeof queue.key === 'string' &&
        typeof queue.name === 'string' &&
        (queue.description === null || typeof queue.description === 'string') &&
        typeof queue.is_active === 'boolean' &&
        person(queue.team) &&
        typeof queue.configuration_version === 'string' &&
        /^[a-f0-9]{64}$/.test(queue.configuration_version) &&
        !!queue.readiness &&
        person(queue.readiness.accountable_owner) &&
        person(queue.readiness.cover) &&
        !!rules &&
        ['work_types', 'categories', 'priorities'].every((key) => {
            const values = rules[key as 'work_types'];
            return (
                values === undefined ||
                (Array.isArray(values) &&
                    values.every((entry) => typeof entry === 'string'))
            );
        }) &&
        ['site_ids', 'service_ids'].every((key) => {
            const values = rules[key as 'site_ids'];
            return (
                values === undefined ||
                (Array.isArray(values) &&
                    values.every((entry) => typeof entry === 'number'))
            );
        }) &&
        (rules.routing_priority === undefined ||
            typeof rules.routing_priority === 'number') &&
        (rules.is_default === undefined ||
            typeof rules.is_default === 'boolean')
    );
}

export default function ItSetupIndex({
    teams,
    queues,
    services,
    catalogItems = [],
    agents,
    sites,
    positionRoles = [],
    apiIdentities,
    apiIdentityViewerUserId = 0,
    canManageApiIdentities = true,
    oneTimeApiCredential = null,
    provisioningTemplates,
    operationsAudit,
    emailDeliveries = [],
    emailDeliveryFilter = null,
    automationDefinitions = [],
    automationRuns = [],
    generatedAt,
}: Props) {
    const page = usePage();
    const pageUrl = page.url;
    const actorId = (page.props.auth as { user?: { id: number } } | undefined)
        ?.user?.id;
    const queueActor = useRef(actorId);
    const queueActorChanged = queueActor.current !== actorId;
    const currentActor = useRef(actorId);
    currentActor.current = actorId;
    const params = new URLSearchParams(
        pageUrl.split('#')[0].split('?')[1] ?? '',
    );
    const requestedTab = params.get('tab');
    const tab: SetupTab = SETUP_TABS.some((item) => item.key === requestedTab)
        ? (requestedTab as SetupTab)
        : oneTimeApiCredential
          ? 'api'
          : 'teams';
    const [query, setQuery] = useState(params.get('q') ?? '');
    const queryTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const state = params.get('state') ?? 'all';
    const layout = params.get('list_view') === 'table' ? 'table' : 'cards';
    const [recordEditor, setRecordEditor] = useState<
        | { kind: 'team'; record?: Team }
        | { kind: 'service'; record?: Service }
        | null
    >(null);
    const openTeam = (record?: Team) =>
        setRecordEditor({ kind: 'team', record });
    const openService = (record?: Service) =>
        setRecordEditor({ kind: 'service', record });
    const navigate = (
        next: SetupTab,
        nextState = 'all',
        nextLayout: 'cards' | 'table' = layout,
        clearQuery = false,
    ) => {
        if (queryTimer.current) clearTimeout(queryTimer.current);
        const nextQuery = next === tab && !clearQuery ? query : '';
        setQuery(nextQuery);
        router.get(
            '/it/setup',
            {
                tab: next,
                ...(nextState !== 'all' ? { state: nextState } : {}),
                list_view: nextLayout,
                ...(next === 'operations' &&
                tab === 'operations' &&
                emailDeliveryFilter
                    ? {
                          delivery_comment_id: emailDeliveryFilter.comment_id,
                          delivery_page: emailDeliveryFilter.page,
                      }
                    : {}),
                ...(nextQuery ? { q: nextQuery } : {}),
            },
            { preserveState: true, preserveScroll: true },
        );
    };
    const search = (value: string) => {
        setQuery(value);
        if (queryTimer.current) clearTimeout(queryTimer.current);
        queryTimer.current = setTimeout(() => {
            router.get(
                '/it/setup',
                {
                    tab,
                    ...(state !== 'all' ? { state } : {}),
                    list_view: layout,
                    ...(tab === 'operations' && params.get('automation_from')
                        ? { automation_from: params.get('automation_from') }
                        : {}),
                    ...(tab === 'operations' && params.get('automation_to')
                        ? { automation_to: params.get('automation_to') }
                        : {}),
                    ...(tab === 'operations' && emailDeliveryFilter
                        ? {
                              delivery_comment_id:
                                  emailDeliveryFilter.comment_id,
                              delivery_page: emailDeliveryFilter.page,
                          }
                        : {}),
                    ...(value ? { q: value } : {}),
                },
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);
    };
    useEffect(() => {
        if (queryTimer.current) clearTimeout(queryTimer.current);
        setQuery(
            new URLSearchParams(pageUrl.split('#')[0].split('?')[1] ?? '').get(
                'q',
            ) ?? '',
        );
        return () => {
            if (queryTimer.current) clearTimeout(queryTimer.current);
        };
    }, [pageUrl]);
    const match = (value: string) =>
        value.toLowerCase().includes(query.trim().toLowerCase());
    const filteredEmailDeliveries = emailDeliveries.filter((item) =>
        match(
            [
                item.subject,
                item.recipient,
                item.recipient_email,
                item.ticket?.reference,
                item.status,
            ].join(' '),
        ),
    );
    const deliveryPageHref = (page: number) =>
        `/it/setup?tab=operations&delivery_comment_id=${emailDeliveryFilter?.comment_id}&delivery_page=${page}${query ? `&q=${encodeURIComponent(query)}` : ''}`;
    const filterRows = <T extends Team | Queue | Service>(rows: T[]) =>
        rows.filter(
            (row) =>
                match(setupSearch(row)) &&
                (state === 'active'
                    ? row.is_active
                    : state === 'inactive'
                      ? !row.is_active
                      : state === 'attention'
                        ? setupNeedsAttention(row)
                        : true),
        );
    const [queueOpen, setQueueOpen] = useState(false);
    const [queueSaved, setQueueSaved] = useState(false);
    const [queueError, setQueueError] = useState<string | null>(null);
    const [queueStep, setQueueStep] = useState(0);
    const [queueDiscardOpen, setQueueDiscardOpen] = useState(false);
    const [queueReviewed, setQueueReviewed] = useState<Queue | null>(null);
    const [queueReviewLoading, setQueueReviewLoading] = useState(false);
    const [queueReviewError, setQueueReviewError] = useState<string | null>(
        null,
    );
    const [queueOutcomeUnknown, setQueueOutcomeUnknown] = useState(false);
    const [queueNotFound, setQueueNotFound] = useState(false);
    const queueSubmittedKey = useRef<string | null>(null);
    const queueSubmitted = useRef<SetupFields | null>(null);
    const [queueSettledToken, setQueueSettledToken] = useState(0);
    const [queueAccessBlocked, setQueueAccessBlocked] = useState<
        'expired' | 'denied' | null
    >(null);
    const queueReviewEpoch = useRef(0);
    const queueReviewAbort = useRef<AbortController | null>(null);
    useEffect(
        () => () => {
            queueReviewEpoch.current++;
            queueReviewAbort.current?.abort();
        },
        [],
    );
    const [editingQueueId, setEditingQueueId] = useState<number | null>(null);
    const [createdQueue, setCreatedQueue] = useState<SetupCreated | null>(null);
    const queueCreate = useSetupCreateCommand({
        active:
            queueOpen && !editingQueueId && !queueSaved && !queueActorChanged,
        actorId,
        resource: 'queues',
    });
    const queueForm = useForm({
        configuration_version: '',
        key: '',
        name: '',
        description: '',
        team_id: '',
        routing_priority: 0,
        is_default: false,
        work_types: [] as string[],
        categories: [] as string[],
        priorities: [] as string[],
        service_ids: [] as number[],
        site_ids: [] as number[],
        default_assignee_user_id: '',
        cover_user_id: '',
        is_active: true,
    });
    const queueOriginal = useRef<typeof queueForm.data | null>(null);
    const queueProcessing = queueForm.processing || queueCreate.busy;
    const queuePayload = (data: typeof queueForm.data): SetupFields => ({
        ...Object.fromEntries(
            Object.entries(data).filter(
                ([key]) => key !== 'configuration_version',
            ),
        ),
        team_id: data.team_id ? Number(data.team_id) : null,
        default_assignee_user_id: data.default_assignee_user_id
            ? Number(data.default_assignee_user_id)
            : null,
        cover_user_id: data.cover_user_id ? Number(data.cover_user_id) : null,
    });
    const queueFromFields = (
        fields: SetupFields,
        version: string | null,
    ): typeof queueForm.data => ({
        configuration_version: version ?? '',
        name: String(fields.name ?? ''),
        key: String(fields.key ?? ''),
        description: String(fields.description ?? ''),
        team_id: String(fields.team_id ?? ''),
        default_assignee_user_id: String(fields.default_assignee_user_id ?? ''),
        cover_user_id: String(fields.cover_user_id ?? ''),
        routing_priority: Number(fields.routing_priority ?? 0),
        is_default: fields.is_default === true,
        is_active: fields.is_active !== false,
        work_types: (fields.work_types ?? []) as string[],
        categories: (fields.categories ?? []) as string[],
        priorities: (fields.priorities ?? []) as string[],
        site_ids: (fields.site_ids ?? []) as number[],
        service_ids: (fields.service_ids ?? []) as number[],
    });
    const queueDirty =
        JSON.stringify(queuePayload(queueForm.data)) !==
        JSON.stringify(queuePayload(queueOriginal.current ?? queueForm.data));
    const queueMemory = useSetupMemory({
        active:
            queueOpen &&
            !queueSaved &&
            !queueActorChanged &&
            queueAccessBlocked !== 'denied',
        actorId,
        resource: 'queues',
        recordId: editingQueueId,
        dirty: queueDirty,
        settledToken: queueSettledToken + queueCreate.settledToken,
        acceptsWork: (work) =>
            !!editingQueueId || queueCreate.accepts(work.command_uuid),
        canRecover: !queueProcessing,
        commandPendingOnly:
            !editingQueueId && queueCreate.pending && !queueSubmitted.current,
        work: {
            configuration_version: queueForm.data.configuration_version || null,
            base_fields: queuePayload(queueOriginal.current ?? queueForm.data),
            fields: queuePayload(queueForm.data),
            step_index: queueStep,
            outcomeUnknown:
                queueOutcomeUnknown ||
                queueProcessing ||
                (!editingQueueId && queueCreate.pending),
            submitted: queueSubmitted.current,
            command_uuid: editingQueueId ? null : queueCreate.requestUuid,
        },
    });
    const queueNameInput = useRef<HTMLInputElement>(null);
    const queueKeyInput = useRef<HTMLInputElement>(null);
    const queueFormElement = useRef<HTMLFormElement>(null);
    const focusQueueField = (errors: Record<string, string>) =>
        requestAnimationFrame(() => {
            const key = Object.keys(errors)
                .map((field) => field.split('.')[0])
                .find((field) => field in queueForm.data);
            const field =
                key &&
                queueFormElement.current?.querySelector<HTMLElement>(
                    `[name="${key}"]`,
                );
            (
                field ||
                queueFormElement.current?.querySelector<HTMLElement>(
                    '[data-queue-errors]',
                )
            )?.focus();
        });
    const openQueue = (queue?: Queue) => {
        queueReviewEpoch.current++;
        setQueueAccessBlocked(null);
        queueActor.current = actorId;
        queueSubmitted.current = null;
        setQueueSaved(false);
        setCreatedQueue(null);
        setQueueOutcomeUnknown(false);
        setQueueNotFound(false);
        queueSubmittedKey.current = null;
        queueReviewAbort.current?.abort();
        setQueueStep(0);
        setQueueReviewed(null);
        setQueueReviewError(null);
        setQueueReviewLoading(false);
        setQueueError(null);
        queueForm.clearErrors();
        setEditingQueueId(queue?.id ?? null);
        const values = {
            configuration_version: queue?.configuration_version ?? '',
            key: queue?.key ?? '',
            name: queue?.name ?? '',
            description: queue?.description ?? '',
            team_id: String(queue?.team?.id ?? ''),
            routing_priority: queue?.filter_rules.routing_priority ?? 0,
            is_default: queue?.filter_rules.is_default ?? false,
            work_types: queue?.filter_rules.work_types ?? [],
            categories: queue?.filter_rules.categories ?? [],
            priorities: queue?.filter_rules.priorities ?? [],
            service_ids: queue?.filter_rules.service_ids ?? [],
            site_ids: queue?.filter_rules.site_ids ?? [],
            default_assignee_user_id: String(
                queue?.filter_rules.default_assignee_user_id ?? '',
            ),
            cover_user_id: String(queue?.filter_rules.cover_user_id ?? ''),
            is_active: queue?.is_active ?? true,
        };
        queueOriginal.current = values;
        queueForm.setDefaults(values);
        queueForm.setData(values);
        setQueueOpen(true);
    };
    const handleQueueCreate = (outcome: SetupCommandOutcome | null) => {
        if (outcome) {
            queueMemory.acknowledgeCommand(outcome.request_uuid);
            if ('cancelled' in outcome) {
                setQueueOutcomeUnknown(false);
                queueSubmitted.current = null;
                return;
            }
            queueMemory.clearOwned();
            setCreatedQueue(outcome);
            setQueueSaved(true);
            setQueueOutcomeUnknown(false);
            return;
        }
        const current = queueCreate.getSnapshot();
        if (current.state === 'expired') {
            setQueueReviewed(null);
            setQueueAccessBlocked('expired');
        }
        if (current.state === 'denied') {
            queueMemory.clearOwned();
            setQueueReviewed(null);
            setQueueAccessBlocked('denied');
            const empty = queueFromFields({}, null);
            queueForm.setData(empty);
            queueOriginal.current = empty;
            queueSubmitted.current = null;
        }
        if (current.state === 'validation') {
            queueForm.clearErrors();
            for (const [key, message] of Object.entries(current.errors)) {
                if (key in queueForm.data)
                    queueForm.setError(
                        key as keyof typeof queueForm.data,
                        message,
                    );
            }
            const errors = current.errors;
            focusQueueField(errors);
            setQueueStep(
                errors.name || errors.key || errors.description
                    ? 0
                    : errors.team_id ||
                        errors.cover_user_id ||
                        errors.default_assignee_user_id
                      ? 1
                      : 2,
            );
            if (errors.name || errors.key)
                requestAnimationFrame(() =>
                    (errors.name
                        ? queueNameInput
                        : queueKeyInput
                    ).current?.focus(),
                );
        }
    };
    const saveQueue = (event: FormEvent) => {
        event.preventDefault();
        if (
            queueProcessing ||
            queueActorChanged ||
            queueAccessBlocked ||
            (!editingQueueId && !queueCreate.canEdit)
        )
            return;
        if (queueStep < 3) {
            if (validateQueueIdentity()) setQueueStep(queueStep + 1);
            return;
        }
        if (
            queueProcessing ||
            queueActorChanged ||
            queueAccessBlocked ||
            queueOutcomeUnknown ||
            queueForm.errors.configuration_version ||
            !validateQueueIdentity()
        )
            return;
        setQueueError(null);
        queueSubmittedKey.current = queueForm.data.key;
        queueSubmitted.current = queuePayload(queueForm.data);
        if (!editingQueueId) {
            queueForm.clearErrors();
            void queueCreate
                .submit(queueSubmitted.current)
                .then(handleQueueCreate);
            return;
        }
        let answered = false;
        const options = {
            onError: (errors: Record<string, string>) => {
                focusQueueField(errors);
                answered = true;
                setQueueSettledToken((value) => value + 1);
                if (errors.configuration_version) setQueueStep(3);
                else if (errors.name || errors.key || errors.description)
                    setQueueStep(0);
                else if (
                    errors.team_id ||
                    errors.cover_user_id ||
                    errors.default_assignee_user_id
                )
                    setQueueStep(1);
                else setQueueStep(2);
            },
            onSuccess: (page: { props: Record<string, unknown> }) => {
                answered = true;
                const flash = page.props.flash as
                    | { error?: string; success?: string }
                    | undefined;
                if (flash?.error || !flash?.success) {
                    if (flash?.error)
                        setQueueSettledToken((value) => value + 1);
                    setQueueOutcomeUnknown(!flash?.error);
                    setQueueError(
                        flash?.error ??
                            'The save was not confirmed. Review the queue before retrying.',
                    );
                    return;
                }
                setQueueSaved(true);
                queueMemory.clearOwned();
                setQueueSettledToken((value) => value + 1);
            },
            onCancel: () => {
                answered = true;
                setQueueOutcomeUnknown(true);
                setQueueError(
                    'The save response was cancelled. Review current queues before retrying.',
                );
            },
            onFinish: () => {
                if (!answered) {
                    setQueueOutcomeUnknown(true);
                    setQueueError(
                        'The save response could not be confirmed. Your draft is retained. Review current queues after signing in again.',
                    );
                }
            },
        };
        queueForm.transform((data) => ({
            ...(editingQueueId && queueOriginal.current
                ? Object.fromEntries(
                      Object.entries(data).filter(
                          ([key, value]) =>
                              key === 'configuration_version' ||
                              JSON.stringify(value) !==
                                  JSON.stringify(
                                      queueOriginal.current?.[
                                          key as keyof typeof data
                                      ],
                                  ),
                      ),
                  )
                : data),
            actor_user_id: queueActor.current,
        }));
        if (editingQueueId) {
            queueForm.patch(`/it/setup/queues/${editingQueueId}`, options);
        } else {
            queueForm.post('/it/setup/queues', options);
        }
    };
    const closeQueue = () => {
        if (queueSaved) {
            setQueueOpen(false);
            if (createdQueue)
                router.reload({ only: ['queues'], preserveScroll: true });
            return;
        }
        if (queueCreate.busy) {
            queueCreate.cancelWait();
            return;
        }
        if (queueProcessing) {
            queueForm.cancel();
            setQueueOutcomeUnknown(true);
            setQueueError(
                'The save response was cancelled. Review current queues before retrying.',
            );
            return;
        }
        if (
            queueOutcomeUnknown ||
            queueCreate.pending ||
            Object.entries(queueForm.data).some(
                ([key, value]) =>
                    key !== 'configuration_version' &&
                    JSON.stringify(value) !==
                        JSON.stringify(
                            queueOriginal.current?.[
                                key as keyof typeof queueForm.data
                            ],
                        ),
            )
        ) {
            setQueueDiscardOpen(true);
            return;
        }
        queueReviewAbort.current?.abort();
        setQueueOpen(false);
    };
    const validateQueueIdentity = () => {
        queueForm.clearErrors('name', 'key');
        if (!queueForm.data.name.trim() || !queueForm.data.key.trim()) {
            if (!queueForm.data.name.trim())
                queueForm.setError('name', 'Enter a queue name.');
            if (!queueForm.data.key.trim())
                queueForm.setError('key', 'Enter a stable key.');
            setQueueStep(0);
            requestAnimationFrame(() => {
                const target = !queueForm.data.name.trim()
                    ? queueNameInput.current
                    : queueKeyInput.current;
                target?.focus();
            });
            return false;
        }
        return true;
    };
    const reviewQueue = async () => {
        if (
            (!editingQueueId && !queueSubmittedKey.current) ||
            queueReviewLoading
        )
            return;
        queueReviewAbort.current?.abort();
        const controller = new AbortController();
        const operation = ++queueReviewEpoch.current;
        queueReviewAbort.current = controller;
        setQueueReviewLoading(true);
        setQueueReviewError(null);
        setQueueReviewed(null);
        setQueueNotFound(false);
        try {
            const response = await fetch(
                `/it/setup?review_resource=queues&actor_user_id=${queueActor.current}`,
                {
                    credentials: 'same-origin',
                    cache: 'no-store',
                    signal: controller.signal,
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                },
            );
            if (
                controller.signal.aborted ||
                operation !== queueReviewEpoch.current ||
                currentActor.current !== queueActor.current
            )
                return;
            if ([401, 419].includes(response.status))
                setQueueAccessBlocked('expired');
            if ([403, 404].includes(response.status)) {
                setQueueAccessBlocked('denied');
                queueMemory.clearOwned();
                const empty = queueFromFields({}, null);
                queueForm.setData(empty);
                queueOriginal.current = empty;
                queueSubmitted.current = null;
            }
            if (!response.ok)
                throw new Error(
                    response.status === 403 || response.status === 401
                        ? 'Your current access does not allow reviewing this queue.'
                        : 'The current queue could not be loaded. Try again.',
                );
            let result;
            try {
                if (
                    !response.headers
                        .get('content-type')
                        ?.includes('application/json')
                )
                    throw new Error('Expected JSON');
                result = await response.json();
                if (
                    controller.signal.aborted ||
                    operation !== queueReviewEpoch.current ||
                    currentActor.current !== queueActor.current
                )
                    return;
            } catch {
                throw new Error(
                    'The current queue could not be verified. Your session may have expired; sign in in another tab, then retry this review.',
                );
            }
            if (
                result?.resource !== 'queues' ||
                result.viewer_user_id !== queueActor.current ||
                !Array.isArray(result.records)
            )
                throw new Error(
                    'The current queue could not be verified. Your draft is retained.',
                );
            const current = result.records.find(
                (queue: unknown) =>
                    isQueueReview(queue) &&
                    (editingQueueId
                        ? queue.id === editingQueueId
                        : queue.key === queueSubmittedKey.current),
            );
            if (!editingQueueId && !current && Array.isArray(result.records)) {
                if (!controller.signal.aborted) setQueueNotFound(true);
                return;
            }
            if (!isQueueReview(current))
                throw new Error(
                    'The current queue could not be verified. Reload setup to review it.',
                );
            if (
                !controller.signal.aborted &&
                operation === queueReviewEpoch.current
            )
                setQueueReviewed(current);
        } catch (error) {
            if (
                !controller.signal.aborted &&
                operation === queueReviewEpoch.current
            )
                setQueueReviewError(
                    error instanceof Error
                        ? error.message
                        : 'The current queue could not be loaded. Try again.',
                );
        } finally {
            if (
                !controller.signal.aborted &&
                operation === queueReviewEpoch.current
            )
                setQueueReviewLoading(false);
        }
    };
    const queueTeam = teams.find(
        (team) => String(team.id) === queueForm.data.team_id,
    );
    const queueAgents = agents.filter(
        (agent) =>
            (queueTeam?.manager?.id === agent.id ||
                queueTeam?.members.some((member) => member.id === agent.id)) &&
            queueForm.data.site_ids.every((siteId) =>
                agent.site_ids?.includes(siteId),
            ),
    );
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="IT setup" />
            <ItModuleShell>
                <div className="space-y-5">
                    <SetupHeader
                        tab={tab}
                        onNavigate={navigate}
                        query={query}
                        onQuery={search}
                        state={state}
                        layout={layout}
                        teams={teams}
                        queues={queues}
                        services={services}
                        generatedAt={generatedAt}
                        onCreate={
                            tab === 'teams'
                                ? () => openTeam()
                                : tab === 'queues'
                                  ? () => openQueue()
                                  : tab === 'services'
                                    ? () => openService()
                                    : undefined
                        }
                    />
                    {tab === 'teams' && (
                        <SetupRegister
                            title="Teams"
                            rows={filterRows(teams)}
                            total={teams.length}
                            layout={layout}
                            onEdit={openTeam}
                        />
                    )}
                    {tab === 'queues' && (
                        <SetupRegister
                            title="Queues"
                            rows={filterRows(queues)}
                            total={queues.length}
                            layout={layout}
                            onEdit={openQueue}
                        />
                    )}
                    {tab === 'services' && (
                        <SetupRegister
                            title="Services"
                            rows={filterRows(services)}
                            total={services.length}
                            layout={layout}
                            onEdit={openService}
                        />
                    )}
                    {tab === 'catalogue' ? (
                        <ItCatalogueManagement
                            key={actorId}
                            actorId={actorId}
                            items={catalogItems.filter((item) =>
                                match(
                                    [
                                        item.name,
                                        item.description,
                                        item.service_name,
                                        item.category,
                                    ].join(' '),
                                ),
                            )}
                            services={services}
                            sites={sites}
                        />
                    ) : null}
                    {tab === 'provisioning' ? (
                        <ItProvisioningTemplates
                            key={actorId}
                            actorId={actorId}
                            templates={provisioningTemplates.filter((item) =>
                                match(
                                    [
                                        item.name,
                                        item.description,
                                        item.lifecycle_type,
                                        item.site?.name,
                                    ].join(' '),
                                ),
                            )}
                            teams={teams}
                            sites={sites}
                            positionRoles={positionRoles}
                        />
                    ) : null}
                    {tab === 'api' ? (
                        <ItApiIdentities
                            identities={apiIdentities}
                            initialCredential={oneTimeApiCredential}
                            viewerUserId={
                                apiIdentityViewerUserId || (actorId ?? 1)
                            }
                            canManage={
                                canManageApiIdentities &&
                                (!actorId ||
                                    !apiIdentityViewerUserId ||
                                    actorId === apiIdentityViewerUserId)
                            }
                            layout={layout}
                            query={query}
                            agents={agents}
                            sites={sites}
                        />
                    ) : null}
                    {tab === 'operations' && operationsAudit ? (
                        <>
                            {emailDeliveryFilter && (
                                <section
                                    aria-label="Reply delivery history"
                                    className="mb-4 space-y-3 rounded-xl border border-border bg-card p-4"
                                >
                                    <p className="text-sm font-medium">
                                        Email attempts for{' '}
                                        {emailDeliveryFilter.ticket_reference} ·
                                        reply #{emailDeliveryFilter.comment_id}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        Showing {filteredEmailDeliveries.length}{' '}
                                        on this page ·{' '}
                                        {emailDeliveryFilter.total} attempts in
                                        total. Page {emailDeliveryFilter.page}{' '}
                                        of {emailDeliveryFilter.last_page}.
                                    </p>
                                    <div className="flex flex-wrap gap-2">
                                        {emailDeliveryFilter.page > 1 && (
                                            <Button
                                                asChild
                                                variant="outline"
                                                size="sm"
                                            >
                                                <a
                                                    href={deliveryPageHref(
                                                        emailDeliveryFilter.page -
                                                            1,
                                                    )}
                                                >
                                                    Previous attempts
                                                </a>
                                            </Button>
                                        )}
                                        {emailDeliveryFilter.page <
                                            emailDeliveryFilter.last_page && (
                                            <Button
                                                asChild
                                                variant="outline"
                                                size="sm"
                                            >
                                                <a
                                                    href={deliveryPageHref(
                                                        emailDeliveryFilter.page +
                                                            1,
                                                    )}
                                                >
                                                    Next attempts
                                                </a>
                                            </Button>
                                        )}
                                        <Button
                                            asChild
                                            variant="outline"
                                            size="sm"
                                        >
                                            <a href="/it/setup?tab=operations">
                                                View all delivery activity
                                            </a>
                                        </Button>
                                    </div>
                                </section>
                            )}
                            <ItServiceOperations
                                audit={operationsAudit}
                                deliveries={filteredEmailDeliveries}
                                automationDefinitions={automationDefinitions.filter(
                                    (item) =>
                                        match(
                                            [
                                                item.key,
                                                item.label,
                                                item.latest_status,
                                            ].join(' '),
                                        ),
                                )}
                                automationRuns={automationRuns.filter((item) =>
                                    match(
                                        [
                                            item.automation_key,
                                            item.status,
                                            item.error_summary,
                                        ].join(' '),
                                    ),
                                )}
                            />
                        </>
                    ) : null}
                </div>
            </ItModuleShell>

            {recordEditor && (
                <SetupRecordWizard
                    key={`${recordEditor.kind}-${recordEditor.record?.id ?? 'new'}`}
                    kind={recordEditor.kind}
                    record={recordEditor.record}
                    agents={agents}
                    onClose={() => setRecordEditor(null)}
                />
            )}

            <WizardShell
                open={queueOpen}
                onClose={closeQueue}
                title={editingQueueId ? 'Edit queue' : 'New queue'}
                description="Configure the queue, accountable people and approved routing rules."
                railIcon={Route}
                railTitle={editingQueueId ? 'Edit queue' : 'New queue'}
                railSub="Accountable service routing"
                steps={[
                    {
                        key: 'identity',
                        label: 'Queue details',
                        blurb: 'Name and purpose',
                        icon: Pencil,
                    },
                    {
                        key: 'ownership',
                        label: 'Accountability',
                        blurb: 'Manager, technician and cover',
                        icon: UsersRound,
                    },
                    {
                        key: 'rules',
                        label: 'Routing rules',
                        blurb: 'Sites and matching criteria',
                        icon: Route,
                    },
                    {
                        key: 'review',
                        label: 'Review',
                        blurb: 'Check before saving',
                        icon: ClipboardCheck,
                    },
                ]}
                success={
                    queueSaved ? (
                        <WizardSuccessPane
                            title="Queue saved"
                            blurb={
                                createdQueue
                                    ? `Queue #${createdQueue.id} is saved in IT setup.`
                                    : `${queueForm.data.name} is available in IT setup.`
                            }
                            actions={<Button onClick={closeQueue}>Done</Button>}
                        />
                    ) : undefined
                }
                pct={Math.round(
                    ([
                        !!queueForm.data.name.trim(),
                        !!queueForm.data.key.trim(),
                        !!queueForm.data.team_id,
                        !!queueForm.data.cover_user_id,
                        queueStep === 3,
                    ].filter(Boolean).length /
                        5) *
                        100,
                )}
                stepIndex={queueStep}
                onStepClick={(step) =>
                    !queueProcessing &&
                    (step <= queueStep || validateQueueIdentity()) &&
                    setQueueStep(step)
                }
                footerStart={
                    <Button
                        type="button"
                        variant="outline"
                        onClick={closeQueue}
                    >
                        Cancel
                    </Button>
                }
                footerEnd={
                    <>
                        {queueStep > 0 && (
                            <Button
                                type="button"
                                variant="outline"
                                disabled={queueProcessing}
                                onClick={() => setQueueStep(queueStep - 1)}
                            >
                                Back
                            </Button>
                        )}
                        {queueStep < 3 ? (
                            <Button
                                key="queue-continue"
                                type="button"
                                disabled={queueProcessing}
                                onClick={(event) => {
                                    event.preventDefault();
                                    if (
                                        queueStep !== 0 ||
                                        validateQueueIdentity()
                                    )
                                        setQueueStep(queueStep + 1);
                                }}
                            >
                                Continue
                            </Button>
                        ) : (
                            <Button
                                key="queue-save"
                                type="submit"
                                form="it-queue-form"
                                disabled={
                                    queueProcessing ||
                                    queueOutcomeUnknown ||
                                    (!editingQueueId && !queueCreate.canEdit) ||
                                    !!queueForm.errors.configuration_version
                                }
                            >
                                Save queue
                            </Button>
                        )}
                    </>
                }
            >
                {queueActorChanged || queueAccessBlocked ? (
                    <div role="alert">
                        {queueActorChanged || queueAccessBlocked === 'denied'
                            ? 'This form is no longer available to your current account. Reopen Setup to continue.'
                            : 'Your session expired. Sign in again, then check access before showing the entered values.'}
                        {queueAccessBlocked === 'expired' &&
                            !queueActorChanged && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={queueMemory.busy}
                                    onClick={async () => {
                                        const proof =
                                            await queueMemory.checkCurrentAccess();
                                        if (proof === 'denied') {
                                            setQueueAccessBlocked('denied');
                                            const empty = queueFromFields(
                                                {},
                                                null,
                                            );
                                            queueForm.setData(empty);
                                            queueOriginal.current = empty;
                                            queueSubmitted.current = null;
                                        } else if (proof) {
                                            setQueueAccessBlocked(null);
                                            if (!proof.capabilities.submit)
                                                queueForm.setError(
                                                    'configuration_version',
                                                    'Review current setup before saving.',
                                                );
                                        }
                                    }}
                                >
                                    Check access and resume
                                </Button>
                            )}
                        {queueMemory.warning && (
                            <span className="block">{queueMemory.warning}</span>
                        )}
                        {queueMemory.busy && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={queueMemory.cancel}
                            >
                                Cancel wait
                            </Button>
                        )}
                        {!editingQueueId &&
                            !queueActorChanged &&
                            queueAccessBlocked === 'expired' && (
                                <SetupCreateRecovery
                                    command={queueCreate}
                                    onOutcome={handleQueueCreate}
                                />
                            )}
                    </div>
                ) : (
                    <form
                        id="it-queue-form"
                        ref={queueFormElement}
                        onSubmit={saveQueue}
                    >
                        <SetupMemoryRecovery
                            memory={queueMemory}
                            onResume={({ work, proof }) => {
                                if (
                                    !editingQueueId &&
                                    (!work.command_uuid ||
                                        !queueCreate.restore(
                                            work.command_uuid,
                                            work.submitted ?? null,
                                            work.outcomeUnknown,
                                        ))
                                )
                                    return;
                                queueOriginal.current = queueFromFields(
                                    work.base_fields,
                                    work.configuration_version,
                                );
                                queueForm.setData(
                                    queueFromFields(
                                        work.fields,
                                        work.configuration_version,
                                    ),
                                );
                                queueSubmitted.current = work.submitted ?? null;
                                queueSubmittedKey.current = work.submitted
                                    ? String(work.submitted.key ?? '')
                                    : null;
                                setQueueStep(work.step_index);
                                setQueueOutcomeUnknown(
                                    editingQueueId
                                        ? work.outcomeUnknown
                                        : false,
                                );
                                queueForm.clearErrors();
                                if (!proof.capabilities.submit)
                                    queueForm.setError(
                                        'configuration_version',
                                        'Review current setup before applying this retained form.',
                                    );
                                setQueueError(
                                    'Retained work restored. Review the proposed details before saving.',
                                );
                            }}
                        />
                        {!editingQueueId && (
                            <SetupCreateRecovery
                                command={queueCreate}
                                onOutcome={handleQueueCreate}
                            />
                        )}
                        {queueActorChanged && (
                            <p role="alert">
                                Your signed-in account changed. Reopen Setup
                                from your current account.
                            </p>
                        )}
                        <FormErrors errors={queueForm.errors} />
                        {queueError && (
                            <p
                                role="alert"
                                className="mb-3 text-sm text-destructive"
                            >
                                {queueError}
                            </p>
                        )}
                        {(queueForm.errors.configuration_version ||
                            queueOutcomeUnknown) && (
                            <div className="mb-4 rounded-lg border border-border p-3">
                                <p className="text-sm">
                                    Your draft is retained. Review the saved
                                    queue before using its current version. Only
                                    fields you changed will be applied; other
                                    current saved values will be preserved.
                                </p>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={queueReviewLoading}
                                    onClick={() => void reviewQueue()}
                                >
                                    {queueReviewLoading
                                        ? 'Loading current queue…'
                                        : 'Review current queue'}
                                </Button>
                                {queueReviewLoading && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={() => {
                                            queueReviewEpoch.current++;
                                            queueReviewAbort.current?.abort();
                                            setQueueReviewLoading(false);
                                        }}
                                    >
                                        Cancel review
                                    </Button>
                                )}
                                {queueReviewError && (
                                    <p
                                        role="alert"
                                        className="mt-2 text-sm text-destructive"
                                    >
                                        {queueReviewError}
                                    </p>
                                )}
                                {queueNotFound && (
                                    <div className="mt-3 space-y-2 text-sm">
                                        <p>
                                            No saved queue with the submitted
                                            key is currently visible. This does
                                            not prove the earlier save failed.
                                            Your draft is retained; review again
                                            or confirm the outcome before
                                            another create attempt.
                                        </p>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => {
                                                setQueueStep(0);
                                            }}
                                        >
                                            Return to draft
                                        </Button>
                                    </div>
                                )}
                                {queueReviewed && (
                                    <div className="mt-3 space-y-2 text-sm">
                                        <p className="font-semibold">
                                            {queueReviewed.name}
                                        </p>
                                        <p>
                                            {queueReviewed.description ||
                                                'No description'}
                                        </p>
                                        <p>
                                            Team:{' '}
                                            {queueReviewed.team?.name ?? 'None'}{' '}
                                            · Manager:{' '}
                                            {queueReviewed.readiness
                                                .accountable_owner?.name ??
                                                'None'}{' '}
                                            · Cover:{' '}
                                            {queueReviewed.readiness.cover
                                                ?.name ?? 'None'}
                                        </p>
                                        <p>
                                            Matching:{' '}
                                            {[
                                                ...(queueReviewed.filter_rules
                                                    .work_types ?? []),
                                                ...(queueReviewed.filter_rules
                                                    .categories ?? []),
                                                ...(queueReviewed.filter_rules
                                                    .priorities ?? []),
                                            ]
                                                .map(labels)
                                                .join(', ') ||
                                                'All classifications'}
                                        </p>
                                        <p>
                                            Sites:{' '}
                                            {(
                                                queueReviewed.filter_rules
                                                    .site_ids ?? []
                                            )
                                                .map(
                                                    (id) =>
                                                        sites.find(
                                                            (site) =>
                                                                site.id === id,
                                                        )?.name ??
                                                        'Site outside current options',
                                                )
                                                .join(', ') ||
                                                'All active Sites'}{' '}
                                            · Services:{' '}
                                            {(
                                                queueReviewed.filter_rules
                                                    .service_ids ?? []
                                            )
                                                .map(
                                                    (id) =>
                                                        services.find(
                                                            (service) =>
                                                                service.id ===
                                                                id,
                                                        )?.name ??
                                                        'Service outside current options',
                                                )
                                                .join(', ') || 'All services'}
                                        </p>
                                        <p>
                                            Routing priority:{' '}
                                            {queueReviewed.filter_rules
                                                .routing_priority ?? 0}{' '}
                                            ·{' '}
                                            {queueReviewed.is_active
                                                ? 'Active'
                                                : 'Inactive'}{' '}
                                            ·{' '}
                                            {queueReviewed.filter_rules
                                                .is_default
                                                ? 'Fallback queue'
                                                : 'Specific rule'}
                                        </p>
                                        <Button
                                            type="button"
                                            onClick={() => {
                                                setEditingQueueId(
                                                    queueReviewed.id,
                                                );
                                                setQueueOutcomeUnknown(false);
                                                setQueueError(null);
                                                queueForm.setData(
                                                    'configuration_version',
                                                    queueReviewed.configuration_version,
                                                );
                                                queueForm.clearErrors(
                                                    'configuration_version',
                                                );
                                                setQueueSettledToken(
                                                    (value) => value + 1,
                                                );
                                                setQueueReviewed(null);
                                            }}
                                        >
                                            Use current version and keep my
                                            draft
                                        </Button>
                                    </div>
                                )}
                            </div>
                        )}
                        <fieldset
                            disabled={
                                queueProcessing ||
                                queueOutcomeUnknown ||
                                queueActorChanged ||
                                (!editingQueueId && !queueCreate.canEdit)
                            }
                            className="min-w-0"
                        >
                            {queueStep === 0 && (
                                <WizardStepPane>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <Field label="Queue name">
                                            <Input
                                                ref={queueNameInput}
                                                name="name"
                                                aria-invalid={
                                                    !!queueForm.errors.name
                                                }
                                                value={queueForm.data.name}
                                                onChange={(event) =>
                                                    queueForm.setData(
                                                        'name',
                                                        event.target.value,
                                                    )
                                                }
                                                required
                                            />
                                        </Field>
                                        <Field label="Stable key">
                                            <Input
                                                ref={queueKeyInput}
                                                name="key"
                                                aria-invalid={
                                                    !!queueForm.errors.key
                                                }
                                                value={queueForm.data.key}
                                                onChange={(event) =>
                                                    queueForm.setData(
                                                        'key',
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="network-urgent"
                                                required
                                            />
                                        </Field>
                                        <Field
                                            label="Description"
                                            className="sm:col-span-2"
                                        >
                                            <Textarea
                                                name="description"
                                                value={
                                                    queueForm.data.description
                                                }
                                                onChange={(event) =>
                                                    queueForm.setData(
                                                        'description',
                                                        event.target.value,
                                                    )
                                                }
                                                rows={2}
                                            />
                                        </Field>
                                    </div>
                                </WizardStepPane>
                            )}
                            {queueStep === 1 && (
                                <WizardStepPane>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <NativeSelect
                                            label="Accountable team"
                                            name="team_id"
                                            invalid={!!queueForm.errors.team_id}
                                            value={queueForm.data.team_id}
                                            onChange={(value) =>
                                                queueForm.setData({
                                                    ...queueForm.data,
                                                    team_id: value,
                                                    default_assignee_user_id:
                                                        '',
                                                    cover_user_id: '',
                                                })
                                            }
                                            options={[
                                                { value: '', label: 'No team' },
                                                ...teams
                                                    .filter(
                                                        (team) =>
                                                            team.is_active,
                                                    )
                                                    .map((team) => ({
                                                        value: String(team.id),
                                                        label: team.name,
                                                    })),
                                            ]}
                                        />
                                        <AgentSelect
                                            label="Default technician (optional)"
                                            name="default_assignee_user_id"
                                            invalid={
                                                !!queueForm.errors
                                                    .default_assignee_user_id
                                            }
                                            value={
                                                queueForm.data
                                                    .default_assignee_user_id
                                            }
                                            agents={queueAgents}
                                            onChange={(value) =>
                                                queueForm.setData(
                                                    'default_assignee_user_id',
                                                    value,
                                                )
                                            }
                                        />
                                        <AgentSelect
                                            label="Absence cover"
                                            name="cover_user_id"
                                            invalid={
                                                !!queueForm.errors.cover_user_id
                                            }
                                            value={queueForm.data.cover_user_id}
                                            agents={queueAgents.filter(
                                                (agent) =>
                                                    agent.id !==
                                                    queueTeam?.manager?.id,
                                            )}
                                            onChange={(value) =>
                                                queueForm.setData(
                                                    'cover_user_id',
                                                    value,
                                                )
                                            }
                                        />
                                        <p
                                            role="status"
                                            className="self-center text-sm text-muted-foreground"
                                        >
                                            Accountable manager:{' '}
                                            {queueTeam?.manager?.name ??
                                                'Choose a team with a current manager.'}{' '}
                                            An active fallback needs a different
                                            cover person in that team.
                                        </p>
                                    </div>
                                </WizardStepPane>
                            )}
                            {queueStep === 2 && (
                                <WizardStepPane>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <Field label="Routing priority">
                                            <Input
                                                type="number"
                                                min={0}
                                                max={1000}
                                                value={
                                                    queueForm.data
                                                        .routing_priority
                                                }
                                                onChange={(event) =>
                                                    queueForm.setData(
                                                        'routing_priority',
                                                        Number(
                                                            event.target.value,
                                                        ),
                                                    )
                                                }
                                            />
                                        </Field>
                                        <div className="flex flex-col gap-2">
                                            <ActiveToggle
                                                checked={
                                                    queueForm.data.is_active
                                                }
                                                onChange={(value) =>
                                                    queueForm.setData(
                                                        'is_active',
                                                        value,
                                                    )
                                                }
                                            />
                                            <label className="flex min-h-11 items-center gap-2 text-sm">
                                                <input
                                                    type="checkbox"
                                                    checked={
                                                        queueForm.data
                                                            .is_default
                                                    }
                                                    onChange={(event) =>
                                                        queueForm.setData(
                                                            'is_default',
                                                            event.target
                                                                .checked,
                                                        )
                                                    }
                                                />
                                                Use as fallback queue
                                            </label>
                                        </div>
                                        <RuleChoices
                                            label="Work types"
                                            values={WORK_TYPES}
                                            selected={queueForm.data.work_types}
                                            onChange={(value) =>
                                                queueForm.setData(
                                                    'work_types',
                                                    value,
                                                )
                                            }
                                        />
                                        <RuleChoices
                                            label="Categories"
                                            values={CATEGORIES}
                                            selected={queueForm.data.categories}
                                            onChange={(value) =>
                                                queueForm.setData(
                                                    'categories',
                                                    value,
                                                )
                                            }
                                        />
                                        <RuleChoices
                                            label="Priorities"
                                            values={PRIORITIES}
                                            selected={queueForm.data.priorities}
                                            onChange={(value) =>
                                                queueForm.setData(
                                                    'priorities',
                                                    value,
                                                )
                                            }
                                        />
                                        <fieldset className="rounded-xl border border-border p-3">
                                            <legend className="px-1 text-sm font-medium">
                                                Approved Sites
                                            </legend>
                                            <p className="mb-2 text-xs text-muted-foreground">
                                                Leave empty to match all active
                                                Sites. The manager and cover
                                                need approved access wherever
                                                the queue is used.
                                            </p>
                                            <div className="mt-1 max-h-36 space-y-1 overflow-y-auto">
                                                {sites.map((site) => (
                                                    <CheckChoice
                                                        key={site.id}
                                                        label={site.name}
                                                        checked={queueForm.data.site_ids.includes(
                                                            site.id,
                                                        )}
                                                        onChange={(checked) =>
                                                            queueForm.setData(
                                                                'site_ids',
                                                                checked
                                                                    ? [
                                                                          ...queueForm
                                                                              .data
                                                                              .site_ids,
                                                                          site.id,
                                                                      ]
                                                                    : queueForm.data.site_ids.filter(
                                                                          (
                                                                              id,
                                                                          ) =>
                                                                              id !==
                                                                              site.id,
                                                                      ),
                                                            )
                                                        }
                                                    />
                                                ))}
                                                {sites.length === 0 && (
                                                    <p className="text-sm text-muted-foreground">
                                                        No approved Sites are
                                                        available.
                                                    </p>
                                                )}
                                            </div>
                                        </fieldset>
                                        <fieldset className="rounded-xl border border-border p-3">
                                            <legend className="px-1 text-sm font-medium">
                                                Services
                                            </legend>
                                            <div className="mt-1 max-h-36 space-y-1 overflow-y-auto">
                                                {services.map((service) => (
                                                    <CheckChoice
                                                        key={service.id}
                                                        label={service.name}
                                                        checked={queueForm.data.service_ids.includes(
                                                            service.id,
                                                        )}
                                                        onChange={(checked) =>
                                                            queueForm.setData(
                                                                'service_ids',
                                                                checked
                                                                    ? [
                                                                          ...queueForm
                                                                              .data
                                                                              .service_ids,
                                                                          service.id,
                                                                      ]
                                                                    : queueForm.data.service_ids.filter(
                                                                          (
                                                                              id,
                                                                          ) =>
                                                                              id !==
                                                                              service.id,
                                                                      ),
                                                            )
                                                        }
                                                    />
                                                ))}
                                            </div>
                                        </fieldset>
                                    </div>
                                </WizardStepPane>
                            )}
                            {queueStep === 3 && (
                                <WizardStepPane>
                                    <div className="space-y-4">
                                        <h2 className="text-lg font-semibold">
                                            Review queue
                                        </h2>
                                        <p className="text-sm text-muted-foreground">
                                            Check ownership and the exact
                                            routing scope before saving.
                                        </p>
                                        <ReviewCard
                                            icon={Network}
                                            title="Queue details"
                                            onEdit={() => setQueueStep(0)}
                                        >
                                            <ReviewRow
                                                label="Name"
                                                value={queueForm.data.name}
                                            />
                                            <ReviewRow
                                                label="Stable key"
                                                value={queueForm.data.key}
                                            />
                                            <ReviewRow
                                                label="Description"
                                                value={
                                                    queueForm.data
                                                        .description || 'None'
                                                }
                                            />
                                            <ReviewRow
                                                label="Availability"
                                                value={
                                                    queueForm.data.is_active
                                                        ? 'Active'
                                                        : 'Inactive'
                                                }
                                            />
                                        </ReviewCard>
                                        <ReviewCard
                                            icon={UsersRound}
                                            title="Accountability"
                                            onEdit={() => setQueueStep(1)}
                                        >
                                            <ReviewRow
                                                label="Team"
                                                value={
                                                    queueTeam?.name ??
                                                    'Not configured'
                                                }
                                            />
                                            <ReviewRow
                                                label="Accountable manager"
                                                value={
                                                    queueTeam?.manager?.name ??
                                                    'Not configured'
                                                }
                                            />
                                            <ReviewRow
                                                label="Absence cover"
                                                value={
                                                    agents.find(
                                                        (agent) =>
                                                            String(agent.id) ===
                                                            queueForm.data
                                                                .cover_user_id,
                                                    )?.name ?? 'Not configured'
                                                }
                                            />
                                            <ReviewRow
                                                label="Default technician"
                                                value={
                                                    agents.find(
                                                        (agent) =>
                                                            String(agent.id) ===
                                                            queueForm.data
                                                                .default_assignee_user_id,
                                                    )?.name ?? 'Unassigned'
                                                }
                                            />
                                        </ReviewCard>
                                        <ReviewCard
                                            icon={Route}
                                            title="Routing rules"
                                            onEdit={() => setQueueStep(2)}
                                        >
                                            <ReviewRow
                                                label="Approved Sites"
                                                value={
                                                    queueForm.data.site_ids
                                                        .map(
                                                            (id) =>
                                                                sites.find(
                                                                    (site) =>
                                                                        site.id ===
                                                                        id,
                                                                )?.name ??
                                                                'Unavailable Site',
                                                        )
                                                        .join(', ') ||
                                                    'No Site-specific restriction'
                                                }
                                            />
                                            <ReviewRow
                                                label="Services"
                                                value={
                                                    queueForm.data.service_ids
                                                        .map(
                                                            (id) =>
                                                                services.find(
                                                                    (service) =>
                                                                        service.id ===
                                                                        id,
                                                                )?.name ??
                                                                'Unavailable service',
                                                        )
                                                        .join(', ') ||
                                                    'Any service'
                                                }
                                            />
                                            <ReviewRow
                                                label="Work types"
                                                value={
                                                    queueForm.data.work_types
                                                        .map(labels)
                                                        .join(', ') ||
                                                    'Any type'
                                                }
                                            />
                                            <ReviewRow
                                                label="Categories"
                                                value={
                                                    queueForm.data.categories
                                                        .map(labels)
                                                        .join(', ') ||
                                                    'Any category'
                                                }
                                            />
                                            <ReviewRow
                                                label="Priorities"
                                                value={
                                                    queueForm.data.priorities
                                                        .map(labels)
                                                        .join(', ') ||
                                                    'Any priority'
                                                }
                                            />
                                            <ReviewRow
                                                label="Routing order"
                                                value={
                                                    queueForm.data
                                                        .routing_priority
                                                }
                                            />
                                            <ReviewRow
                                                label="Fallback queue"
                                                value={
                                                    queueForm.data.is_default
                                                        ? 'Yes'
                                                        : 'No'
                                                }
                                            />
                                        </ReviewCard>
                                    </div>
                                </WizardStepPane>
                            )}
                        </fieldset>
                    </form>
                )}
            </WizardShell>
            <ConfirmDialog
                open={queueDiscardOpen}
                onClose={() => setQueueDiscardOpen(false)}
                title="Discard this queue draft?"
                description={
                    queueOutcomeUnknown || queueCreate.pending
                        ? 'A save may already have completed. Closing this draft does not undo a saved queue; review setup before creating another.'
                        : 'Your proposed changes will be discarded.'
                }
                confirmText="Discard draft"
                onConfirm={() => {
                    queueReviewAbort.current?.abort();
                    queueMemory.clearOwned();
                    setQueueOpen(false);
                }}
            />
        </AppLayout>
    );
}

function Field({
    label,
    className = '',
    children,
}: {
    label: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <label className={`space-y-1.5 text-sm font-medium ${className}`}>
            {label}
            {children}
        </label>
    );
}
function AgentSelect({
    name,
    invalid,
    label,
    value,
    agents,
    onChange,
}: {
    label: string;
    value: string;
    agents: Agent[];
    name?: string;
    invalid?: boolean;
    onChange: (value: string) => void;
}) {
    return (
        <NativeSelect
            name={name}
            invalid={invalid}
            label={label}
            value={value}
            onChange={onChange}
            options={[
                { value: '', label: 'Unassigned' },
                ...(value && !agents.some((agent) => String(agent.id) === value)
                    ? [
                          {
                              value,
                              label: 'Unavailable — choose another person',
                              disabled: true,
                          },
                      ]
                    : []),
                ...agents.map((agent) => ({
                    value: String(agent.id),
                    label: agent.name,
                })),
            ]}
        />
    );
}
function NativeSelect({
    name,
    invalid,
    label,
    value,
    onChange,
    options,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    options: Array<{ value: string; label: string; disabled?: boolean }>;
    name?: string;
    invalid?: boolean;
}) {
    return (
        <label className="space-y-1.5 text-sm font-medium">
            {label}
            <select
                name={name}
                aria-invalid={invalid}
                className="min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm"
                value={value}
                onChange={(event) => onChange(event.target.value)}
            >
                {options.map((option) => (
                    <option
                        key={`${label}-${option.value}`}
                        value={option.value}
                        disabled={option.disabled}
                    >
                        {option.label}
                    </option>
                ))}
            </select>
        </label>
    );
}
function ActiveToggle({
    checked,
    onChange,
}: {
    checked: boolean;
    onChange: (value: boolean) => void;
}) {
    return (
        <label className="flex min-h-11 items-center gap-2 text-sm font-medium">
            <input
                type="checkbox"
                checked={checked}
                onChange={(event) => onChange(event.target.checked)}
            />
            Active
        </label>
    );
}
function RuleChoices({
    label,
    values,
    selected,
    onChange,
}: {
    label: string;
    values: string[];
    selected: string[];
    onChange: (values: string[]) => void;
}) {
    return (
        <fieldset className="rounded-xl border border-border p-3">
            <legend className="px-1 text-sm font-medium">{label}</legend>
            <div className="mt-1 space-y-1">
                {values.map((value) => (
                    <CheckChoice
                        key={value}
                        label={labels(value)}
                        checked={selected.includes(value)}
                        onChange={(checked) =>
                            onChange(
                                checked
                                    ? [...selected, value]
                                    : selected.filter((item) => item !== value),
                            )
                        }
                    />
                ))}
            </div>
        </fieldset>
    );
}
function CheckChoice({
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
            <input
                type="checkbox"
                checked={checked}
                onChange={(event) => onChange(event.target.checked)}
            />
            {label}
        </label>
    );
}
function FormErrors({ errors }: { errors: Record<string, string> }) {
    const messages = Object.values(errors);
    return messages.length ? (
        <div
            role="alert"
            data-queue-errors
            tabIndex={-1}
            className="mt-4 rounded-xl border border-status-critical/30 bg-status-critical-bg p-3 text-sm text-status-critical"
        >
            <p className="font-semibold">
                Check the highlighted setup details.
            </p>
            <ul className="mt-1 list-disc pl-5">
                {messages.map((message) => (
                    <li key={message}>{message}</li>
                ))}
            </ul>
        </div>
    ) : null;
}
