import type {
    NativeMonitorManagement,
    NativeMonitorTarget,
} from './native-monitor-dialogs';
export type NativeMonitorDraft = {
    deviceId: string;
    profileId: string;
    kind: string;
    name: string;
    target: string;
    port: string;
    dnsName: string;
    dnsType: string;
    expectedAnswers: string;
    expectedStatus: string;
    warnDays: string;
    credentialReference: string;
    inventoryProfile: string;
    hostKeyFingerprint: string;
    affectsAvailability: boolean;
};
export type NativeMonitorAttempt = {
    uuid: string;
    actor: number;
    site: number;
    device: number;
    operation: 'create' | 'update' | 'deactivate';
    target: number | null;
    payload: Record<string, unknown>;
    expected: {
        name?: string;
        kind?: string;
        profile?: number;
        availability?: boolean;
        enabled: boolean;
    };
};
export type SavedNativeMonitor = {
    id: number;
    name: string;
    kind: string;
    device_id: number;
    profile_id: number;
    enabled: boolean;
    affects_availability: boolean;
    collection_mode: string;
};
export type NativeMonitorAcknowledgement = {
    monitor: SavedNativeMonitor;
    current_monitor: SavedNativeMonitor;
    receipt: {
        schema_version: number;
        owner: string;
        request_uuid: string;
        actor_id: number;
        operation: string;
        target_monitor_id: number | null;
        monitor_id: number;
        device_id: number;
        site_id: number;
        committed_at: string;
        current_matches_saved: boolean;
        confirms: string;
        confirms_observation: boolean;
    };
};
export const nativeKinds = [
    'icmp',
    'tcp',
    'dns',
    'http',
    'tls',
    'snmp',
    'ssh_inventory',
    'winrm_inventory',
] as const;
export function initialNativeDraft(
    management: NativeMonitorManagement,
    monitor: NativeMonitorTarget | null,
): NativeMonitorDraft {
    return {
        deviceId: String(
            monitor?.device.id ??
                (management.devices.length === 1
                    ? management.devices[0].id
                    : ''),
        ),
        profileId: String(
            monitor?.profile?.id ??
                (management.profiles.length === 1
                    ? management.profiles[0].id
                    : ''),
        ),
        kind:
            monitor?.kind ??
            (management.kinds.some((k) => k.value === 'icmp')
                ? 'icmp'
                : (management.kinds[0]?.value ?? '')),
        name: monitor?.name ?? '',
        target: '',
        port: '',
        dnsName: '',
        dnsType: monitor ? '' : 'A',
        expectedAnswers: '',
        expectedStatus: monitor ? '' : '200',
        warnDays: monitor ? '' : '30',
        credentialReference: '',
        inventoryProfile: '',
        hostKeyFingerprint: '',
        affectsAvailability: monitor?.affects_availability ?? false,
    };
}
export const nativeLines = (value: string) =>
    value
        .split(/[\n,]/)
        .map((v) => v.trim())
        .filter(Boolean);
const integer = (value: string, min: number, max: number) =>
    /^\d+$/.test(value) &&
    Number.isSafeInteger(Number(value)) &&
    Number(value) >= min &&
    Number(value) <= max;
const hasControlCharacters = (value: string) =>
    [...value].some(
        (character) =>
            character.charCodeAt(0) < 32 || character.charCodeAt(0) === 127,
    );
export function nativeDraftErrors(
    d: NativeMonitorDraft,
    management: NativeMonitorManagement,
    monitor: NativeMonitorTarget | null,
    through = 2,
): Record<string, string> {
    const errors: Record<string, string> = {},
        editing = monitor !== null;
    if (
        !nativeKinds.includes(d.kind as (typeof nativeKinds)[number]) ||
        (!editing && !management.kinds.some((k) => k.value === d.kind))
    )
        errors.kind = 'Choose a supported check type.';
    if (through >= 1) {
        if (
            !editing &&
            !management.devices.some((item) => String(item.id) === d.deviceId)
        )
            errors.device_id = 'Choose a current approved device.';
        if (
            !management.profiles.some((item) => String(item.id) === d.profileId)
        )
            errors.profile_id = 'Choose a current policy profile.';
        if (
            d.name.trim().length < 3 ||
            d.name.trim().length > 128 ||
            hasControlCharacters(d.name)
        )
            errors.name =
                'Use a name of 3 to 128 characters without control characters.';
    }
    if (through >= 2) {
        if (
            (!editing && !d.target.trim()) ||
            d.target.length > 8192 ||
            hasControlCharacters(d.target)
        )
            errors.target = editing
                ? 'Enter a valid replacement target, or leave it blank.'
                : 'Enter the approved target.';
        if (
            (d.kind === 'tcp' && !editing && !d.port) ||
            (d.port && !integer(d.port, 1, 65535))
        )
            errors.port = 'Use a port from 1 to 65,535.';
        if (d.kind === 'dns') {
            if (
                (!editing && !d.dnsName.trim()) ||
                d.dnsName.length > 253 ||
                hasControlCharacters(d.dnsName)
            )
                errors.dns_name =
                    'Enter a DNS name of 253 characters or fewer.';
            if (
                (!editing || d.dnsType) &&
                !['A', 'AAAA', 'CNAME', 'MX', 'TXT'].includes(d.dnsType)
            )
                errors.dns_type = 'Choose a supported DNS answer type.';
            const answers = nativeLines(d.expectedAnswers);
            if (
                answers.length > 64 ||
                answers.some((v) => v.length > 1024 || hasControlCharacters(v))
            )
                errors.expected_answers =
                    'Use up to 64 valid expected answers.';
        }
        if (d.kind === 'http' && (!editing || d.expectedStatus.trim())) {
            const statuses = nativeLines(d.expectedStatus);
            if (
                !statuses.length ||
                statuses.length > 20 ||
                statuses.some((v) => !integer(v, 100, 599))
            )
                errors.expected_status =
                    'Use 1 to 20 HTTP status codes from 100 to 599.';
        }
        if (
            d.kind === 'tls' &&
            (!editing || d.warnDays) &&
            !integer(d.warnDays, 1, 365)
        )
            errors.warn_days = 'Use 1 to 365 days.';
        if (
            ['snmp', 'ssh_inventory', 'winrm_inventory'].includes(d.kind) &&
            (!editing || d.credentialReference.trim()) &&
            !/^[a-z][a-z0-9._-]{1,31}:[A-Za-z0-9._/:@-]{1,158}$/.test(
                d.credentialReference.trim(),
            )
        )
            errors.credential_reference =
                'Enter the approved Site credential reference.';
        if (
            ['ssh_inventory', 'winrm_inventory'].includes(d.kind) &&
            (!editing || d.inventoryProfile) &&
            d.inventoryProfile !==
                (d.kind === 'ssh_inventory' ? 'linux.basic' : 'windows.basic')
        )
            errors.inventory_profile =
                'Choose the approved read-only inventory profile.';
        if (
            d.kind === 'ssh_inventory' &&
            (!editing || d.hostKeyFingerprint.trim()) &&
            !/^SHA256:[A-Za-z0-9+/]{43}$/.test(d.hostKeyFingerprint.trim())
        )
            errors.host_key_fingerprint =
                'Enter the approved SHA256 host-key fingerprint.';
    }
    return errors;
}
export function nativePayload(
    d: NativeMonitorDraft,
    monitor: NativeMonitorTarget | null,
): Record<string, unknown> {
    const body: Record<string, unknown> = {
        profile_id: Number(d.profileId),
        name: d.name.trim(),
        affects_availability: d.affectsAvailability,
    };
    if (!monitor) {
        body.device_id = Number(d.deviceId);
        body.kind = d.kind;
    }
    if (d.target.trim()) body.target = d.target.trim();
    if (d.port) body.port = Number(d.port);
    if (d.kind === 'dns') {
        if (d.dnsName.trim()) body.dns_name = d.dnsName.trim();
        if (d.dnsType) body.dns_type = d.dnsType;
        if (d.expectedAnswers.trim())
            body.expected_answers = nativeLines(d.expectedAnswers);
    }
    if (d.kind === 'http' && d.expectedStatus.trim())
        body.expected_status = nativeLines(d.expectedStatus).map(Number);
    if (d.kind === 'tls' && d.warnDays) body.warn_days = Number(d.warnDays);
    if (
        ['snmp', 'ssh_inventory', 'winrm_inventory'].includes(d.kind) &&
        d.credentialReference.trim()
    )
        body.credential_reference = d.credentialReference.trim();
    if (
        ['ssh_inventory', 'winrm_inventory'].includes(d.kind) &&
        d.inventoryProfile
    )
        body.inventory_profile = d.inventoryProfile;
    if (d.kind === 'ssh_inventory' && d.hostKeyFingerprint.trim())
        body.host_key_fingerprint = d.hostKeyFingerprint.trim();
    return body;
}
export function nativeErrorStep(key: string) {
    return ['kind'].includes(key)
        ? 0
        : [
                'device_id',
                'profile_id',
                'name',
                'expected_site_id',
                'expected_device_id',
            ].includes(key)
          ? 1
          : 2;
}
export function confirmedNativeMonitor(
    value: unknown,
    attempt: NativeMonitorAttempt,
): NativeMonitorAcknowledgement {
    if (!value || typeof value !== 'object')
        throw Error('Monitor save was not confirmed.');
    const reply = value as NativeMonitorAcknowledgement,
        r = reply.receipt,
        m = reply.monitor,
        c = reply.current_monitor;
    if (
        !r ||
        !m ||
        !c ||
        r.schema_version !== 1 ||
        r.owner !== 'Native monitoring' ||
        r.request_uuid !== attempt.uuid ||
        r.actor_id !== attempt.actor ||
        r.operation !== attempt.operation ||
        r.target_monitor_id !== attempt.target ||
        r.device_id !== attempt.device ||
        r.site_id !== attempt.site ||
        r.monitor_id !== m.id ||
        !Number.isSafeInteger(m.id) ||
        m.id <= 0 ||
        (attempt.target !== null && m.id !== attempt.target) ||
        m.device_id !== attempt.device ||
        c.id !== m.id ||
        c.device_id !== m.device_id ||
        typeof r.current_matches_saved !== 'boolean' ||
        r.confirms !== 'configuration_saved' ||
        r.confirms_observation !== false ||
        typeof r.committed_at !== 'string' ||
        !/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]+)?(?:Z|[+-][0-9]{2}:[0-9]{2})$/.test(
            r.committed_at,
        ) ||
        !Number.isFinite(Date.parse(r.committed_at)) ||
        typeof m.name !== 'string' ||
        !nativeKinds.includes(m.kind as (typeof nativeKinds)[number]) ||
        !Number.isSafeInteger(m.profile_id) ||
        m.profile_id <= 0 ||
        typeof m.enabled !== 'boolean' ||
        typeof m.affects_availability !== 'boolean' ||
        m.collection_mode !== 'central_direct' ||
        m.enabled !== attempt.expected.enabled
    )
        throw Error('The receipt does not match this saved monitor request.');
    for (const [key, expected] of Object.entries({
        name: attempt.expected.name,
        kind: attempt.expected.kind,
        profile_id: attempt.expected.profile,
        affects_availability: attempt.expected.availability,
    }))
        if (
            expected !== undefined &&
            m[key as keyof SavedNativeMonitor] !== expected
        )
            throw Error('The saved monitor fields do not match this request.');
    if (
        typeof c.name !== 'string' ||
        !nativeKinds.includes(c.kind as (typeof nativeKinds)[number]) ||
        !Number.isSafeInteger(c.profile_id) ||
        c.profile_id <= 0 ||
        typeof c.enabled !== 'boolean' ||
        typeof c.affects_availability !== 'boolean' ||
        c.collection_mode !== 'central_direct'
    )
        throw Error('The current monitor context is incomplete.');
    if (
        r.current_matches_saved &&
        Object.keys(m).some(
            (key) =>
                m[key as keyof SavedNativeMonitor] !==
                c[key as keyof SavedNativeMonitor],
        )
    )
        throw Error('The current monitor does not match the receipt snapshot.');
    return reply;
}
