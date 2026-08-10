import { useCallback, useState } from 'react';

import { create as createShift } from '@/routes/operations/shifts';

import { CreateShiftDialog } from './create-shift-dialog';

type Client = {
    id: number;
    first_name: string;
    last_name: string;
    service_context_id?: number | null;
    site_id?: number | null;
};
type Staff = { id: number; name: string; email?: string };
type ServiceContext = {
    id: number;
    name: string;
    type: string;
    is_active: boolean;
};

type RoleShortage = {
    key: string;
    label?: string | null;
    required?: number | string | null;
    missing?: number | string | null;
};

type CoverageContext = {
    rule_id?: number | string | null;
    rule_name?: string | null;
    required_staff?: number | string | null;
    missing_staff?: number | string | null;
    site_id?: number | string | null;
    site_name?: string | null;
    preferred_client_id?: number | string | null;
    role_shortages?: RoleShortage[];
} | null;

/** The JSON payload returned by ShiftController@create (Accept: application/json). */
type CreateData = {
    clients: Client[];
    staff: Staff[];
    serviceContexts: ServiceContext[];
    defaultServiceContextId: number | null;
    defaultClientId: number | string | null;
    defaultSiteId: number | string | null;
    defaultUserId: number | string | null;
    defaultStartsAt: string | null;
    defaultEndsAt: string | null;
    defaultRepeatWeekly: boolean;
    defaultRepeatEndDate: string | null;
    coverageReservationToken: string | null;
    coverageContext: CoverageContext;
};

/** Query params accepted by the create endpoint — mirrors the old deep-link URL. */
export type CreateShiftParams = {
    site_id?: number | string | null;
    coverage_rule_id?: number | string | null;
    client_id?: number | string | null;
    starts_at?: string | null;
    ends_at?: string | null;
    coverage_rule_name?: string | null;
    coverage_required_staff?: number | string | null;
    coverage_missing_staff?: number | string | null;
    coverage_role_shortages?: string | null;
    coverage_reservation_token?: string | null;
    open_shift?: boolean;
    repeat_weekly?: boolean;
    repeat_end_date?: string | null;
    shift_type?: string | null;
    return_to?: string | null;
};

const numberOrNull = (value: number | string | null | undefined) =>
    value === null || value === undefined || value === ''
        ? null
        : Number(value);

/**
 * Opens the shared inline CreateShiftDialog from any "create a shift" entry
 * point. Fetches site-scoped clients/staff + coverage context as JSON from the
 * (retired-as-a-page) create endpoint, then hydrates the dialog — including the
 * coverage reservation token so saving still closes the gap. Returns the dialog
 * element to render once plus an `openWith(params)` trigger.
 */
export function useCreateShiftLauncher() {
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [data, setData] = useState<CreateData | null>(null);

    const openWith = useCallback(async (params: CreateShiftParams = {}) => {
        setError(null);
        setLoading(true);
        try {
            const query: Record<string, string> = {};
            for (const [key, value] of Object.entries(params)) {
                if (value === undefined || value === null || value === '') {
                    continue;
                }
                query[key] =
                    typeof value === 'boolean'
                        ? value
                            ? '1'
                            : '0'
                        : String(value);
            }
            const res = await fetch(createShift.url({ query }), {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            setData((await res.json()) as CreateData);
            setOpen(true);
        } catch {
            setError('Could not open the shift creator. Please try again.');
        } finally {
            setLoading(false);
        }
    }, []);

    const coverage = data?.coverageContext ?? null;
    // The create endpoint always returns a coverageContext object (falling back
    // to nulls when there's no gap), so only surface the locked "Coverage gap"
    // card when it carries real coverage intent — otherwise a plain create shows
    // an empty gap card.
    const hasCoverageContext =
        !!coverage &&
        (coverage.rule_id != null ||
            !!coverage.rule_name ||
            coverage.site_id != null ||
            coverage.missing_staff != null ||
            (coverage.role_shortages?.length ?? 0) > 0);
    const lockedContext = hasCoverageContext
        ? {
              site_name: coverage.site_name ?? null,
              window_label: coverage.rule_name ?? null,
              missing: coverage.missing_staff ?? null,
              role_shortages: coverage.role_shortages?.map((role) => ({
                  key: role.key,
                  label: role.label ?? null,
                  missing: role.missing ?? null,
              })),
          }
        : null;

    const dialog = data ? (
        <CreateShiftDialog
            open={open}
            onClose={() => setOpen(false)}
            clients={data.clients}
            staff={data.staff}
            serviceContexts={data.serviceContexts}
            defaultServiceContextId={data.defaultServiceContextId ?? null}
            defaultStartsAt={data.defaultStartsAt}
            defaultEndsAt={data.defaultEndsAt}
            defaultClientId={numberOrNull(data.defaultClientId)}
            defaultSiteId={numberOrNull(data.defaultSiteId)}
            defaultUserId={numberOrNull(data.defaultUserId)}
            defaultCoverageRoles={coverage?.role_shortages?.map((r) => r.key)}
            coverageReservationToken={data.coverageReservationToken}
            coverageRuleId={coverage?.rule_id ?? null}
            defaultRepeatWeekly={data.defaultRepeatWeekly}
            defaultRepeatEndDate={data.defaultRepeatEndDate}
            lockedContext={lockedContext}
        />
    ) : null;

    return { openWith, dialog, loading, error };
}
