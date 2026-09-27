import fs from 'node:fs';
const app=new URL('./app.tsx',import.meta.url),test=new URL('./verify-flow.js',import.meta.url);
fs.writeFileSync(app,fs.readFileSync(app,'utf8').replace('{!j&&<Card title="Before departure"',"{!j&&activeDemand(r)&&['approved','checked_out'].includes(b.state)&&<Card title=\"Before departure\""));
fs.writeFileSync(test,fs.readFileSync(test,'utf8').replace("includes('Transport cancelled')&&await", "includes('Transport cancelled')&&!(await main()).includes('Before departure')&&await"));
