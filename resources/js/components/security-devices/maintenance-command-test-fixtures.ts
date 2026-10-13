import {
    maintenanceCommandHash,
    type MaintenanceCommandReply,
    type MaintenanceCommandScope,
    type MaintenanceOperation,
} from './maintenance-command';
/** Synthetic only. Original application exports use their own unchanged bytes. */
export async function maintenanceCommandFixture(
    body: Record<string, unknown>,
    operation: MaintenanceOperation = 'create',
    target: number | null = null,
    scopeOverride?: MaintenanceCommandScope,
): Promise<MaintenanceCommandReply> {
    const scope = scopeOverride ?? {
        site_id: Number(body.site_id ?? 14),
        device_id: body.device_id ? Number(body.device_id) : null,
        monitor_id: body.monitor_id ? Number(body.monitor_id) : null,
        monitor_device_id: body.monitor_id ? 41 : null,
    };
    const window = {
        id: target ?? 21,
        version: operation === 'create' ? 1 : Number(body.version) + 1,
        state:
            operation === 'cancel'
                ? ('cancelled' as const)
                : ('active' as const),
    };
    return {
        window,
        current_window: { ...window },
        receipt: {
            schema_version: 1,
            owner: 'Monitoring',
            request_uuid: String(body.request_uuid),
            actor_id: Number(body.expected_actor_id),
            operation,
            target_window_id: target,
            request_hash: await maintenanceCommandHash(operation, target, body),
            committed_at: '2026-10-12T00:00:00+00:00',
            scope,
        },
    };
}
