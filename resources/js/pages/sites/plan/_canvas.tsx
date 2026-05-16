import { PlanThumbnail, type PlanLayout, type PlanPin } from './_thumbnail';

type Props = {
    layout: PlanLayout;
    pins: PlanPin[];
    onCanvasClick: (point: { x: number; y: number }) => void;
};

export default function PlanCanvas({ layout, pins, onCanvasClick }: Props) {
    return (
        <PlanThumbnail
            layout={layout}
            pins={pins}
            className="h-[min(58vh,620px)] cursor-crosshair"
            onCanvasClick={onCanvasClick}
        />
    );
}

