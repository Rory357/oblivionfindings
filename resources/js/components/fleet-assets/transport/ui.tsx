import {
    TierTwoTabs,
    type GroupedProfileNavTab,
} from '@/components/page/grouped-profile-nav';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/datetime';
import {
    ArrowRight,
    CircleCheck,
    Clock,
    Flag,
    type LucideIcon,
} from 'lucide-react';
import type { ReactNode } from 'react';
import type { TransportRecord } from './types';
export function SectionTabs({
    tabs,
    value,
    onChange,
    label,
}: {
    tabs: GroupedProfileNavTab[];
    value: string;
    onChange: (value: string) => void;
    label: string;
}) {
    return (
        <TierTwoTabs
            tabs={tabs}
            activeTab={value}
            onTab={onChange}
            ariaLabel={label}
            testIdPrefix={`transport-${label.replaceAll(' ', '-')}`}
            renderLink={(tab, className, inner, props) => (
                // eslint-disable-next-line no-restricted-syntax -- This complete selector card or shared tab uses its canonical layout styles.
                <button
                    type="button"
                    className={className}
                    {...props}
                    onClick={() => onChange(tab.key)}
                >
                    {inner}
                </button>
            )}
        />
    );
}
export function Panel({
    title,
    icon: Icon,
    children,
    action,
    className = '',
}: {
    title: string;
    icon: LucideIcon;
    children: ReactNode;
    action?: ReactNode;
    className?: string;
}) {
    return (
        <section className={`tr-panel ${className}`}>
            <div className="tr-panel-heading">
                <h3>
                    <Icon className="size-4 text-primary" />
                    {title}
                </h3>
                {action}
            </div>
            {children}
        </section>
    );
}
export function Stage({ row }: { row: TransportRecord }) {
    const done = row.stage === 'completed',
        warn = ['information', 'decision', 'plan_review', 'returned'].includes(
            row.stage,
        );
    const Icon = done ? CircleCheck : warn ? Flag : Clock;
    return (
        <span
            className={`tr-stage ${done ? 'success' : warn ? 'warning' : ''}`}
        >
            <Icon className="size-3.5" />
            {row.stage_label}
        </span>
    );
}
export function Notice({ children }: { children: ReactNode }) {
    return (
        <div className="tr-notice" role="status">
            {children}
        </div>
    );
}
export function ShortRows({
    rows,
    onOpen,
}: {
    rows: TransportRecord[];
    onOpen: (row: TransportRecord) => void;
}) {
    return (
        <div className="tr-short-rows">
            {rows.map((r) => (
                // eslint-disable-next-line no-restricted-syntax -- This complete selector card or shared tab uses its canonical layout styles.
                <button key={r.id} onClick={() => onOpen(r)}>
                    <span>
                        <strong>{r.person}</strong>
                        <small>
                            {r.pickup} → {r.destination}
                        </small>
                    </span>
                    <span>
                        <strong>
                            {formatDateTime(r.booking?.start || r.start)}
                        </strong>
                        <small>
                            {r.booking?.vehicle.name || 'Awaiting allocation'}
                        </small>
                    </span>
                    <Stage row={r} />
                    <ArrowRight className="size-4 text-primary" />
                </button>
            ))}
            {!rows.length && (
                <p className="tr-empty">No requests in this queue.</p>
            )}
        </div>
    );
}
export function Empty({ onClear }: { onClear?: () => void }) {
    return (
        <div className="tr-empty">
            <Flag className="mx-auto mb-3 size-7 text-primary" />
            <h3>No transport matches this view</h3>
            <p>Choose another date, site or queue.</p>
            {onClear && (
                <Button variant="outline" onClick={onClear}>
                    Clear search
                </Button>
            )}
        </div>
    );
}
