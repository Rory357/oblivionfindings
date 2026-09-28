import catalogue from './fixtures.json';
import type { Definition, Saved } from '@/pages/reporting/model';
import { safeCsvCell } from '@/pages/reporting/model';

// This adapter is bundled only by this isolated review preview, never by the application.
export function installSyntheticApi(): Saved[] {
  const key='operational-reports-synthetic-preview';
  let saved: Saved[]=JSON.parse(localStorage.getItem(key)??'[]');
  const versions=new Map<number,unknown[]>(); const runs=new Map<string,any>();
  const original=window.fetch.bind(window);
  const response=(value:unknown,status=200)=>new Response(JSON.stringify(value),{status,headers:{'Content-Type':'application/json'}});
  const persist=()=>localStorage.setItem(key,JSON.stringify(saved));
  window.fetch=async(input,options)=>{
    const url=new URL(String(input),location.href); if(!url.pathname.startsWith('/report-builder/'))return original(input,options);
    const body=options?.body?JSON.parse(String(options.body)):{}; const path=url.pathname;
    if(path.endsWith('/targets'))return response({targets:[{id:1,label:'Demo resource or person A'},{id:2,label:'Demo resource or person B'}],sites:[{id:1,label:'Demo North'},{id:2,label:'Demo South'}]});
    if(path.endsWith('/validate')){
      const d=body.definition;
      if(!d || d.version!==1 || !(d.source in catalogue.sources) || !Array.isArray(d.measures) || d.measures.some((m:any)=>!Number.isInteger(m.decimals)||m.decimals<0||m.decimals>6))return response({message:'Invalid definition: check the source and decimal places (0–6).'},422);
      return response({definition:d});
    }
    if(path==='/report-builder/runs' && (options?.method??'GET')==='GET')return response({runs:[...runs.values()].map(r=>({...r,payload:undefined}))});
    if(path==='/report-builder/runs'){
      if(!body.reason?.trim())return response({message:'Add a purpose before running the report.'},422);
      const id=crypto.randomUUID();const row={id,status:'ready',definition:body.definition,created_at:new Date().toISOString(),expires_at:new Date(Date.now()+86400000).toISOString(),payload:calculate(body.definition)};runs.set(id,row);return response(row,202);
    }
    const run=path.match(/\/runs\/([^/]+)(?:\/(export|cancel))?$/);
    if(run){const row=runs.get(run[1]);if(!row)return response({message:'This synthetic result is no longer available. Run it again.'},404);
      if(run[2]==='export'){const rows=row.payload.result.rows; const d=row.definition; if(body.format==='json')return new Response(JSON.stringify(row.payload,null,2)); const grid=body.section==='summary'?[[...d.groups,...d.measures.map((m:any)=>m.label)],...row.payload.result.groups.map((g:any)=>[...g.dimensions,...d.measures.map((m:any)=>g.values[m.id])])]:[d.columns,...rows.map((r:any)=>d.columns.map((c:string)=>r[c]??null))]; const csv=grid.map(r=>r.map(safeCsvCell).join(',')).join('\n');return new Response(csv,{headers:{'Content-Type':'text/csv'}});}
      if(run[2]==='cancel')row.status='cancelled';return response(row);}
    if(path==='/report-builder/reports'||/\/reports\/\d+$/.test(path)){
      const id=Number(path.split('/').pop())||Date.now(); const prior=saved.find(s=>s.id===id);
      const report={id,name:body.definition.name,source:body.definition.source,definition:body.definition,version:(prior?.version??0)+1,archived_at:null,folder:body.folder??null,favourite:body.favourite??false};
      saved=[...saved.filter(s=>s.id!==id),report];versions.set(id,[{version:report.version,definition:report.definition},...(versions.get(id)??[])]);persist();return response({report});
    }
    const match=path.match(/\/reports\/(\d+)\/(archive|versions|subscription|share)$/);
    if(match){const id=Number(match[1]);const report=saved.find(s=>s.id===id);if(!report)return response({message:'Save the demo report first.'},404);
      if(match[2]==='archive'){report.archived_at=body.archived?new Date().toISOString():null;persist();return response({report});}
      if(match[2]==='versions')return response({versions:versions.get(id)??[{version:report.version,definition:report.definition}]});
      return response(match[2]==='subscription'?{subscription:{...body}}:{shared_count:body.remove?0:1});
    }
    return response({message:'This preview action is unavailable.'},404);
  };
  return saved;
}

function calculate(d:Definition){
  const fields=(catalogue.sources as any)[d.source].fields;
  let rows:any[]=Array.from({length:8},(_,i)=>Object.fromEntries(Object.entries(fields).map(([key,meta]:[string,any])=>[key,key==='reference'?'DEMO-'+(i+1):key==='date'?(i<4?d.date_from:d.date_to):key==='resource'?'Demo resource '+(i%2+1):key==='site'?'Demo '+(i%2?'South':'North'):key==='status'?(i===7?'open':'completed'):key==='charging'?(i%2?'charge_full':'charging'):key==='motion'?(i%2?'Moving':'Stationary'):key==='event_type'?'Reported sample':meta.type==='number'?(i===3?null:key==='battery'?72-i:key==='latitude'?-36.85:key==='longitude'?174.76:key==='distance'?12.5:i+1):meta.type==='date'?d.date_from+'T03:00:00Z':'Demo evidence'])));
  rows=rows.map(r=>({...r,...(d.precision==='redacted'?{latitude:null,longitude:null}:{})}));
  const test=(row:any,f:any)=>{const value=row[f.field];const v=f.value;switch(f.operator){case'missing':return value==null;case'known':return value!=null;case'contains':return value!=null&&String(value).toLowerCase().includes(String(v).toLowerCase());case'eq':return value!=null&&String(value)===String(v);case'ne':return value!=null&&String(value)!==String(v);case'gt':return value!=null&&value>Number(v);case'gte':return value!=null&&value>=Number(v);case'lt':return value!=null&&value<Number(v);case'lte':return value!=null&&value<=Number(v);default:return false;}};
  rows=rows.filter(r=>d.filter_groups?.length?(d.match==='all'?d.filter_groups.every(g=>g.match==='all'?g.filters.every(f=>test(r,f)):g.filters.some(f=>test(r,f))):d.filter_groups.some(g=>g.match==='all'?g.filters.every(f=>test(r,f)):g.filters.some(f=>test(r,f)))):d.filters.length===0||(d.match==='all'?d.filters.every(f=>test(r,f)):d.filters.some(f=>test(r,f))));
  const values=(items:any[])=>{const result:Record<string,number|null>={};for(const m of d.measures){if(m.operation==='formula')continue;const subset=m.where?items.filter(r=>test(r,m.where)):items;const known=subset.map(r=>r[m.field??'']).filter(v=>v!=null);const nums=known.filter(v=>typeof v==='number').sort((a,b)=>a-b);let n:number|null=null;switch(m.operation){case'count':n=subset.length;break;case'known':n=known.length;break;case'distinct':n=new Set(known.map(v=>JSON.stringify(v))).size;break;case'sum':n=nums.length?nums.reduce((a,b)=>a+b,0):null;break;case'avg':n=nums.length?nums.reduce((a,b)=>a+b,0)/nums.length:null;break;case'min':n=nums[0]??null;break;case'max':n=nums.at(-1)??null;break;case'p50':case'p95':if(nums.length){const j=(nums.length-1)*(m.operation==='p50'?.5:.95);n=nums[Math.floor(j)]+(nums[Math.ceil(j)]-nums[Math.floor(j)])*(j-Math.floor(j));}}result[m.id]=n;}
    for(const m of d.measures)if(m.operation==='formula'){const f=m.formula?.match(/^(m[1-8])\s*([+*/-])\s*(m[1-8])$/);const a=f?result[f[1]]:null,b=f?result[f[3]]:null;result[m.id]=f&&a!=null&&b!=null?(f[2]==='/'?(b===0?null:a/b):f[2]==='*'?a*b:f[2]==='+'?a+b:a-b):null;}return result;};
  const bucketDate=(date:string)=>{const day=new Date(date.slice(0,10)+'T12:00:00Z');if(d.date_bucket==='week')day.setUTCDate(day.getUTCDate()-(day.getUTCDay()+6)%7);if(d.date_bucket==='month')day.setUTCDate(1);return day.toISOString().slice(0,10);};
  const dimension=(row:any,key:string)=>key==='date'&&row[key]?bucketDate(row[key]):row[key]??null;
  const pivot_totals={rows:[] as any[],columns:[] as any[]};if(d.groups.length===2)for(const [axis,index] of [['rows',0],['columns',1]] as const){const margin=new Map<string,any[]>();for(const row of rows){const key=JSON.stringify(dimension(row,d.groups[index]));margin.set(key,[...(margin.get(key)??[]),row]);}pivot_totals[axis]=[...margin].map(([key,items])=>({dimensions:[JSON.parse(key)],values:values(items),row_count:items.length}));}
  if(d.detail_sort)rows.sort((a,b)=>{const x=a[d.detail_sort!],y=b[d.detail_sort!];if(x==null||y==null)return Number(x==null)-Number(y==null);return (x<y?-1:x>y?1:0)*(d.detail_direction==='desc'?-1:1);});
  const buckets=new Map<string,any[]>();for(const r of rows){const key=JSON.stringify(d.groups.map(k=>dimension(r,k)));buckets.set(key,[...(buckets.get(key)??[]),r]);}
  const groups=[...buckets].map(([key,items])=>({dimensions:JSON.parse(key),values:values(items),row_count:items.length}));
  groups.sort((a,b)=>(d.sort==='value'?(a.values[d.measures[0].id]??0)-(b.values[d.measures[0].id]??0):JSON.stringify(a.dimensions).localeCompare(JSON.stringify(b.dimensions)))*(d.direction==='desc'?-1:1));
  let chart:any[]=groups.slice(0,d.limit);
  if(d.layout==='line'){chart=[];for(let day=new Date(bucketDate(d.date_from)+'T00:00:00Z');day<=new Date(d.date_to+'T00:00:00Z');d.date_bucket==='month'?day.setUTCMonth(day.getUTCMonth()+1):day.setUTCDate(day.getUTCDate()+(d.date_bucket==='week'?7:1))){const key=day.toISOString().slice(0,10);chart.push({...groups.find(g=>g.dimensions[0]===key)??{dimensions:[key],values:Object.fromEntries(d.measures.map(m=>[m.id,null])),row_count:0},timestamp:day.valueOf()});}}
  return {generated_at:new Date().toISOString(),definition_hash:'synthetic-preview',preview_limit:500,source:{window:{from:nzMidnight(d.date_from),to:new Date(Math.min(Date.parse(nzMidnight(new Date(Date.parse(d.date_to+'T00:00:00Z')+86400000).toISOString().slice(0,10)))-1,Date.now())).toISOString(),timezone:'Pacific/Auckland'},watermark:{captured_at:new Date().toISOString()},coverage:'Synthetic review sample only. These are not operational records.'},result:{pivot_totals,row_count:rows.length,group_count:groups.length,missing:Object.fromEntries(d.columns.map(c=>[c,rows.filter(r=>r[c]==null).length])),totals:values(rows),groups,chart,rows},comparison:null};
}

function nzMidnight(date:string){const target=Date.parse(date+'T00:00:00Z');let guess=target;for(let i=0;i<3;i++){const parts=Object.fromEntries(new Intl.DateTimeFormat('en-CA',{timeZone:'Pacific/Auckland',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'}).formatToParts(new Date(guess)).map(p=>[p.type,p.value]));const rendered=Date.UTC(Number(parts.year),Number(parts.month)-1,Number(parts.day),Number(parts.hour),Number(parts.minute),Number(parts.second));guess=target-(rendered-guess);}return new Date(guess).toISOString();}
