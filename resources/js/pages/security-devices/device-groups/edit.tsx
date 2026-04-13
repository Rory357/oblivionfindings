import DeviceGroupForm from './create';

type Props = {
    group: { id: number; name: string; type: string; description: string | null };
};

export default function DeviceGroupEdit({ group }: Props) {
    return <DeviceGroupForm group={group} isEdit />;
}
