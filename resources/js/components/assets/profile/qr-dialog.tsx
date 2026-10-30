import {
    FilePreviewDialog,
    type PreviewFile,
} from '@/components/files/file-preview-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Link } from '@inertiajs/react';
import { Download, Printer } from 'lucide-react';
import { useState } from 'react';
import type { ProfileWorkspace } from './types';
export function AssetQrDialog({
    assetId,
    assetName,
    qr,
    onClose,
}: {
    assetId: number;
    assetName: string;
    qr: ProfileWorkspace['qr'];
    onClose: () => void;
}) {
    const [format, setFormat] = useState('a4'),
        [copies, setCopies] = useState(1),
        [offset, setOffset] = useState(0),
        [width, setWidth] = useState(63.5),
        [height, setHeight] = useState(46.6),
        [logo, setLogo] = useState(true),
        [preview, setPreview] = useState<PreviewFile | null>(null);
    const valid =
        Number.isInteger(copies) &&
        copies >= 1 &&
        copies <= 180 &&
        Number.isInteger(offset) &&
        offset >= 0 &&
        offset <= 17 &&
        width >= 50 &&
        width <= 150 &&
        height >= 46 &&
        height <= 150;
    const url = qr
        ? `${qr.label}?${new URLSearchParams({ format, copies: String(copies), offset: String(offset), width: String(width), height: String(height), logo: logo ? '1' : '0' })}`
        : '';
    return (
        <>
            <Dialog open onOpenChange={(open) => !open && onClose()}>
                <DialogContent
                    style={{
                        width: 'min(680px, calc(100vw - 2rem))',
                        maxWidth: 'none',
                    }}
                    className="max-h-[90dvh] overflow-y-auto"
                >
                    <DialogHeader>
                        <DialogTitle>Asset QR labels</DialogTitle>
                        <DialogDescription>
                            {assetName} · Use the same identity on replacement
                            labels.
                        </DialogDescription>
                    </DialogHeader>
                    {qr ? (
                        <div className="grid gap-6 sm:grid-cols-[160px_1fr]">
                            <div>
                                <img
                                    src={qr.image}
                                    alt={`QR code for ${assetName}`}
                                    className="w-40 rounded-lg border bg-white p-2"
                                />
                                <Button
                                    variant="outline"
                                    asChild
                                    className="mt-3 w-full"
                                >
                                    <a href={qr.download} download>
                                        <Download />
                                        QR PNG
                                    </a>
                                </Button>
                                <Button
                                    variant="ghost"
                                    asChild
                                    className="mt-2 w-full"
                                >
                                    <a href={`${qr.svg}?download=1`} download>
                                        QR SVG
                                    </a>
                                </Button>
                            </div>
                            <div className="space-y-4">
                                <div
                                    role="group"
                                    aria-label="Label format"
                                    className="flex gap-2"
                                >
                                    <Button
                                        variant={
                                            format === 'a4'
                                                ? 'default'
                                                : 'outline'
                                        }
                                        onClick={() => setFormat('a4')}
                                    >
                                        A4 sheet
                                    </Button>
                                    <Button
                                        variant={
                                            format === 'custom'
                                                ? 'default'
                                                : 'outline'
                                        }
                                        onClick={() => setFormat('custom')}
                                    >
                                        Label printer
                                    </Button>
                                </div>
                                <p className="text-caption">
                                    {format === 'a4'
                                        ? '18 labels per A4 sheet · 63.5 × 46.6 mm · 3 columns × 6 rows · margins 9.75 mm sides / 8.7 mm top.'
                                        : 'One label per PDF page. Match the printer’s media size to these dimensions.'}
                                </p>
                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <Label htmlFor="qr-copies">
                                            Copies (1–180)
                                        </Label>
                                        <Input
                                            id="qr-copies"
                                            type="number"
                                            min={1}
                                            max={180}
                                            value={copies}
                                            onChange={(e) =>
                                                setCopies(
                                                    Number(e.target.value),
                                                )
                                            }
                                        />
                                    </div>
                                    {format === 'a4' ? (
                                        <div>
                                            <Label htmlFor="qr-offset">
                                                Used labels (0–17)
                                            </Label>
                                            <Input
                                                id="qr-offset"
                                                type="number"
                                                min={0}
                                                max={17}
                                                value={offset}
                                                onChange={(e) =>
                                                    setOffset(
                                                        Number(e.target.value),
                                                    )
                                                }
                                            />
                                        </div>
                                    ) : (
                                        <>
                                            <div>
                                                <Label htmlFor="qr-width">
                                                    Width (mm)
                                                </Label>
                                                <Input
                                                    id="qr-width"
                                                    type="number"
                                                    min={50}
                                                    max={150}
                                                    step="0.1"
                                                    value={width}
                                                    onChange={(e) =>
                                                        setWidth(
                                                            Number(
                                                                e.target.value,
                                                            ),
                                                        )
                                                    }
                                                />
                                            </div>
                                            <div>
                                                <Label htmlFor="qr-height">
                                                    Height (mm)
                                                </Label>
                                                <Input
                                                    id="qr-height"
                                                    type="number"
                                                    min={46}
                                                    max={150}
                                                    step="0.1"
                                                    value={height}
                                                    onChange={(e) =>
                                                        setHeight(
                                                            Number(
                                                                e.target.value,
                                                            ),
                                                        )
                                                    }
                                                />
                                            </div>
                                        </>
                                    )}
                                </div>
                                <label className="flex items-center gap-3">
                                    <input
                                        type="checkbox"
                                        checked={logo}
                                        onChange={(e) =>
                                            setLogo(e.target.checked)
                                        }
                                    />
                                    Use company logo from Branding settings
                                </label>
                                <p className="text-caption">
                                    The logo sits above the code, clear of its
                                    quiet zone. If a supported logo is
                                    unavailable, the company name is used.
                                </p>
                                <p className="text-subtle">
                                    Print the PDF at 100% / actual size, with
                                    scaling disabled. Check one label’s
                                    dimensions and scan it before printing the
                                    batch.
                                </p>
                                <p className="text-caption">
                                    This PDF contains label copies for this
                                    asset.
                                </p>
                            </div>
                        </div>
                    ) : (
                        <p>
                            This asset has no QR identity recorded. An
                            authorised asset administrator must create it before
                            printing.
                        </p>
                    )}
                    <DialogFooter>
                        <Button variant="outline" asChild>
                            <Link
                                href={`/fleet-assets/asset-register/labels/workspace?selected=${assetId}`}
                            >
                                Bulk QR labels
                            </Link>
                        </Button>
                        <Button variant="outline" onClick={onClose}>
                            Close
                        </Button>
                        {qr && (
                            <Button
                                disabled={!valid}
                                onClick={() =>
                                    setPreview({
                                        id: `labels-${assetId}-${url}`,
                                        name: `${assetName} labels`,
                                        filename: `asset-${assetId}-labels.pdf`,
                                        mime: 'application/pdf',
                                        previewUrl: url,
                                        downloadUrl: url,
                                    })
                                }
                            >
                                <Printer />
                                Preview printable PDF
                            </Button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <FilePreviewDialog
                file={preview}
                onClose={() => setPreview(null)}
            />
        </>
    );
}
