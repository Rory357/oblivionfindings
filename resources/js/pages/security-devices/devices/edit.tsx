import DeviceForm from './create';

type FilterOption = { value: string; label: string };
type Taxonomy = Record<string, Record<string, Record<string, string>>>;

type Props = {
    device: Record<string, unknown>;
    taxonomy: Taxonomy;
    domains: FilterOption[];
    statuses: FilterOption[];
};

export default function DeviceEdit({
    device,
    taxonomy,
    domains,
    statuses,
}: Props) {
    return (
        <DeviceForm
            taxonomy={taxonomy}
            domains={domains}
            statuses={statuses}
            device={device as any}
            isEdit
        />
    );
}
