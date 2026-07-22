import { Badge } from '@/components/ui/badge';
import {
    SafetyEmpty,
    SafetyRegisterCard,
    SafetyRegisterHeader,
    formatRegisterDate,
    registerLabel,
} from './safety-register';

type PpeRow = {
    id: number;
    name: string;
    condition?: string | null;
    quantity: number;
    status?: string | null;
    location?: string | null;
    expiry_date?: string | null;
    next_inspection_due?: string | null;
    expired: boolean;
    inspection_due: boolean;
};

export type SitePpeData = {
    locked?: boolean;
    items: PpeRow[];
    can_manage: boolean;
    href: string;
};

export function SiteProfilePpe({ data }: { data: SitePpeData }) {
    const units = data.items.reduce((total, item) => total + item.quantity, 0);

    return (
        <div className="space-y-5">
            <SafetyRegisterHeader
                title="PPE inventory"
                description="All active Site PPE with condition, quantity, location, expiry and next-inspection attention cues."
                href={data.href}
                actionLabel="Open PPE register"
                count={units}
            />
            <SafetyRegisterCard title="Site PPE">
                {data.items.length ? (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full min-w-[760px] text-sm">
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                <tr>
                                    <th className="px-4 py-3 font-medium">
                                        Item
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        Condition
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        Location
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        Expiry
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        Inspection
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Units
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {data.items.map((item) => (
                                    <tr key={item.id}>
                                        <td className="px-4 py-3">
                                            <div className="font-medium">
                                                {item.name}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {registerLabel(item.status)}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge variant="outline">
                                                {registerLabel(item.condition)}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {item.location ?? 'Not recorded'}
                                        </td>
                                        <td className="px-4 py-3">
                                            <span
                                                className={
                                                    item.expired
                                                        ? 'font-semibold text-destructive'
                                                        : ''
                                                }
                                            >
                                                {formatRegisterDate(
                                                    item.expiry_date,
                                                )}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <span
                                                className={
                                                    item.inspection_due
                                                        ? 'font-semibold text-destructive'
                                                        : ''
                                                }
                                            >
                                                {formatRegisterDate(
                                                    item.next_inspection_due,
                                                )}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-right font-semibold">
                                            {item.quantity}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <SafetyEmpty label="No active PPE is recorded for this Site." />
                )}
            </SafetyRegisterCard>
        </div>
    );
}
