import { ConfirmDialog } from '@/components/confirm-dialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import axios from 'axios';
import { Pencil, Plus, RefreshCw, Shield, Trash2, Users } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

export type SsoRole = { id: number; name: string; label: string | null };
export type SsoMapping = {
    id: number;
    version: string;
    provider: string;
    external_group_id: string;
    external_group_name: string;
    role_id: number;
    auto_assign: boolean;
    auto_remove: boolean;
    last_synced_at: string | null;
    role?: SsoRole;
};
type Fields = {
    provider: string;
    external_group_id: string;
    external_group_name: string;
    role_id: string;
    auto_assign: boolean;
    auto_remove: boolean;
    confirm_role_assignment: boolean;
    assignment_reason: string;
};
const emptyFields = (): Fields => ({
    provider: 'microsoft',
    external_group_id: '',
    external_group_name: '',
    role_id: '',
    auto_assign: false,
    auto_remove: false,
    confirm_role_assignment: false,
    assignment_reason: '',
});
function validMapping(value: unknown): value is SsoMapping {
    if (!value || typeof value !== 'object') return false;
    const m = value as SsoMapping;
    return (
        Number.isSafeInteger(m.id) &&
        m.id > 0 &&
        /^[a-f0-9]{64}$/.test(m.version) &&
        ['microsoft', 'google'].includes(m.provider) &&
        typeof m.external_group_id === 'string' &&
        typeof m.external_group_name === 'string' &&
        Number.isSafeInteger(m.role_id) &&
        typeof m.auto_assign === 'boolean' &&
        typeof m.auto_remove === 'boolean'
    );
}

export function SsoGroupMappings({
    mappings,
    roles,
    onChange,
    onAccessLost,
    onDirtyChange,
    provider = 'all',
}: {
    mappings: SsoMapping[];
    roles: SsoRole[];
    onChange?: (mappings: SsoMapping[]) => void;
    onAccessLost?: (reason: 'session' | 'access') => void;
    onDirtyChange?: (dirty: boolean) => void;
    provider?: string;
}) {
    const [rows, setRows] = useState(mappings);
    const [editor, setEditor] = useState<'new' | SsoMapping | null>(null);
    const [remove, setRemove] = useState<SsoMapping | null>(null);
    const [fields, setFields] = useState(emptyFields);
    const [step, setStep] = useState(0);
    const [confirmClose, setConfirmClose] = useState(false);
    const [savedMapping, setSavedMapping] = useState<SsoMapping | null>(null);
    const [pending, setPending] = useState(false);
    const [failure, setFailure] = useState<
        'validation' | 'unknown' | 'session' | 'access' | null
    >(null);
    const [message, setMessage] = useState('');
    const [groups, setGroups] = useState<
        { id: string; displayName?: string }[] | null
    >(null);
    const controller = useRef<AbortController | null>(null);
    const alive = useRef(true);
    const summary = useRef<HTMLDivElement>(null);
    const visible =
        provider === 'all'
            ? rows
            : rows.filter((row) => row.provider === provider);
    const concealed = failure === 'session' || failure === 'access';
    const blocked = pending || (!!failure && failure !== 'validation');
    const startsGranting =
        fields.auto_assign &&
        (editor === 'new' ||
            !editor?.auto_assign ||
            editor.role_id !== Number(fields.role_id));
    useEffect(() => {
        onDirtyChange?.(!!editor || !!remove || pending || !!failure);
        return () => onDirtyChange?.(false);
    }, [editor, remove, pending, failure, onDirtyChange]);
    useEffect(() => {
        setRows(mappings);
    }, [mappings]);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
            controller.current?.abort();
        };
    }, []);
    useEffect(() => {
        if (failure) summary.current?.focus();
    }, [failure, message]);
    function close() {
        if (pending) return;
        if (savedMapping) {
            setSavedMapping(null);
            return;
        }
        if (editor || failure) {
            setConfirmClose(true);
            return;
        }
        discard();
    }
    function discard() {
        setEditor(null);
        setRemove(null);
        setFields(emptyFields());
    }
    function start(mapping: SsoMapping | 'new') {
        setSavedMapping(null);
        setEditor(mapping);
        setStep(0);
        setFailure(null);
        setMessage('');
        setFields(
            mapping === 'new'
                ? emptyFields()
                : {
                      ...emptyFields(),
                      ...mapping,
                      role_id: String(mapping.role_id),
                  },
        );
    }
    function changeRows(next: SsoMapping[]) {
        setRows(next);
        onChange?.(next);
    }
    async function command(kind: 'save' | 'remove' | 'recover' | 'fetch') {
        if (pending || (blocked && kind !== 'recover')) return;
        const abort = new AbortController();
        controller.current = abort;
        setPending(true);
        setMessage('');
        try {
            const options = {
                signal: abort.signal,
                timeout: 20000,
                headers: { Accept: 'application/json' },
            };
            if (kind === 'recover') {
                const response = await axios.get(
                    '/settings/sso-groups',
                    options,
                );
                if (
                    !Array.isArray(response.data?.mappings) ||
                    !response.data.mappings.every(validMapping)
                )
                    throw new Error('Invalid rules response.');
                if (!alive.current) return;
                changeRows(response.data.mappings);
                setEditor(null);
                setRemove(null);
                setFields(emptyFields());
                setGroups(null);
                setFailure(null);
                setMessage(
                    'Current saved rules loaded. Review them before starting another edit; no command was repeated.',
                );
            } else if (kind === 'fetch') {
                const response = await axios.post(
                    '/settings/sso-groups/fetch',
                    {},
                    options,
                );
                if (
                    response.data?.status !== 'fetched' ||
                    !Array.isArray(response.data.groups) ||
                    !response.data.groups.every(
                        (group: { id?: unknown }) =>
                            typeof group.id === 'string',
                    )
                )
                    throw new Error('Invalid directory response.');
                if (!alive.current) return;
                setGroups(response.data.groups);
                setFailure(null);
                setMessage(
                    `${response.data.groups.length} directory groups returned. No role synchronization was performed.`,
                );
            } else if (kind === 'remove' && remove) {
                const response = await axios.delete(
                    `/settings/sso-groups/${remove.id}`,
                    { ...options, data: { expected_version: remove.version } },
                );
                if (
                    response.data?.status !== 'removed' ||
                    response.data?.mapping_id !== remove.id
                )
                    throw new Error('Unconfirmed removal.');
                if (!alive.current) return;
                changeRows(rows.filter((row) => row.id !== remove.id));
                setRemove(null);
                setFailure(null);
                setMessage(
                    'Group mapping removed. Existing user roles were not changed.',
                );
            } else if (kind === 'save' && editor) {
                const response =
                    editor === 'new'
                        ? await axios.post(
                              '/settings/sso-groups',
                              fields,
                              options,
                          )
                        : await axios.put(
                              `/settings/sso-groups/${editor.id}`,
                              {
                                  role_id: fields.role_id,
                                  auto_assign: fields.auto_assign,
                                  auto_remove: fields.auto_remove,
                                  confirm_role_assignment:
                                      fields.confirm_role_assignment,
                                  assignment_reason: fields.assignment_reason,
                                  expected_version: editor.version,
                              },
                              options,
                          );
                if (
                    response.data?.status !== 'saved' ||
                    !validMapping(response.data.mapping) ||
                    (editor !== 'new' && response.data.mapping.id !== editor.id)
                )
                    throw new Error('Unconfirmed save.');
                if (!alive.current) return;
                const saved = response.data.mapping as SsoMapping;
                changeRows([
                    ...rows.filter((row) => row.id !== saved.id),
                    saved,
                ]);
                setEditor(null);
                setFields(emptyFields());
                setFailure(null);
                setSavedMapping(saved);
                setMessage(
                    'Group mapping saved. Existing user roles were not changed.',
                );
            }
        } catch (error) {
            if (!alive.current) return;
            const status = axios.isAxiosError(error)
                ? error.response?.status
                : undefined;
            if (status === 401 || status === 419 || status === 403) {
                onAccessLost?.(status === 403 ? 'access' : 'session');
                setFailure(status === 403 ? 'access' : 'session');
                setRows([]);
                setGroups(null);
                setFields(emptyFields());
                setEditor(null);
                setRemove(null);
                setSavedMapping(null);
                setConfirmClose(false);
                setMessage(
                    status === 403
                        ? 'Your current access does not allow group mapping. Records are concealed.'
                        : 'Your session expired. Sign in again, then reload current saved rules.',
                );
            } else if (status === 422) {
                const data = axios.isAxiosError(error)
                    ? error.response?.data
                    : null;
                setFailure('validation');
                setMessage(
                    data?.errors
                        ? Object.values(data.errors).flat().join(' ')
                        : data?.message || 'Review the group and role fields.',
                );
            } else {
                setFailure('unknown');
                setGroups(null);
                setMessage(
                    kind === 'fetch'
                        ? 'The directory request did not complete. No membership or role result is available. Reload current rules before retrying.'
                        : 'The command outcome is unconfirmed or the rule changed. Reload current saved rules before retrying. Cancelling the wait does not undo a save.',
                );
            }
        } finally {
            if (alive.current) setPending(false);
        }
    }
    const waiting = pending && (
        <div role="status" className="flex items-center gap-3">
            Waiting for a response…
            <Button
                variant="outline"
                onClick={() => controller.current?.abort()}
            >
                Cancel wait
            </Button>
        </div>
    );
    const feedback = message && (
        <Alert
            ref={summary}
            tabIndex={-1}
            variant={failure ? 'destructive' : 'default'}
            role={failure ? 'alert' : 'status'}
        >
            <AlertTitle>Group mapping status</AlertTitle>
            <AlertDescription>
                <p>{message}</p>
                {failure === 'session' && (
                    <Button asChild variant="outline">
                        <a
                            href="/login"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            Sign in again
                        </a>
                    </Button>
                )}
                {failure && failure !== 'validation' && (
                    <Button
                        variant="outline"
                        disabled={pending}
                        onClick={() => void command('recover')}
                    >
                        Reload saved rules and discard form entries
                    </Button>
                )}
            </AlertDescription>
        </Alert>
    );
    return (
        <div className="space-y-5">
            {!editor && !remove && feedback}
            <Alert>
                <AlertDescription>
                    These are saved role-mapping rules. Automatic directory
                    synchronization is not enabled. Microsoft directory reads
                    require separately approved Graph access; Google group
                    synchronization is not integrated.
                </AlertDescription>
            </Alert>
            {!concealed && (
                <Card>
                    <CardHeader>
                        <div className="flex items-start justify-between gap-5">
                            <div>
                                <CardTitle>Group mapping</CardTitle>
                                <CardDescription>
                                    {visible.length} saved rules in this view.
                                    Membership failure never proves that someone
                                    left a group.
                                </CardDescription>
                            </div>
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    disabled={blocked}
                                    onClick={() => void command('fetch')}
                                >
                                    <RefreshCw />
                                    Fetch Microsoft groups
                                </Button>
                                <Button
                                    disabled={blocked}
                                    onClick={() => start('new')}
                                >
                                    <Plus />
                                    Add mapping
                                </Button>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {visible.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No group mappings match this view.
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Group</TableHead>
                                        <TableHead>Provider</TableHead>
                                        <TableHead>Role</TableHead>
                                        <TableHead>Assignment</TableHead>
                                        <TableHead>Removal</TableHead>
                                        <TableHead>Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {visible.map((row) => (
                                        <TableRow key={row.id}>
                                            <TableCell>
                                                <div>
                                                    {row.external_group_name}
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    {row.external_group_id}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                {row.provider}
                                            </TableCell>
                                            <TableCell>
                                                {roles.find(
                                                    (role) =>
                                                        role.id === row.role_id,
                                                )?.label ||
                                                    roles.find(
                                                        (role) =>
                                                            role.id ===
                                                            row.role_id,
                                                    )?.name ||
                                                    'Role unavailable'}
                                            </TableCell>
                                            <TableCell>
                                                {row.auto_assign
                                                    ? 'Configured'
                                                    : 'Disabled'}
                                            </TableCell>
                                            <TableCell>
                                                {row.auto_remove
                                                    ? 'Configured'
                                                    : 'Disabled'}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex gap-2">
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        aria-label={`Edit ${row.external_group_name}`}
                                                        disabled={blocked}
                                                        onClick={() =>
                                                            start(row)
                                                        }
                                                    >
                                                        <Pencil />
                                                        Edit
                                                    </Button>
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        aria-label={`Remove ${row.external_group_name}`}
                                                        disabled={blocked}
                                                        onClick={() => {
                                                            setRemove(row);
                                                            setMessage('');
                                                            setFailure(null);
                                                        }}
                                                    >
                                                        <Trash2 />
                                                        Remove
                                                    </Button>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            )}
            {groups && !concealed && (
                <Card>
                    <CardHeader>
                        <CardTitle>Returned directory groups</CardTitle>
                        <CardDescription>
                            Use a verified group ID when preparing a mapping.
                            These results do not change access.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {groups.length ? (
                            <ul className="space-y-2">
                                {groups.map((group) => (
                                    <li key={group.id}>
                                        {group.displayName || 'Unnamed group'} —{' '}
                                        <span className="text-sm text-muted-foreground">
                                            {group.id}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p>No groups were returned by the directory.</p>
                        )}
                    </CardContent>
                </Card>
            )}
            <WizardShell
                open={(!!editor || !!savedMapping) && !concealed}
                onClose={close}
                title={
                    savedMapping
                        ? 'Group mapping saved'
                        : editor === 'new'
                          ? 'Add group mapping'
                          : 'Edit group mapping'
                }
                description="Prepare a role mapping and explicitly review any assignment it may grant."
                railIcon={Shield}
                railTitle="Group mapping"
                railSub="Review access intent"
                steps={[
                    {
                        key: 'group',
                        label: 'Group',
                        blurb: 'Provider identity',
                        icon: Users,
                    },
                    {
                        key: 'role',
                        label: 'Role and review',
                        blurb: 'Access intent',
                        icon: Shield,
                    },
                ]}
                stepIndex={step}
                onStepClick={(index) => {
                    if (!blocked) setStep(index);
                }}
                success={
                    savedMapping ? (
                        <WizardSuccessPane
                            title="Group mapping saved"
                            blurb={`${savedMapping.external_group_name} is saved. Existing user roles were not changed; directory synchronization has not run.`}
                            actions={
                                <>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={close}
                                    >
                                        Done
                                    </Button>
                                    <Button
                                        type="button"
                                        onClick={() => start('new')}
                                    >
                                        Add another mapping
                                    </Button>
                                </>
                            }
                        />
                    ) : undefined
                }
                footerStart={
                    <Button
                        variant="outline"
                        disabled={pending}
                        onClick={close}
                    >
                        Cancel
                    </Button>
                }
                footerEnd={
                    step === 0 ? (
                        <Button
                            disabled={
                                blocked ||
                                !fields.external_group_id.trim() ||
                                !fields.external_group_name.trim()
                            }
                            onClick={() => setStep(1)}
                        >
                            Continue
                        </Button>
                    ) : (
                        <Button
                            disabled={
                                blocked ||
                                !fields.role_id ||
                                (startsGranting &&
                                    (!fields.confirm_role_assignment ||
                                        !fields.assignment_reason.trim()))
                            }
                            onClick={() => void command('save')}
                        >
                            Save mapping
                        </Button>
                    )
                }
            >
                <WizardStepPane>
                    {feedback}
                    {waiting}
                    <fieldset disabled={blocked} className="space-y-5">
                        {step === 0 ? (
                            <>
                                <Label htmlFor="mapping-provider">
                                    Provider
                                </Label>
                                <Select
                                    value={fields.provider}
                                    disabled={editor !== 'new'}
                                    onValueChange={(value) =>
                                        setFields({
                                            ...fields,
                                            provider: value,
                                        })
                                    }
                                >
                                    <SelectTrigger id="mapping-provider">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="microsoft">
                                            Microsoft
                                        </SelectItem>
                                        <SelectItem value="google">
                                            Google
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <Label htmlFor="mapping-group-id">
                                    External group ID
                                </Label>
                                <Input
                                    id="mapping-group-id"
                                    disabled={editor !== 'new'}
                                    value={fields.external_group_id}
                                    onChange={(event) =>
                                        setFields({
                                            ...fields,
                                            external_group_id:
                                                event.target.value,
                                        })
                                    }
                                />
                                <Label htmlFor="mapping-group-name">
                                    Group name
                                </Label>
                                <Input
                                    id="mapping-group-name"
                                    disabled={editor !== 'new'}
                                    value={fields.external_group_name}
                                    onChange={(event) =>
                                        setFields({
                                            ...fields,
                                            external_group_name:
                                                event.target.value,
                                        })
                                    }
                                />
                            </>
                        ) : (
                            <>
                                <Label htmlFor="mapping-role">
                                    Application role
                                </Label>
                                <Select
                                    value={fields.role_id}
                                    onValueChange={(value) =>
                                        setFields({
                                            ...fields,
                                            role_id: value,
                                            confirm_role_assignment: false,
                                        })
                                    }
                                >
                                    <SelectTrigger id="mapping-role">
                                        <SelectValue placeholder="Select a role" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {roles.map((role) => (
                                            <SelectItem
                                                key={role.id}
                                                value={String(role.id)}
                                            >
                                                {role.label || role.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <div className="flex items-center justify-between">
                                    <Label htmlFor="mapping-assign">
                                        Assign role when membership is verified
                                    </Label>
                                    <Switch
                                        id="mapping-assign"
                                        checked={fields.auto_assign}
                                        onCheckedChange={(value) =>
                                            setFields({
                                                ...fields,
                                                auto_assign: value,
                                                confirm_role_assignment: false,
                                            })
                                        }
                                    />
                                </div>
                                <div className="flex items-center justify-between">
                                    <Label htmlFor="mapping-remove">
                                        Remove role after verified group
                                        departure
                                    </Label>
                                    <Switch
                                        id="mapping-remove"
                                        checked={fields.auto_remove}
                                        onCheckedChange={(value) =>
                                            setFields({
                                                ...fields,
                                                auto_remove: value,
                                            })
                                        }
                                    />
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    Saving this rule does not run
                                    synchronization or change existing user
                                    roles.
                                </p>
                                {startsGranting && (
                                    <>
                                        <Label htmlFor="mapping-reason">
                                            Reason for granting this role
                                        </Label>
                                        <Textarea
                                            id="mapping-reason"
                                            maxLength={1000}
                                            value={fields.assignment_reason}
                                            onChange={(event) =>
                                                setFields({
                                                    ...fields,
                                                    assignment_reason:
                                                        event.target.value,
                                                })
                                            }
                                        />
                                        <div className="flex gap-2">
                                            <Checkbox
                                                id="mapping-confirm"
                                                checked={
                                                    fields.confirm_role_assignment
                                                }
                                                onCheckedChange={(value) =>
                                                    setFields({
                                                        ...fields,
                                                        confirm_role_assignment:
                                                            value === true,
                                                    })
                                                }
                                            />
                                            <Label htmlFor="mapping-confirm">
                                                I reviewed this role and the
                                                group allowed to receive it.
                                            </Label>
                                        </div>
                                    </>
                                )}
                            </>
                        )}
                    </fieldset>
                    {step === 1 && (
                        <ReviewCard
                            icon={Shield}
                            title="Mapping to save"
                            onEdit={blocked ? undefined : () => setStep(0)}
                        >
                            <ReviewRow
                                label="Provider"
                                value={
                                    fields.provider === 'microsoft'
                                        ? 'Microsoft'
                                        : 'Google'
                                }
                            />
                            <ReviewRow
                                label="Group"
                                value={fields.external_group_name}
                            />
                            <ReviewRow
                                label="External group ID"
                                value={
                                    <span className="break-all">
                                        {fields.external_group_id}
                                    </span>
                                }
                            />
                            <ReviewRow
                                label="Application role"
                                value={
                                    roles.find(
                                        (role) =>
                                            String(role.id) === fields.role_id,
                                    )?.label ||
                                    roles.find(
                                        (role) =>
                                            String(role.id) === fields.role_id,
                                    )?.name
                                }
                            />
                            <ReviewRow
                                label="Assignment"
                                value={
                                    fields.auto_assign
                                        ? 'Only after verified membership'
                                        : 'Disabled'
                                }
                            />
                            <ReviewRow
                                label="Removal"
                                value={
                                    fields.auto_remove
                                        ? 'Only after verified departure'
                                        : 'Disabled'
                                }
                            />
                        </ReviewCard>
                    )}
                </WizardStepPane>
            </WizardShell>
            <Dialog
                open={!!remove && !concealed}
                onOpenChange={(open) => {
                    if (!open) close();
                }}
            >
                <DialogContent
                    className="max-h-[90vh] overflow-y-auto"
                    style={{
                        width: 'min(92vw, 480px)',
                        maxWidth: 'min(92vw, 480px)',
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>Remove group mapping?</DialogTitle>
                        <DialogDescription>
                            {remove?.external_group_name} will be removed from
                            the saved rules. Existing user roles remain
                            unchanged.
                        </DialogDescription>
                    </DialogHeader>
                    {feedback}
                    {waiting}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={pending}
                            onClick={close}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            disabled={blocked}
                            onClick={() => void command('remove')}
                        >
                            Remove mapping
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <ConfirmDialog
                open={confirmClose && !concealed}
                onClose={() => setConfirmClose(false)}
                onConfirm={discard}
                title="Discard this mapping draft?"
                description="Your unsaved mapping entries will be discarded. A command already sent may still complete; reload saved rules before trying again. Cancel to keep editing."
                confirmText="Discard draft"
            />
            {pending && !editor && !remove && (
                <div role="status" className="flex items-center gap-3">
                    Waiting for a response…
                    <Button
                        variant="outline"
                        onClick={() => controller.current?.abort()}
                    >
                        Cancel wait
                    </Button>
                </div>
            )}
        </div>
    );
}
