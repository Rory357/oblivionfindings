/**
 * Site contact rows: "Someone who works here" (picked from the staff list,
 * details from their staff record) or "Someone outside the organisation"
 * (typed in). Shared by the Add / Edit site pop-up and Site profile ›
 * Contacts (to-do #10).
 *
 * Staff details are work details only — job title, work phone and work email
 * from HR. A missing work phone or email shows as missing; it is never filled
 * from a personal number or the sign-in email (DESIGN.md "Personal contact
 * details copied into work fields").
 */
import { PeoplePicker, type PersonOption } from '@/components/hr/people-picker';
import { Button } from '@/components/ui/button';
import { Segmented } from '@/components/wizard/primitives';
import { BadgeCheck, Building2, RotateCcw, UserRound } from 'lucide-react';

export type ContactSource = 'staff' | 'outside';

/** One person the viewer may pick, with their work details. */
export type SiteStaffOption = {
    id: number;
    name: string;
    role?: string | null;
    phone?: string | null;
    email?: string | null;
};

/** Types that start as "Someone who works here" (SiteContact::STAFF_DEFAULT_TYPES). */
const STAFF_DEFAULT_TYPES = [
    'site_contact',
    'site_lead',
    'team_lead',
    'manager',
    'clinical',
];

export function defaultContactSource(type: string): ContactSource {
    return STAFF_DEFAULT_TYPES.includes(type) ? 'staff' : 'outside';
}

export const CONTACT_SOURCE_OPTIONS: {
    value: ContactSource;
    label: string;
    icon: typeof UserRound;
}[] = [
    { value: 'staff', label: 'Someone who works here', icon: BadgeCheck },
    {
        value: 'outside',
        label: 'Someone outside the organisation',
        icon: Building2,
    },
];

export function ContactSourceChoice({
    value,
    onChange,
}: {
    value: ContactSource;
    onChange: (source: ContactSource) => void;
}) {
    return (
        <Segmented
            value={value}
            onChange={onChange}
            options={CONTACT_SOURCE_OPTIONS}
        />
    );
}

/** The staff list with `extra` (e.g. a saved link the viewer can't pick today) kept selectable. */
export function staffPeopleOptions(
    staff: SiteStaffOption[],
    extra?: SiteStaffOption | null,
): PersonOption[] {
    const list =
        extra && !staff.some((person) => person.id === extra.id)
            ? [...staff, extra]
            : staff;
    return list.map((person) => ({
        value: String(person.id),
        label: person.name,
        sub: person.role ?? undefined,
    }));
}

export function StaffContactPicker({
    id,
    value,
    onChange,
    staff,
    current,
    loading = false,
    failed = false,
    onRetry,
}: {
    id?: string;
    /** The picked staff member's id, or '' for none. */
    value: string;
    onChange: (person: SiteStaffOption | null) => void;
    staff: SiteStaffOption[];
    /** The staff member this contact is saved with, if any (shown even when outside `staff`). */
    current?: SiteStaffOption | null;
    loading?: boolean;
    failed?: boolean;
    onRetry?: () => void;
}) {
    const options = staffPeopleOptions(staff, current);
    const picked =
        staff.find((person) => String(person.id) === value) ??
        (current && String(current.id) === value ? current : null);

    if (failed) {
        return (
            <div
                id={id}
                className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border bg-muted/40 p-3 text-[13px]"
                role="alert"
            >
                <span>The staff list couldn&apos;t load.</span>
                {onRetry ? (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={onRetry}
                    >
                        <RotateCcw className="h-3.5 w-3.5" /> Try again
                    </Button>
                ) : null}
            </div>
        );
    }

    return (
        <div className="grid gap-2">
            <div id={id}>
                <PeoplePicker
                    value={value}
                    onChange={(next) =>
                        onChange(
                            next === ''
                                ? null
                                : (staff.find(
                                      (person) => String(person.id) === next,
                                  ) ??
                                      (current && String(current.id) === next
                                          ? current
                                          : null)),
                        )
                    }
                    people={options}
                    placeholder={
                        loading ? 'Loading staff…' : 'Search staff by name…'
                    }
                    disabled={loading}
                />
            </div>
            {picked ? <StaffWorkDetails person={picked} /> : null}
        </div>
    );
}

/** Read-only work details for a picked staff member. */
export function StaffWorkDetails({ person }: { person: SiteStaffOption }) {
    const rows: [string, string | null | undefined, string][] = [
        ['Job title', person.role, 'No job title on their staff record'],
        ['Work phone', person.phone, 'No work phone on their staff record'],
        ['Work email', person.email, 'No work email on their staff record'],
    ];
    return (
        <div className="rounded-lg border border-border bg-muted/40 p-3">
            <dl className="grid gap-1.5 text-[13px] sm:grid-cols-[110px_1fr]">
                {rows.map(([label, value, empty]) => (
                    <div key={label} className="contents">
                        <dt className="text-muted-foreground">{label}</dt>
                        <dd
                            className={
                                value
                                    ? 'min-w-0 break-words'
                                    : 'text-muted-foreground'
                            }
                        >
                            {value || empty}
                        </dd>
                    </div>
                ))}
            </dl>
            <p className="text-caption mt-2 text-muted-foreground">
                From their staff record, so it stays up to date. Work details
                only.
            </p>
        </div>
    );
}
