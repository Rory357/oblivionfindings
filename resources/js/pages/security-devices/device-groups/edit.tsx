import type { AutoRules } from './auto-rule-builder';
import DeviceGroupForm from './create';

type Props = {
    group: {
        id: number;
        name: string;
        type: string;
        description: string | null;
        auto_rules: AutoRules | null;
    };
};

export default function DeviceGroupEdit({ group }: Props) {
    return <DeviceGroupForm group={group} isEdit />;
}
