<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Training certificate — {{ $employee_name }}</title>
    <style>
        @page { size: A4 landscape; margin: 0; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background: #eef2ff;
            color: #172033;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        .certificate {
            position: relative;
            width: min(1120px, calc(100vw - 48px));
            min-height: 720px;
            padding: 72px 84px;
            overflow: hidden;
            background: #ffffff;
            border: 12px solid #312e81;
            box-shadow: 0 24px 64px rgba(30, 41, 59, .16);
            text-align: center;
        }
        .certificate::before,
        .certificate::after {
            content: "";
            position: absolute;
            width: 280px;
            height: 280px;
            border-radius: 50%;
            background: rgba(14, 165, 233, .12);
        }
        .certificate::before { top: -150px; left: -120px; }
        .certificate::after { right: -120px; bottom: -150px; }
        .eyebrow {
            margin: 0 0 18px;
            color: #4338ca;
            font-size: 15px;
            font-weight: 800;
            letter-spacing: .18em;
            text-transform: uppercase;
        }
        h1 {
            margin: 0;
            color: #1e1b4b;
            font-family: Georgia, "Times New Roman", serif;
            font-size: clamp(48px, 7vw, 78px);
            font-weight: 600;
            line-height: 1.05;
        }
        .intro { margin: 36px 0 10px; color: #64748b; font-size: 19px; }
        .employee {
            display: inline-block;
            min-width: 62%;
            margin: 0;
            padding: 0 20px 12px;
            border-bottom: 2px solid #c7d2fe;
            color: #0f172a;
            font-family: Georgia, "Times New Roman", serif;
            font-size: clamp(36px, 5vw, 58px);
            font-weight: 600;
        }
        .completion { margin: 26px 0 10px; color: #475569; font-size: 18px; }
        .course { margin: 0; color: #0f172a; font-size: 30px; font-weight: 800; }
        .course-code { margin: 8px 0 0; color: #64748b; font-size: 15px; letter-spacing: .08em; }
        .details {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
            margin-top: 52px;
            padding-top: 28px;
            border-top: 1px solid #e2e8f0;
        }
        .detail-label {
            display: block;
            margin-bottom: 7px;
            color: #64748b;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .1em;
            text-transform: uppercase;
        }
        .detail-value { color: #1e293b; font-size: 16px; font-weight: 700; }
        @media print {
            body { min-height: auto; background: #fff; }
            .certificate { width: 297mm; min-height: 210mm; box-shadow: none; }
        }
    </style>
</head>
<body>
<main class="certificate">
    <p class="eyebrow">{{ $company_name }}</p>
    <h1>Certificate of completion</h1>
    <p class="intro">This certifies that</p>
    <p class="employee">{{ $employee_name }}</p>
    <p class="completion">successfully completed</p>
    <p class="course">{{ $course_title }}</p>
    @if ($course_code)
        <p class="course-code">Course {{ $course_code }}</p>
    @endif

    <section class="details" aria-label="Certificate details">
        <div>
            <span class="detail-label">Completed</span>
            <span class="detail-value">{{ $completion_date }}</span>
        </div>
        <div>
            <span class="detail-label">Certificate number</span>
            <span class="detail-value">{{ $certificate_number }}</span>
        </div>
        <div>
            <span class="detail-label">Assessment result</span>
            <span class="detail-value">{{ $score ?: 'Completed' }}</span>
        </div>
    </section>
</main>
</body>
</html>
