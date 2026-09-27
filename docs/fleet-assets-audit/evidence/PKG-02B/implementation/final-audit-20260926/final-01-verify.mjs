import fs from 'node:fs/promises';
import { FileBlob, SpreadsheetFile } from '@oai/artifact-tool';

const root = new URL('.', import.meta.url);
const fixtures = JSON.parse(await fs.readFile(new URL('final-01-probe.json', root), 'utf8'));
const results = [];
for (const fixture of fixtures) {
  const workbook = await SpreadsheetFile.importXlsx(await FileBlob.load(new URL(fixture.file, root).pathname.replace(/^\/([A-Z]:)/i, '$1')));
  workbook.recalculate();
  const sheet = workbook.worksheets.getItem('Trips');
  const totalCell = `J${8 + fixture.input_trip_seconds.length}`;
  const total = sheet.getRange(totalCell).values[0][0];
  if (Math.abs(total * 60 - fixture.recorded_total_seconds) > 1e-8) throw new Error(`Recalculation failed: ${fixture.file}`);
  results.push({ file: fixture.file, engine: '@oai/artifact-tool', totalCell, recalculatedMinutes: total,
    seconds: total * 60, formula: sheet.getRange(totalCell).formulas[0][0],
    matchesRecordedSeconds: true });
  if (fixture.file.endsWith('case-0.xlsx')) {
    const image = await workbook.render({ sheetName: 'Trips', range: 'I7:L10', scale: 2, format: 'png' });
    await fs.writeFile(new URL('final-01-duration-render.png', root), new Uint8Array(await image.arrayBuffer()));
  }
}
await fs.writeFile(new URL('final-01-recalculation.json', root), JSON.stringify(results, null, 2));
console.log(JSON.stringify(results, null, 2));
