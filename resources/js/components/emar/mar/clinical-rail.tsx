/* eslint-disable no-restricted-syntax -- the workflow rows are custom-layout
   <button> rows (icon + label + action affordance) that intentionally diverge
   from <Button>; all colours are semantic tokens. See the MAR handoff. */
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import {
    AlertTriangle,
    CalendarClock,
    FileCheck2,
    HeartPulse,
    Phone,
    Plus,
    ShieldAlert,
    Syringe,
} from 'lucide-react';

export type InrRecord = {
    id: number;
    medication_name?: string | null;
    inr_value: string | number;
    tested_on?: string | null;
    next_test_date?: string | null;
    target_range_min?: string | number | null;
    target_range_max?: string | number | null;
    medication_dose?: string | null;
    disabled_at?: string | null;
};

export type SyringeDriver = {
    id: number;
    status: string;
    commenced_at?: string | null;
    rate?: string | null;
    rate_unit?: string | null;
    site_of_insertion?: string | null;
};

export type RailAllergy = {
    id: number;
    allergen: string;
    severity?: string | null;
};
export type RailCondition = {
    id: number;
    label: string;
    severity?: string | null;
};
export type RailContact = {
    id: number;
    name: string;
    relationship?: string | null;
    phone?: string | null;
};

type Props = {
    inrRecords: InrRecord[];
    syringeDrivers: SyringeDriver[];
    awaitingVerification: number;
    pendingCorrections: number;
    chartReviewDate: string | null;
    allergies: RailAllergy[];
    conditions: RailCondition[];
    emergencyContacts: RailContact[];
    can: {
        manageInr: boolean;
        manageSyringeDrivers: boolean;
        verifyOrders: boolean;
        reviewCorrections: boolean;
    };
    onRecordInr: () => void;
    onStartDriver: () => void;
    onVerifyOrders: () => void;
    onReviewCorrections: () => void;
};

function daysUntil(dateStr: string | null): number | null {
    if (!dateStr) return null;
    const target = new Date(dateStr);
    if (Number.isNaN(target.getTime())) return null;
    const today = new Date();
    return Math.ceil(
        (target.getTime() - today.getTime()) / (1000 * 60 * 60 * 24),
    );
}

function severityChip(severity?: string | null): string {
    const s = (severity ?? '').toLowerCase();
    if (s.includes('severe') || s.includes('high'))
        return 'bg-status-critical-bg text-status-critical';
    if (s.includes('moderate') || s.includes('medium'))
        return 'bg-status-warning-bg text-status-warning';
    return 'bg-status-info-bg text-status-info';
}

function RailCard({ children }: { children: React.ReactNode }) {
    return (
        <div className="rounded-2xl border bg-card p-4 shadow-sm">
            {children}
        </div>
    );
}

function RailHead({
    icon: Icon,
    title,
    action,
}: {
    icon: typeof HeartPulse;
    title: string;
    action?: React.ReactNode;
}) {
    return (
        <div className="mb-3 flex items-center justify-between gap-2">
            <span className="flex items-center gap-2 text-[13.5px] font-bold">
                <Icon className="h-4 w-4 text-muted-foreground" />
                {title}
            </span>
            {action}
        </div>
    );
}

export default function ClinicalRail({
    inrRecords,
    syringeDrivers,
    awaitingVerification,
    pendingCorrections,
    chartReviewDate,
    allergies,
    conditions,
    emergencyContacts,
    can,
    onRecordInr,
    onStartDriver,
    onVerifyOrders,
    onReviewCorrections,
}: Props) {
    const latestInr =
        inrRecords.find((r) => !r.disabled_at) ?? inrRecords[0] ?? null;
    const reviewDays = daysUntil(chartReviewDate);
    const activeDrivers = syringeDrivers.filter(
        (d) => d.status === 'running' || d.status === 'active',
    );
    const contact = emergencyContacts[0] ?? null;
    const hasWorkflow =
        awaitingVerification > 0 ||
        pendingCorrections > 0 ||
        reviewDays !== null;

    return (
        <div className="flex flex-col gap-4">
            {/* Warfarin / INR */}
            <RailCard>
                <RailHead
                    icon={HeartPulse}
                    title="Warfarin / INR"
                    action={
                        can.manageInr ? (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={onRecordInr}
                            >
                                <Plus className="h-3.5 w-3.5" />
                                Record INR
                            </Button>
                        ) : undefined
                    }
                />
                {latestInr ? (
                    <div className="flex items-start gap-4">
                        <div>
                            <div className="text-[30px] leading-none font-bold text-status-critical">
                                {latestInr.inr_value}
                            </div>
                            <div className="mt-1 text-[11px] text-muted-foreground">
                                latest INR
                                {latestInr.tested_on
                                    ? ` · tested ${latestInr.tested_on}`
                                    : ''}
                            </div>
                        </div>
                        <div className="flex flex-col gap-1 border-l pl-4 text-xs">
                            {(latestInr.target_range_min ||
                                latestInr.target_range_max) && (
                                <span>
                                    Target {latestInr.target_range_min}–
                                    {latestInr.target_range_max}
                                </span>
                            )}
                            {latestInr.next_test_date && (
                                <span className="text-status-critical">
                                    Next test {latestInr.next_test_date}
                                </span>
                            )}
                            {latestInr.medication_dose && (
                                <span>Dose {latestInr.medication_dose}</span>
                            )}
                        </div>
                    </div>
                ) : (
                    <p className="text-xs text-muted-foreground">
                        No INR results recorded.
                    </p>
                )}
            </RailCard>

            {/* Syringe drivers */}
            <RailCard>
                <RailHead
                    icon={Syringe}
                    title="Syringe drivers"
                    action={
                        can.manageSyringeDrivers ? (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={onStartDriver}
                            >
                                <Plus className="h-3.5 w-3.5" />
                                Start driver
                            </Button>
                        ) : undefined
                    }
                />
                {activeDrivers.length === 0 ? (
                    <p className="text-xs text-muted-foreground">
                        No active drivers.
                    </p>
                ) : (
                    <ul className="flex flex-col gap-2 text-xs">
                        {activeDrivers.map((driver) => (
                            <li
                                key={driver.id}
                                className="flex items-center justify-between"
                            >
                                <span className="font-medium">
                                    {driver.site_of_insertion ?? 'Driver'}
                                </span>
                                <span className="text-muted-foreground">
                                    {[
                                        driver.rate
                                            ? `${driver.rate}${driver.rate_unit ?? ''}`
                                            : null,
                                        driver.status,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </RailCard>

            {/* Alerts & workflow */}
            {hasWorkflow && (
                <RailCard>
                    <RailHead icon={FileCheck2} title="Alerts &amp; workflow" />
                    <div className="flex flex-col gap-2">
                        {awaitingVerification > 0 && (
                            <button
                                type="button"
                                onClick={onVerifyOrders}
                                disabled={!can.verifyOrders}
                                className="flex items-center justify-between rounded-lg border px-3 py-2 text-left text-xs transition hover:bg-accent disabled:cursor-default disabled:opacity-70"
                            >
                                <span className="flex items-center gap-2">
                                    <ShieldAlert className="h-3.5 w-3.5 text-status-warning" />
                                    {awaitingVerification} order
                                    {awaitingVerification === 1 ? '' : 's'}{' '}
                                    awaiting verification
                                </span>
                                {can.verifyOrders && (
                                    <span className="font-medium text-primary">
                                        Verify
                                    </span>
                                )}
                            </button>
                        )}
                        {pendingCorrections > 0 && (
                            <button
                                type="button"
                                onClick={onReviewCorrections}
                                disabled={!can.reviewCorrections}
                                className="flex items-center justify-between rounded-lg border px-3 py-2 text-left text-xs transition hover:bg-accent disabled:cursor-default disabled:opacity-70"
                            >
                                <span className="flex items-center gap-2">
                                    <AlertTriangle className="h-3.5 w-3.5 text-status-warning" />
                                    {pendingCorrections} correction
                                    {pendingCorrections === 1 ? '' : 's'}{' '}
                                    pending review
                                </span>
                                {can.reviewCorrections && (
                                    <span className="font-medium text-primary">
                                        Review
                                    </span>
                                )}
                            </button>
                        )}
                        {reviewDays !== null && (
                            <div className="flex items-center gap-2 rounded-lg border px-3 py-2 text-xs text-muted-foreground">
                                <CalendarClock className="h-3.5 w-3.5" />
                                {reviewDays <= 0
                                    ? 'Chart review overdue'
                                    : `Chart review due in ${reviewDays} day${reviewDays === 1 ? '' : 's'}`}
                            </div>
                        )}
                    </div>
                </RailCard>
            )}

            {/* Clinical context */}
            <RailCard>
                <RailHead icon={HeartPulse} title="Clinical context" />
                <div className="flex flex-col gap-3 text-xs">
                    {allergies.length > 0 && (
                        <div>
                            <div className="mb-1 font-semibold text-muted-foreground">
                                Allergies
                            </div>
                            <div className="flex flex-wrap gap-1.5">
                                {allergies.map((allergy) => (
                                    <span
                                        key={allergy.id}
                                        className={cn(
                                            'rounded-full px-2 py-0.5 text-[11px] font-medium',
                                            severityChip(allergy.severity),
                                        )}
                                    >
                                        {allergy.allergen}
                                        {allergy.severity
                                            ? ` · ${allergy.severity}`
                                            : ''}
                                    </span>
                                ))}
                            </div>
                        </div>
                    )}
                    {conditions.length > 0 && (
                        <div>
                            <div className="mb-1 font-semibold text-muted-foreground">
                                Conditions
                            </div>
                            <div className="flex flex-wrap gap-1.5">
                                {conditions.map((condition) => (
                                    <span
                                        key={condition.id}
                                        className="rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground"
                                    >
                                        {condition.label}
                                    </span>
                                ))}
                            </div>
                        </div>
                    )}
                    {contact && (
                        <div className="flex items-center justify-between gap-2 rounded-lg border px-3 py-2">
                            <div>
                                <div className="font-medium">
                                    {contact.name}
                                </div>
                                <div className="text-[11px] text-muted-foreground">
                                    {[contact.relationship, contact.phone]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </div>
                            </div>
                            {contact.phone && (
                                <Button asChild variant="outline" size="sm">
                                    <a href={`tel:${contact.phone}`}>
                                        <Phone className="h-3.5 w-3.5" />
                                        Call
                                    </a>
                                </Button>
                            )}
                        </div>
                    )}
                </div>
            </RailCard>
        </div>
    );
}
