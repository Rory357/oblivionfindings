import fs from 'node:fs/promises';
const url='http://127.0.0.1:4400',results=[],check=(name,pass)=>{results.push({name,pass});if(!pass)throw Error(name)};
check('PDF POST rejects missing local origin',(await fetch(url+'/preview-report.pdf',{method:'POST',body:'{}'})).status===403);
check('PDF POST rejects invalid payload',(await fetch(url+'/preview-report.pdf',{method:'POST',headers:{origin:url},body:'{}'})).status===400);
check('Static preview remains available',(await fetch(url+'/',{method:'HEAD'})).status===200);
await fs.writeFile(new URL('./qa-server.json',import.meta.url),JSON.stringify({results},null,2)+'\n');console.log(JSON.stringify({passed:results.length}));
