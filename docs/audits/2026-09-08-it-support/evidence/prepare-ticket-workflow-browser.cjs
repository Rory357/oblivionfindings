// Derive an isolated harness from the repository's guarded W06 browser fixture.
// Keeps the source guards, fresh-schema refusal, local mail and exact cleanup checks.
const fs = require('node:fs');
const path = require('node:path');
const directory = __dirname;
for (const suffix of ['environment.ps1', 'runtime.php', 'bootstrap.php', 'fixtures.php', 'router.php', 'teardown.php']) {
    const target = path.join(directory, `ticket-workflow-browser-${suffix}`);
    if (fs.existsSync(target)) throw new Error(`Refusing to replace existing harness: ${target}`);
    let source = fs.readFileSync(path.join(directory, `w06-draft-browser-${suffix}`), 'utf8')
        .replaceAll('w06-draft-browser', 'ticket-workflow-browser')
        .replaceAll('it-draft-browser-', 'it-ticket-work-browser-')
        .replaceAll('oblivion_it_draft_browser_', 'oblivion_ticket_work_browser_')
        .replaceAll('public/build/manifest.json', 'public/ticket-workflow-review/manifest.json')
        .replaceAll("$public.'/build/manifest.json'", "$public.'/ticket-workflow-review/manifest.json'")
        .replaceAll('8766, 8767', '8779, 8780').replaceAll('= 8766', '= 8779');
    if (suffix === 'runtime.php') source = source.replace('    return $app;', "    $app->booted(fn () => app(\\Illuminate\\Foundation\\Vite::class)->useBuildDirectory('ticket-workflow-review'));\n\n    return $app;");
    fs.writeFileSync(target, source, { flag: 'wx' });
}
