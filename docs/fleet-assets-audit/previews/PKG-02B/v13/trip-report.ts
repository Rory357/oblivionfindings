import {
    confirmedDriver,
    driverAt,
    eventKey,
    reviewedScore,
    type DrivingState,
} from './driving-workflows';
import type { Journey } from './trip-data';

export type TripReport = {
    trips: Journey[];
    state: DrivingState;
    from: string;
    to: string;
    maps: boolean;
    events: boolean;
};
const purple = '#7C3AED';
const xml = (v: unknown) =>
    String(v ?? '').replace(
        /[&<>"']/g,
        (c) =>
            ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&apos;',
            })[c]!,
    );
const date = (s: string) =>
    new Date(s + 'T12:00:00').toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
const image = (src: string) =>
    new Promise<HTMLImageElement>((resolve, reject) => {
        const img = new Image();
        img.crossOrigin = 'anonymous';
        const timer = window.setTimeout(() => {
            img.onload = null;
            img.onerror = null;
            reject(
                new Error(
                    'Map imagery timed out. Retry, or turn off map images.',
                ),
            );
        }, 15000);
        img.onload = () => {
            clearTimeout(timer);
            resolve(img);
        };
        img.onerror = () => {
            clearTimeout(timer);
            reject(
                new Error(
                    'Map imagery could not be loaded. Retry, or turn off map images.',
                ),
            );
        };
        img.src = src;
    });
async function logo() {
    // Event Horizon ring, matching the current shell wordmark in app.css.
    const c = document.createElement('canvas');
    c.width = 200;
    c.height = 200;
    const ctx = c.getContext('2d')!;
    const g = ctx.createConicGradient(-Math.PI / 2, 100, 100);
    g.addColorStop(0, 'transparent');
    g.addColorStop(200 / 360, purple);
    g.addColorStop(290 / 360, '#b3a1ff');
    g.addColorStop(340 / 360, 'transparent');
    g.addColorStop(1, 'transparent');
    ctx.strokeStyle = g;
    ctx.lineWidth = 28;
    ctx.beginPath();
    ctx.arc(100, 100, 70, 0, Math.PI * 2);
    ctx.stroke();
    return c.toDataURL('image/png');
}

// Small, on-demand report maps. No prefetch, offline tile cache or routing inference.
export async function tripMap(trip: Journey) {
    const w = 1000,
        h = 480;
    const project = (p: { lat: number; lng: number }, z: number) => {
        const n = 256 * 2 ** z,
            s = Math.sin((p.lat * Math.PI) / 180);
        return {
            x: ((p.lng + 180) / 360) * n,
            y: (0.5 - Math.log((1 + s) / (1 - s)) / (4 * Math.PI)) * n,
        };
    };
    let z = 16,
        points = trip.path.map((p) => project(p, z));
    while (
        z > 2 &&
        (Math.max(...points.map((p) => p.x)) -
            Math.min(...points.map((p) => p.x)) >
            w - 180 ||
            Math.max(...points.map((p) => p.y)) -
                Math.min(...points.map((p) => p.y)) >
                h - 120)
    ) {
        z -= 1;
        points = trip.path.map((p) => project(p, z));
    }
    const left =
        (Math.min(...points.map((p) => p.x)) +
            Math.max(...points.map((p) => p.x)) -
            w) /
        2;
    const top =
        (Math.min(...points.map((p) => p.y)) +
            Math.max(...points.map((p) => p.y)) -
            h) /
        2;
    const c = document.createElement('canvas');
    c.width = w;
    c.height = h;
    const ctx = c.getContext('2d')!;
    ctx.fillStyle = '#eee';
    ctx.fillRect(0, 0, w, h);
    ctx.filter = 'grayscale(1)';
    const tiles: Promise<void>[] = [];
    for (let x = Math.floor(left / 256); x <= Math.floor((left + w) / 256); x++)
        for (
            let y = Math.floor(top / 256);
            y <= Math.floor((top + h) / 256);
            y++
        ) {
            tiles.push(
                image(`/report-tiles/${z}/${x}/${y}.png`).then((img) => {
                    ctx.drawImage(img, x * 256 - left, y * 256 - top, 256, 256);
                }),
            );
        }
    await Promise.all(tiles);
    ctx.filter = 'none';
    ctx.strokeStyle = purple;
    ctx.lineWidth = 5;
    ctx.lineJoin = 'round';
    if (trip.coverage < 90) ctx.setLineDash([12, 8]);
    ctx.beginPath();
    points.forEach((p, i) =>
        i
            ? ctx.lineTo(p.x - left, p.y - top)
            : ctx.moveTo(p.x - left, p.y - top),
    );
    ctx.stroke();
    ctx.setLineDash([]);
    points.forEach((p, i) => {
        const x = p.x - left,
            y = p.y - top;
        ctx.fillStyle = purple;
        ctx.beginPath();
        ctx.arc(
            x,
            y,
            i === 0 || i === points.length - 1 ? 15 : 9,
            0,
            Math.PI * 2,
        );
        ctx.fill();
        ctx.strokeStyle = '#fff';
        ctx.lineWidth = 3;
        ctx.stroke();
        if (i === 0 || i === points.length - 1) {
            ctx.fillStyle = '#fff';
            ctx.font = 'bold 16px Arial';
            ctx.textAlign = 'center';
            ctx.fillText(i === 0 ? 'A' : 'B', x, y + 6);
        }
    });
    ctx.fillStyle = 'rgba(255,255,255,.94)';
    ctx.fillRect(0, h - 30, w, 30);
    ctx.fillStyle = '#37333f';
    ctx.font = '13px Arial';
    ctx.textAlign = 'left';
    ctx.fillText(
        'Synthetic recorded points • illustrative joins, not verified roads',
        12,
        h - 10,
    );
    ctx.textAlign = 'right';
    ctx.fillText(
        '© OpenStreetMap contributors · openstreetmap.org/copyright',
        w - 12,
        h - 10,
    );
    return c.toDataURL('image/png');
}

export async function createTripReport(
    report: TripReport,
    format: 'pdf' | 'xlsx',
    progress: (s: string) => void,
) {
    if (!report.trips.length) throw new Error('No trips match this report.');
    const trips = [...report.trips].sort(
        (a, b) => a.day.localeCompare(b.day) || a.start.localeCompare(b.start),
    );
    const maps: string[] = [];
    const brand = await logo();
    if (report.maps)
        for (const [i, trip] of trips.entries()) {
            progress(`Preparing map ${i + 1} of ${trips.length}…`);
            maps.push(await tripMap(trip));
        }
    progress(`Building ${format === 'pdf' ? 'PDF' : 'Excel'} report…`);
    const bytes =
        format === 'pdf'
            ? await pdf(report, trips, maps, brand)
            : await excel(report, trips, maps, brand);
    const mime =
        format === 'pdf'
            ? 'application/pdf'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    // Data URL keeps the generated report downloadable without a server or mutation endpoint.
    const href = await new Promise<string>((resolve, reject) => {
        const r = new FileReader();
        r.onload = () => resolve(String(r.result));
        r.onerror = reject;
        r.readAsDataURL(new Blob([bytes as BlobPart], { type: mime }));
    });
    return {
        href,
        filename: `VH-014-trips-${report.from}-to-${report.to}.${format}`,
        count: trips.length,
    };
}

async function pdf(
    r: TripReport,
    trips: Journey[],
    maps: string[],
    brand: string,
) {
    const { PDFDocument, StandardFonts, rgb } = await import('pdf-lib');
    const doc = await PDFDocument.create();
    const regular = await doc.embedFont(StandardFonts.Helvetica),
        bold = await doc.embedFont(StandardFonts.HelveticaBold),
        logoImg = await doc.embedPng(brand);
    const ink = rgb(0.13, 0.11, 0.19),
        muted = rgb(0.4, 0.38, 0.45),
        accent = rgb(0.486, 0.227, 0.929);
    const clean = (s: unknown) =>
        String(s)
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[–—−]/g, '-')
            .replace(/→/g, 'to')
            .replace(/[^\x20-\x7E\n]/g, ' ');
    const text = (
        p: any,
        s: unknown,
        x: number,
        y: number,
        size = 10,
        strong = false,
        color = ink,
    ) =>
        p.drawText(clean(s), {
            x,
            y,
            size,
            font: strong ? bold : regular,
            color,
        });
    const wrap = (
        target: any,
        s: string,
        y: number,
        width = 507,
        size = 10,
    ) => {
        let line = '';
        const drawLine = () => {
            if (y < 92) {
                p = page('Trip report continued');
                target = p;
                y = 658;
            }
            text(target, line, 44, y, size);
            y -= size < 10 ? 12 : 15;
        };
        for (const word of clean(s).split(/\s+/)) {
            const next = (line + ' ' + word).trim();
            if (regular.widthOfTextAtSize(next, size) > width) {
                if (line) drawLine();
                line = word;
            } else line = next;
            while (regular.widthOfTextAtSize(line, size) > width) {
                let end = line.length - 1;
                while (
                    regular.widthOfTextAtSize(line.slice(0, end), size) > width
                )
                    end--;
                const rest = line.slice(end);
                line = line.slice(0, end);
                drawLine();
                line = rest;
            }
        }
        if (line) drawLine();
        return y;
    };
    const page = (subtitle: string) => {
        const p = doc.addPage([595.28, 841.89]);
        p.drawRectangle({
            x: 0,
            y: 830,
            width: 596,
            height: 12,
            color: accent,
        });
        p.drawImage(logoImg, { x: 44, y: 756, width: 40, height: 40 });
        text(p, 'OBLIVION CARE', 96, 781, 12, true);
        text(p, 'Fleet & Assets / Trip report', 96, 763, 10, false, muted);
        text(p, subtitle, 44, 715, 23, true);
        text(
            p,
            `${date(r.from)} - ${date(r.to)} | Pacific/Auckland`,
            44,
            691,
            10,
            false,
            muted,
        );
        return p;
    };
    let p = page('Vehicle journeys');
    text(p, 'KWH014', 44, 649, 18, true);
    text(
        p,
        'VH-014 / Kowhai van / Synthetic demonstration records',
        44,
        629,
        10,
        false,
        muted,
    );
    const metrics = [
        ['TRIPS', trips.length],
        [
            'DISTANCE',
            trips.reduce((s, t) => s + t.distance, 0).toFixed(1) + ' km',
        ],
        ['RECORDED TIME', trips.reduce((s, t) => s + t.minutes, 0) + ' min'],
    ];
    metrics.forEach(([label, value], i) => {
        const x = 44 + i * 171;
        p.drawRectangle({
            x,
            y: 543,
            width: 158,
            height: 65,
            color: rgb(0.96, 0.94, 1),
        });
        text(p, label, x + 13, 587, 9, true, muted);
        text(p, value, x + 13, 559, 21, true, accent);
    });
    text(p, 'Included journeys', 44, 507, 13, true);
    p.drawRectangle({ x: 44, y: 473, width: 507, height: 22, color: accent });
    [
        ['Date', 52],
        ['Trip', 147],
        ['Route', 251],
        ['km', 503],
    ].forEach(([s, x]) => text(p, s, Number(x), 480, 9, true, rgb(1, 1, 1)));
    let y = 447;
    for (const t of trips) {
        text(p, date(t.day), 52, y, 9);
        text(p, t.id, 147, y, 9, true);
        text(p, t.from, 251, y, 9);
        text(p, t.to, 251, y - 14, 9, false, muted);
        text(p, t.distance.toFixed(1), 503, y, 10, true);
        p.drawLine({
            start: { x: 44, y: y - 24 },
            end: { x: 551, y: y - 24 },
            thickness: 0.5,
            color: rgb(0.87, 0.86, 0.9),
        });
        y -= 53;
    }
    y -= 10;
    wrap(
        p,
        'Distance is a tracker estimate, not a verified dashboard odometer. Actual driver confirmation, coverage and event reviews are retained per trip. Report scope includes the selected dates and current trip filters.',
        y,
    );
    text(
        p,
        `Score policy SCORE-DEMO-v${r.state.policy.version}`,
        44,
        y - 65,
        10,
        true,
        accent,
    );
    for (const [i, t] of trips.entries()) {
        p = page(t.id);
        text(p, `${t.from} to ${t.to}`, 44, 658, 13, true);
        text(
            p,
            `${date(t.day)} | ${t.start} - ${t.end} | ${t.distance} km estimated | ${t.minutes} min`,
            44,
            637,
            10,
            false,
            muted,
        );
        const actual =
            confirmedDriver(t, r.state) ||
            ((r.state.assignments[t.id] || []).length
                ? 'Multiple / partial confirmation'
                : 'Unconfirmed');
        text(p, `Planned: ${t.driver} / Actual: ${actual}`, 44, 614, 10);
        text(
            p,
            `Coverage ${t.coverage}% / Reviewed score ${reviewedScore(t, r.state) ?? 'Withheld'} / Peak ${t.max} km/h`,
            44,
            594,
            10,
            true,
            accent,
        );
        y = 563;
        if (maps[i]) {
            const map = await doc.embedPng(maps[i]);
            p.drawImage(map, { x: 44, y: y - 244, width: 507, height: 244 });
            y -= 266;
        } else {
            text(
                p,
                'Map images excluded from this report.',
                44,
                y,
                10,
                false,
                muted,
            );
            y -= 25;
        }
        if (r.events) {
            text(p, 'Journey events & review', 44, y, 12, true);
            y -= 24;
            for (const [j, e] of t.events.entries()) {
                const review = r.state.reviews[eventKey(t, j)];
                if (y < 128) {
                    p = page(t.id + ' / continued');
                    y = 658;
                }
                text(
                    p,
                    `${e.at || `Point ${e.point + 1}`} / ${e.title} / ${review?.outcome || 'Recorded'}`,
                    44,
                    y,
                    10,
                    true,
                );
                y -= 15;
                y = wrap(p, review?.reason || e.detail, y, 507, 9);
                y -= 5;
            }
        }
        y = Math.min(y - 8, 150);
        wrap(
            p,
            `Source: ${t.source}. Score policy SCORE-DEMO-v${r.state.policy.version}. ${t.coverage < 90 ? 'Partial trail: gaps remain unknown. ' : ''}Original samples are retained; reviewed scores may change.`,
            y,
            507,
            9,
        );
    }
    doc.getPages().forEach((p: any, i: number) => {
        p.drawLine({
            start: { x: 44, y: 52 },
            end: { x: 551, y: 52 },
            thickness: 0.6,
            color: rgb(0.87, 0.86, 0.9),
        });
        text(p, 'SYNTHETIC PREVIEW / VH-014', 44, 34, 8, false, muted);
        text(p, `${i + 1} / ${doc.getPageCount()}`, 516, 34, 8, false, muted);
    });
    doc.setTitle('VH-014 Vehicle trip report');
    doc.setAuthor('Oblivion Care');
    return doc.save();
}

async function excel(
    r: TripReport,
    trips: Journey[],
    maps: string[],
    brand: string,
) {
    // Browser-only OOXML writer: typed cells, filterable data and embedded report maps.
    const { default: JSZip } = await import('jszip');
    const zip = new JSZip();
    const ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main',
        rel =
            'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    const serial = (s: string) =>
        Math.round(
            (Date.parse(s + 'T00:00:00Z') - Date.UTC(1899, 11, 30)) / 86400000,
        );
    const cell = (v: unknown, col: number, row: number, style = 0) => {
        let c = '',
            n = col + 1;
        while (n) {
            c = String.fromCharCode(65 + ((n - 1) % 26)) + c;
            n = Math.floor((n - 1) / 26);
        }
        const ref = c + row;
        return typeof v === 'number'
            ? `<c r="${ref}" s="${style}"><v>${v}</v></c>`
            : `<c r="${ref}" s="${style}" t="inlineStr"><is><t xml:space="preserve">${xml(v)}</t></is></c>`;
    };
    const row = (values: unknown[], n: number, style = 0, height = 24) =>
        `<row r="${n}" ht="${height}" customHeight="1">${values.map((v, c) => cell(v, c, n, typeof v === 'number' && c === 1 ? 4 : style)).join('')}</row>`;
    const names = [
        'Trips',
        ...(r.events ? ['Events'] : []),
        ...(r.maps ? ['Journey maps'] : []),
    ];
    const widths = [20, 17, 27, 29, 29, 14, 15, 15, 18, 18, 34];
    const sheet = (
        rows: string,
        filter: string,
        drawing = false,
        cols = widths,
        merges: string[] = ['C1:K1', 'A2:K2', 'A3:K3', 'A4:K4', 'A5:K5'],
    ) =>
        `<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="${ns}" xmlns:r="${rel}"><sheetViews><sheetView workbookViewId="0" showGridLines="0"><pane ySplit="7" topLeftCell="A8" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>${cols.map((w, i) => `<col min="${i + 1}" max="${i + 1}" width="${w}" customWidth="1"/>`).join('')}</cols><sheetData>${rows}</sheetData>${filter ? `<autoFilter ref="${filter}"/>` : ''}${merges.length ? `<mergeCells count="${merges.length}">${merges.map((ref) => `<mergeCell ref="${ref}"/>`).join('')}</mergeCells>` : ''}<pageMargins left="0.3" right="0.3" top="0.4" bottom="0.4" header="0.2" footer="0.2"/><pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/>${drawing ? '<drawing r:id="rId1"/>' : ''}</worksheet>`;
    let rows =
        row(['', '', 'OBLIVION CARE / Trip report'], 1, 1, 42) +
        row(['VH-014 / KWH014 / Kōwhai van'], 2, 2) +
        row([`${date(r.from)} - ${date(r.to)} / Pacific/Auckland`], 3) +
        row(
            [
                'Synthetic records. Distances are estimates. Actual dashboard readings remain separate.',
            ],
            4,
        ) +
        row(
            [
                `SCORE-DEMO-v${r.state.policy.version} / Scope: selected dates and current trip filters`,
            ],
            5,
        ) +
        row(
            [
                'Trip',
                'Date',
                'Actual driver',
                'From',
                'To',
                'Distance km',
                'Duration min',
                'Peak km/h',
                'Coverage %',
                'Reviewed score',
                'Observation source',
            ],
            7,
            3,
            30,
        );
    trips.forEach((t, i) => {
        const n = i + 8,
            v = [
                t.id,
                serial(t.day),
                confirmedDriver(t, r.state) || 'Unconfirmed / multiple',
                t.from,
                t.to,
                t.distance,
                t.minutes,
                t.max,
                t.coverage,
                reviewedScore(t, r.state) ?? 'Withheld',
                t.source,
            ];
        rows += `<row r="${n}" ht="32" customHeight="1">${v.map((x, c) => cell(x, c, n, c === 1 ? 4 : c === 5 ? 5 : 0)).join('')}</row>`;
    });
    const total = 8 + trips.length;
    rows += `<row r="${total}" ht="28" customHeight="1">${cell('TOTAL', 0, total, 2)}<c r="F${total}" s="5"><f>SUM(F8:F${total - 1})</f><v>${trips.reduce((s, t) => s + t.distance, 0)}</v></c><c r="G${total}"><f>SUM(G8:G${total - 1})</f><v>${trips.reduce((s, t) => s + t.minutes, 0)}</v></c></row>`;
    zip.file('xl/worksheets/sheet1.xml', sheet(rows, `A7:K${total - 1}`, true));
    if (r.events) {
        let n = 8;
        let ev =
            row(['OBLIVION CARE / Journey events'], 1, 1, 42) +
            row([`${date(r.from)} - ${date(r.to)} / Synthetic records`], 3) +
            row(
                [
                    'Trip',
                    'Date',
                    'Time / point',
                    'Event',
                    'Details',
                    'Review',
                    'Reviewer',
                    'Reason',
                    'Actual driver',
                ],
                7,
                3,
                30,
            );
        for (const t of trips)
            for (const [i, e] of t.events.entries()) {
                const review = r.state.reviews[eventKey(t, i)];
                ev += row(
                    [
                        t.id,
                        serial(t.day),
                        e.at || `Point ${e.point + 1}`,
                        e.title,
                        e.detail,
                        review?.outcome || 'Recorded',
                        review?.reviewer || '',
                        review?.reason || '',
                        driverAt(t, r.state, e.point) || 'Unconfirmed',
                    ],
                    n++,
                    0,
                    45,
                );
            }
        zip.file(
            'xl/worksheets/sheet2.xml',
            sheet(
                ev,
                `A7:I${n - 1}`,
                false,
                [20, 17, 18, 28, 60, 18, 25, 45, 25],
                ['A1:I1', 'A3:I3'],
            ),
        );
    }
    const drawing = (
        items: {
            name: string;
            file: string;
            col: number;
            row: number;
            width: number;
            height: number;
        }[],
        id: number,
        sheetId: number,
    ) => {
        let pics = '',
            rels = '';
        items.forEach((p, i) => {
            pics += `<xdr:oneCellAnchor><xdr:from><xdr:col>${p.col}</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>${p.row}</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from><xdr:ext cx="${p.width * 9525}" cy="${p.height * 9525}"/><xdr:pic><xdr:nvPicPr><xdr:cNvPr id="${i + 1}" name="${xml(p.name)}" descr="${xml(p.name)}"/><xdr:cNvPicPr/></xdr:nvPicPr><xdr:blipFill><a:blip r:embed="rId${i + 1}"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill><xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="${p.width * 9525}" cy="${p.height * 9525}"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic><xdr:clientData/></xdr:oneCellAnchor>`;
            rels += `<Relationship Id="rId${i + 1}" Type="${rel}/image" Target="../media/${p.file}"/>`;
        });
        zip.file(
            `xl/drawings/drawing${id}.xml`,
            `<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="${rel}">${pics}</xdr:wsDr>`,
        );
        zip.file(
            `xl/drawings/_rels/drawing${id}.xml.rels`,
            `<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">${rels}</Relationships>`,
        );
        zip.file(
            `xl/worksheets/_rels/sheet${sheetId}.xml.rels`,
            `<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="${rel}/drawing" Target="../drawings/drawing${id}.xml"/></Relationships>`,
        );
    };
    zip.file('xl/media/logo.png', brand.split(',')[1], { base64: true });
    drawing(
        [
            {
                name: 'Oblivion Care logo',
                file: 'logo.png',
                col: 0,
                row: 0,
                width: 48,
                height: 48,
            },
        ],
        1,
        1,
    );
    if (r.maps) {
        let mr =
            row(['OBLIVION CARE / Journey maps'], 1, 1, 42) +
            row(
                [
                    `${date(r.from)} - ${date(r.to)} / Map imagery © OpenStreetMap contributors`,
                ],
                3,
            ) +
            row(
                [
                    'Synthetic recorded points. Joins are illustrative, not verified roads.',
                ],
                4,
            );
        const pics: any[] = [];
        trips.forEach((t, i) => {
            const base = 7 + i * 23;
            mr += row(
                [`${t.id} / ${date(t.day)} / ${t.from} → ${t.to}`],
                base,
                2,
                28,
            );
            mr += row(
                [
                    `${t.start} - ${t.end} / ${t.distance} km / ${t.coverage}% coverage / Peak ${t.max} km/h`,
                ],
                base + 1,
            );
            for (let j = 2; j < 23; j++) mr += row([], base + j, 0, 20);
            const file = `trip-${i + 1}.png`;
            zip.file('xl/media/' + file, maps[i].split(',')[1], {
                base64: true,
            });
            pics.push({
                name: t.id + ' recorded route',
                file,
                col: 0,
                row: base + 1,
                width: 800,
                height: 384,
            });
        });
        zip.file(
            `xl/worksheets/sheet${names.length}.xml`,
            sheet(mr, '', true, widths, [
                'A1:K1',
                'A3:K3',
                'A4:K4',
                ...trips.flatMap((_, i) => [
                    `A${7 + i * 23}:K${7 + i * 23}`,
                    `A${8 + i * 23}:K${8 + i * 23}`,
                ]),
            ]),
        );
        drawing(pics, 2, names.length);
    }
    zip.file(
        'xl/styles.xml',
        `<styleSheet xmlns="${ns}"><numFmts count="2"><numFmt numFmtId="164" formatCode="d mmm yyyy"/><numFmt numFmtId="165" formatCode="0.0"/></numFmts><fonts count="4"><font><sz val="11"/><color rgb="FF272136"/><name val="Calibri"/></font><font><b/><sz val="18"/><color rgb="FF7C3AED"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FF7C3AED"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF7C3AED"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="6">${[0, 1, 2, 3, 0, 0].map((f, i) => `<xf numFmtId="${i === 4 ? 164 : i === 5 ? 165 : 0}" fontId="${f}" fillId="${i === 3 ? 2 : 0}" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="${i === 0 || i === 3 ? 1 : 0}"/></xf>`).join('')}</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>`,
    );
    zip.file(
        'xl/workbook.xml',
        `<workbook xmlns="${ns}" xmlns:r="${rel}"><sheets>${names.map((n, i) => `<sheet name="${n}" sheetId="${i + 1}" r:id="rId${i + 1}"/>`).join('')}</sheets><calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>`,
    );
    zip.file(
        'xl/_rels/workbook.xml.rels',
        `<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">${names.map((_, i) => `<Relationship Id="rId${i + 1}" Type="${rel}/worksheet" Target="worksheets/sheet${i + 1}.xml"/>`).join('')}<Relationship Id="rId${names.length + 1}" Type="${rel}/styles" Target="styles.xml"/></Relationships>`,
    );
    zip.file(
        '_rels/.rels',
        `<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="${rel}/officeDocument" Target="xl/workbook.xml"/></Relationships>`,
    );
    zip.file(
        '[Content_Types].xml',
        `<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>${names.map((_, i) => `<Override PartName="/xl/worksheets/sheet${i + 1}.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>`).join('')}${[1, ...(r.maps ? [2] : [])].map((i) => `<Override PartName="/xl/drawings/drawing${i}.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>`).join('')}</Types>`,
    );
    return zip.generateAsync({ type: 'uint8array', compression: 'DEFLATE' });
}
