import type { FundingLine } from './funding-types';
import { label, type Options } from './types';

/** Keep native units and unknown values visible in both record and review screens. */
export function FundingAllocation({
    line,
    options,
}: {
    line: FundingLine;
    options: Options;
}) {
    const unit =
        line.unit === 'money'
            ? line.currency
            : line.unit === 'other'
              ? line.unit_label
              : line.unit;
    const quantity = (value: FundingLine['quantity']) =>
        value === null || value === ''
            ? 'Unknown'
            : `${value} ${unit} / ${label(line.period)}`;
    return (
        <div className="space-y-2 rounded-lg border p-4">
            <p className="font-medium">
                {options.services.find((item) => item.id === line.service_id)
                    ?.label ??
                    (line.service_id
                        ? label(line.service_id)
                        : 'Personal income assistance')}
            </p>
            <p className="text-sm">Original award: {quantity(line.quantity)}</p>
            {line.service_id && (
                <p className="text-sm">
                    Provider allocation: {quantity(line.provider_quantity)}
                </p>
            )}
            {line.restrictions && (
                <p className="text-subtle whitespace-pre-wrap">
                    {line.restrictions}
                </p>
            )}
        </div>
    );
}
