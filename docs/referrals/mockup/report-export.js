// Preserve literal values when opening a CSV in spreadsheet applications.
export function csvCell(value) {
    let text = String(value ?? '');
    if (/^[\s]*[=+@-]/.test(text)) text = "'" + text;
    return '"' + text.replaceAll('"', '""') + '"';
}
export function reportCsv(report) {
    const rows = [
        ['Report', report.title],
        ['Data', 'Fictional prototype data'],
        ['Period / scope', report.period],
        ['Site', report.site],
        ['Search', report.search],
        [],
        ['Measure', 'Value'],
        ...report.measures.map((m) => [m.label, m.value]),
    ];
    return rows.map((row) => row.map(csvCell).join(',')).join('\r\n');
}
