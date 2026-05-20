import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { PageHero, PageLayout } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FileText, PenTool, RotateCcw, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

type SignatureData = {
    id: number;
    status: string;
    document_title: string;
    document_category: string | null;
    document_original_name: string | null;
    requested_by: string;
    requested_at: string;
    signed_at: string | null;
    declined_reason: string | null;
};

type Props = {
    signature: SignatureData;
    can: { sign: boolean };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Signatures', href: '/hr/signatures/pending' },
    { title: 'Sign Document', href: '#' },
];

const statusConfig: Record<string, { className: string; label: string }> = {
    pending: {
        className:
            'border-status-warning/30 text-status-warning bg-status-warning',
        label: 'Pending',
    },
    signed: {
        className:
            'border-status-success/30 text-status-success bg-status-success',
        label: 'Signed',
    },
    declined: {
        className:
            'border-status-critical/30 text-status-critical bg-status-critical',
        label: 'Declined',
    },
};

export default function SignDocument({ signature, can }: Props) {
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const [isDrawing, setIsDrawing] = useState(false);
    const [hasDrawn, setHasDrawn] = useState(false);
    const [showDeclineForm, setShowDeclineForm] = useState(false);

    const signForm = useForm({ signature_data: '' });
    const declineForm = useForm({ reason: '' });

    const getCanvasPosition = useCallback((e: MouseEvent | TouchEvent) => {
        const canvas = canvasRef.current;
        if (!canvas) return { x: 0, y: 0 };
        const rect = canvas.getBoundingClientRect();
        const scaleX = canvas.width / rect.width;
        const scaleY = canvas.height / rect.height;

        if ('touches' in e) {
            return {
                x: (e.touches[0].clientX - rect.left) * scaleX,
                y: (e.touches[0].clientY - rect.top) * scaleY,
            };
        }
        return {
            x: (e.clientX - rect.left) * scaleX,
            y: (e.clientY - rect.top) * scaleY,
        };
    }, []);

    useEffect(() => {
        const canvas = canvasRef.current;
        if (!canvas || !can.sign) return;

        const ctx = canvas.getContext('2d');
        if (!ctx) return;

        // Set up canvas for high DPI
        canvas.width = 600;
        canvas.height = 200;
        ctx.strokeStyle = '#1a1a2e';
        ctx.lineWidth = 2.5;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';

        const handleStart = (e: MouseEvent | TouchEvent) => {
            e.preventDefault();
            setIsDrawing(true);
            setHasDrawn(true);
            const pos = getCanvasPosition(e);
            ctx.beginPath();
            ctx.moveTo(pos.x, pos.y);
        };

        const handleMove = (e: MouseEvent | TouchEvent) => {
            e.preventDefault();
            if (!isDrawing) return;
            const pos = getCanvasPosition(e);
            ctx.lineTo(pos.x, pos.y);
            ctx.stroke();
        };

        const handleEnd = (e: MouseEvent | TouchEvent) => {
            e.preventDefault();
            setIsDrawing(false);
        };

        canvas.addEventListener('mousedown', handleStart);
        canvas.addEventListener('mousemove', handleMove);
        canvas.addEventListener('mouseup', handleEnd);
        canvas.addEventListener('mouseleave', handleEnd);
        canvas.addEventListener('touchstart', handleStart, { passive: false });
        canvas.addEventListener('touchmove', handleMove, { passive: false });
        canvas.addEventListener('touchend', handleEnd);

        return () => {
            canvas.removeEventListener('mousedown', handleStart);
            canvas.removeEventListener('mousemove', handleMove);
            canvas.removeEventListener('mouseup', handleEnd);
            canvas.removeEventListener('mouseleave', handleEnd);
            canvas.removeEventListener('touchstart', handleStart);
            canvas.removeEventListener('touchmove', handleMove);
            canvas.removeEventListener('touchend', handleEnd);
        };
    }, [can.sign, isDrawing, getCanvasPosition]);

    const clearCanvas = () => {
        const canvas = canvasRef.current;
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        if (!ctx) return;
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        setHasDrawn(false);
    };

    const handleSign = () => {
        const canvas = canvasRef.current;
        if (!canvas) return;

        const dataUrl = canvas.toDataURL('image/png');
        signForm.setData('signature_data', dataUrl);

        router.post(`/hr/signatures/${signature.id}/sign`, {
            signature_data: dataUrl,
        });
    };

    const handleDecline = (e: React.FormEvent) => {
        e.preventDefault();
        declineForm.post(`/hr/signatures/${signature.id}/decline`);
    };

    const config = statusConfig[signature.status] || statusConfig.pending;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Sign: ${signature.document_title}`} />
            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref="/hr/signatures/pending"
                        title="Sign Document"
                        description="Review the document and provide your signature."
                    />
                }
            >
                {/* Document Info */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <FileText className="h-5 w-5" />
                            {signature.document_title}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <span className="text-muted-foreground">
                                    Category:{' '}
                                </span>
                                <span className="capitalize">
                                    {signature.document_category || 'General'}
                                </span>
                            </div>
                            <div>
                                <span className="text-muted-foreground">
                                    File:{' '}
                                </span>
                                <span>
                                    {signature.document_original_name || 'N/A'}
                                </span>
                            </div>
                            <div>
                                <span className="text-muted-foreground">
                                    Requested by:{' '}
                                </span>
                                <span>{signature.requested_by}</span>
                            </div>
                            <div>
                                <span className="text-muted-foreground">
                                    Requested:{' '}
                                </span>
                                <span>{signature.requested_at}</span>
                            </div>
                        </div>
                        <div>
                            <Badge
                                variant="outline"
                                className={config.className}
                            >
                                {config.label}
                            </Badge>
                        </div>
                        {signature.signed_at && (
                            <p className="text-sm text-status-success">
                                Signed on {signature.signed_at}
                            </p>
                        )}
                        {signature.declined_reason && (
                            <div className="rounded-md bg-status-critical-bg p-3 text-sm text-status-critical">
                                <strong>Decline reason:</strong>{' '}
                                {signature.declined_reason}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Signature Pad */}
                {can.sign && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <PenTool className="h-5 w-5" />
                                Your Signature
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {/* eslint-disable-next-line no-restricted-syntax -- Signature canvas frame is an input surface, not a content card. */}
                            <div className="rounded-lg border-2 border-dashed border-muted bg-white p-1">
                                <canvas
                                    ref={canvasRef}
                                    className="w-full cursor-crosshair"
                                    style={{
                                        height: '200px',
                                        touchAction: 'none',
                                    }}
                                />
                            </div>
                            <p className="text-xs text-muted-foreground">
                                Draw your signature above using your mouse or
                                touch input
                            </p>

                            <div className="flex gap-2">
                                <Button
                                    onClick={handleSign}
                                    disabled={!hasDrawn || signForm.processing}
                                >
                                    <PenTool className="mr-1.5 h-4 w-4" />
                                    Sign Document
                                </Button>
                                <Button variant="outline" onClick={clearCanvas}>
                                    <RotateCcw className="mr-1.5 h-4 w-4" />
                                    Clear
                                </Button>
                                <Button
                                    variant="destructive"
                                    onClick={() =>
                                        setShowDeclineForm(!showDeclineForm)
                                    }
                                >
                                    <X className="mr-1.5 h-4 w-4" />
                                    Decline
                                </Button>
                            </div>

                            {showDeclineForm && (
                                <form
                                    onSubmit={handleDecline}
                                    className="space-y-3 rounded-md border p-4"
                                >
                                    <div className="space-y-2">
                                        <Label>Reason for declining</Label>
                                        <Textarea
                                            value={declineForm.data.reason}
                                            onChange={(e) =>
                                                declineForm.setData(
                                                    'reason',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="Please provide a reason for declining to sign..."
                                            className="h-24"
                                        />
                                        {declineForm.errors.reason && (
                                            <p className="text-sm text-status-critical">
                                                {declineForm.errors.reason}
                                            </p>
                                        )}
                                    </div>
                                    <div className="flex gap-2">
                                        <Button
                                            type="submit"
                                            variant="destructive"
                                            disabled={declineForm.processing}
                                        >
                                            Confirm Decline
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() =>
                                                setShowDeclineForm(false)
                                            }
                                        >
                                            Cancel
                                        </Button>
                                    </div>
                                </form>
                            )}
                        </CardContent>
                    </Card>
                )}
            </PageLayout>
        </AppLayout>
    );
}
