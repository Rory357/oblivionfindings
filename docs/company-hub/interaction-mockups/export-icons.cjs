const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const source=fs.readFileSync(path.join(__dirname,'hub-icons.js'),'utf8');
const context={};vm.createContext(context);vm.runInContext(source.slice(0,source.indexOf('const hubGlyphAliases'))+'\nglobalThis.glyphs=hubGlyphs;',context);
const destination=path.join(__dirname,'icons');fs.mkdirSync(destination,{recursive:true});
for(const [name,glyph] of Object.entries(context.glyphs)){
 const svg=`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" role="img" aria-labelledby="title"><title id="title">${glyph.label}</title>${glyph.shape}</svg>\n`;
 fs.writeFileSync(path.join(destination,name+'.svg'),svg);
}
console.log('Saved '+Object.keys(context.glyphs).length+' original feature icons.');
