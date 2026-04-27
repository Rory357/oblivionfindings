import DictateButton, {
    type DictateButtonProps,
} from '@/components/dictate-button';

export type VoiceInputButtonProps = DictateButtonProps;

export default function VoiceInputButton(props: VoiceInputButtonProps) {
    return <DictateButton {...props} />;
}
