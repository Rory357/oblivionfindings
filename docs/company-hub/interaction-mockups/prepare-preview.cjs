const fs=require('node:fs');
const path=require('node:path');
const React=require('react');
const {renderToStaticMarkup}=require('react-dom/server');
const lucide=require(path.join(path.dirname(require.resolve('lucide-react')),'../esm/lucide-react.js'));
const directory=__dirname;
const source=fs.readdirSync(directory).filter(f=>f.endsWith('.js')).map(f=>fs.readFileSync(path.join(directory,f),'utf8')).join('\n');
const names=new Set([...source.matchAll(/['"]([a-z][a-z0-9]*(?:-[a-z0-9]+)*)['"]/g)].map(m=>m[1]));
const map={};
for(const name of names){const key=name.split('-').map(s=>s[0].toUpperCase()+s.slice(1)).join('');if(lucide[key])map[name]=renderToStaticMarkup(React.createElement(lucide[key],{width:18,height:18,'aria-hidden':true}));}
fs.writeFileSync(path.join(directory,'preview-icons.js'),`globalThis.lucide={createIcons(){document.querySelectorAll('i[data-lucide]').forEach(el=>{const markup=(${JSON.stringify(map)})[el.dataset.lucide];if(markup){el.outerHTML=markup;}});}};`);
console.log('Prepared '+Object.keys(map).length+' local Lucide icons for standalone preview.');
