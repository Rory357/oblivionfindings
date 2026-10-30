/* Staff eligibility building blocks (eMAR P11 v5 `ui.tsx` CanList and
 * `dialogs-elig.tsx` AreaTable), shared by the register, the assessment
 * viewer and wizard, and My eligibility. */
import { StatusBadge } from '@/components/ui/status-badge';
import {
    areaAbility,
    areaRes,
    STATUS_META,
    type Ability,
    type AreaMeta,
    type EligPerson,
    type EligPolicy,
} from './_model';

export const StatusChip = ({ x }: { x: EligPerson }) => {
    const [variant, label] = STATUS_META[x.status];
    return (
        <StatusBadge variant={variant} size="sm">
            {label}
        </StatusBadge>
    );
};

const TONE = {
    yes: 'success',
    no: 'critical',
    part: 'warning',
    na: 'neutral',
} as const;
const WORD = {
    yes: 'Yes',
    no: 'No',
    part: 'With conditions',
    na: 'Not checked',
} as const;

/** What someone can do, one line each, with its yes / no / conditions. */
export function CanList({ items }: { items: (Ability & { head?: string })[] }) {
    return (
        <ul className="divide-y divide-border rounded-xl border border-border">
            {items.map((a, i) => (
                <li key={i} className="flex items-start gap-3 p-3">
                    <StatusBadge
                        variant={TONE[a.v]}
                        size="sm"
                        className="mt-0.5 shrink-0"
                    >
                        {WORD[a.v]}
                    </StatusBadge>
                    <span className="min-w-0 text-[13px]">
                        {a.head ? (
                            <>
                                <span className="block font-semibold">
                                    {a.head}
                                </span>
                                <span className="text-subtle block">{a.t}</span>
                            </>
                        ) : (
                            a.t
                        )}
                    </span>
                </li>
            ))}
        </ul>
    );
}

/** Each of the 12 areas, its result and what checks it. */
export function AreaTable({
    x,
    areas,
    policy,
    corePasses,
}: {
    x: EligPerson;
    areas: AreaMeta[];
    policy: EligPolicy;
    corePasses: boolean;
}) {
    return (
        <div className="divide-y divide-border rounded-xl border">
            {areas.map((a) => {
                const r = areaRes(x, a.key);
                return (
                    <div
                        key={a.key}
                        className="grid gap-2 p-3 sm:grid-cols-[1.2fr_0.8fr_2fr] sm:items-center"
                    >
                        <span className="text-[13px] font-medium">
                            {a.label}
                            {a.core ? (
                                <StatusBadge
                                    variant="neutral"
                                    size="sm"
                                    className="ml-2"
                                >
                                    Core
                                </StatusBadge>
                            ) : null}
                        </span>
                        <span>
                            {r === 'yes' ? (
                                <StatusBadge variant="success" size="sm">
                                    Passed
                                </StatusBadge>
                            ) : r === 'no' ? (
                                <StatusBadge variant="critical" size="sm">
                                    Not passed
                                </StatusBadge>
                            ) : (
                                <StatusBadge variant="neutral" size="sm">
                                    Not assessed
                                </StatusBadge>
                            )}
                        </span>
                        <span className="text-caption">
                            {a.rule === 'area'
                                ? areaAbility(x, policy, a).t
                                : a.rule === 'notyet'
                                  ? 'Not checked when recording yet — orders don’t say which medicines are insulin'
                                  : a.core
                                    ? r === 'yes'
                                        ? 'Core area'
                                        : corePasses
                                          ? 'Core area — not passed means the assessment isn’t passed'
                                          : 'Core area — counts towards the pass mark only'
                                    : 'Recorded on the assessment — not checked when recording'}
                        </span>
                    </div>
                );
            })}
        </div>
    );
}
