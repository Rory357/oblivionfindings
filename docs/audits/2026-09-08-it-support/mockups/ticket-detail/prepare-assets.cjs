// Read-only extraction of the current shared tokens and Lucide icon package.
// Writes exclusively to this mockup directory; not part of the application build.
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../../../../..');
const source = fs.readFileSync(path.join(root, 'resources/css/app.css'), 'utf8');
const declarations = source.slice(source.indexOf(':root {') + 8, source.indexOf('    /* Category tokens'));
const header = source.slice(source.indexOf('.eh-header {'), source.indexOf('/* Hide scrollbars on icon sidebar */'));
fs.writeFileSync(path.join(__dirname, 'tokens.css'), '/* Snapshot of current app.css tokens and PageHeader utilities, 13 Sep 2026. */\n:root {\n' + declarations + '\n}\n' + header);
const React = require(path.join(root, 'node_modules/react'));
const { renderToStaticMarkup } = require(path.join(root, 'node_modules/react-dom/server'));
const lucide = require(path.join(root, 'node_modules/lucide-react'));
const names = ['FlaskConical','Search','ShieldAlert','Clock3','MessageSquare','Bell','Sun','LayoutDashboard','CalendarDays','Calendar','ListTodo','Building2','Layers','Users','ShieldCheck','TriangleAlert','Landmark','Car','Wallet','MonitorCog','ChevronRight','ChevronDown','ChevronLeft','Settings','Ticket','MoreHorizontal','ArrowUpRight','ListChecks','Info','Activity','Paperclip','FileText','Link2','Timer','CheckCircle2','Circle','Lock','Globe','Send','BookOpen','X','ArrowRight','Pencil','Eye','EyeOff','UserRound','Printer','Image','Download','RotateCcw','GitMerge','Copy','UserPlus','Check','Plus','ClipboardCheck','Wrench','LifeBuoy','Mail','Phone','NotebookPen','CircleHelp','ArrowDown','RefreshCw','ArrowLeft','Save','CircleX'];
const icons = Object.fromEntries(names.map(name => {
  if (!lucide[name]) throw new Error('Unknown Lucide icon ' + name);
  return [name, renderToStaticMarkup(React.createElement(lucide[name], { className: 'icon', 'aria-hidden': true, width:16,height:16 }))];
}));
fs.writeFileSync(path.join(__dirname, 'icons.js'), '/* Lucide icons, rendered from the installed package. ISC licence. */\nwindow.MOCK_ICONS = ' + JSON.stringify(icons) + ';\n');
console.log('Created isolated token snapshot and ' + names.length + ' Lucide icons.');
