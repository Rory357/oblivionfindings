import {actors,fleet,siteNames,TODAY,NOW,permitted,validDate,execute as executeBase,type Store,type Transport,type Command,type CommandContext,type Vehicle} from './domain';
export const keyPlaces=[
 {id:'aurora-office',site:'aurora',name:'Office key safe · bay A2',room:'Aurora House office',instructions:'Collect from the on-duty receiving worker at the office. Return to the same worker; record safe placement separately.'},
 {id:'aurora-reception',site:'aurora',name:'Reception handover point',room:'Aurora House reception',instructions:'Named worker handover only. This is not a secure storage confirmation.'},
 {id:'kowhai-office',site:'kowhai',name:'Office storage point · needs confirmation',room:'Kōwhai House office',instructions:'The exact key storage point has not been confirmed for this example.'},
];
export type KeyPlan={pickup:string;dropoff:string;mover:string;receiver:string;method:string;note:string;deliveryWorker?:string};
export type KeyEvent={id:string;vehicle:Vehicle;record:string;time:string;kind:'stored'|'collected'|'received';holder:string;place:string;actor:string;note:string};
export function placeLabel(id:string){const p=keyPlaces.find(v=>v.id===id);return p?siteNames[p.site]+' · '+p.name:id==='with_worker'?'With named worker':'Location not confirmed'}
export function keyRef(r:Transport){return r.booking?'KEY-'+fleet[r.booking.vehicle].ref.slice(4):'No allocated vehicle key'}
export function seedKeys(store:Store):Store{
 const events:KeyEvent[]=Object.keys(fleet).filter(v=>v!=='tui').map((v,i)=>({id:'KEY-SEED-'+i,vehicle:v as Vehicle,record:'',time:'2026-09-27T17:00',kind:'stored',holder:'Ben Carter',place:'aurora-office',actor:'Ben Carter',note:'Example custody record: secured at the approved site storage point.'}));
 for(const r of store.records){if(!r.booking)continue;if(r.checkout)events.push({id:r.id+'-key-out',vehicle:r.booking.vehicle,record:r.id,time:r.checkout.time,kind:'collected',holder:r.checkout.receiver,place:'with_worker',actor:r.checkout.receiver,note:'Collected for '+r.booking.id});for(const receipt of r.receipts)if(receipt.keys)events.push({id:receipt.id+'-key-in',vehicle:r.booking.vehicle,record:r.id,time:receipt.time,kind:'received',holder:receipt.receiver,place:'with_worker',actor:receipt.recordedBy,note:'Receipt '+receipt.id+' confirms the receiving worker. It does not prove placement in a safe.'})}
 events.push({id:'KEY-STORED-312',vehicle:'koru',record:'TR-1047',time:TODAY+'T08:12',kind:'stored',holder:'Ben Carter',place:'aurora-office',actor:'Ben Carter',note:'Separate example confirmation of physical placement after RC-312.'});
 return {...store,keyEvents:events};
}
export function currentKey(store:Store,r:Transport){return store.keyEvents?.filter(e=>e.vehicle===r.booking?.vehicle).sort((a,b)=>a.time.localeCompare(b.time)).at(-1)}
export function plannedKeys(r:Transport):KeyPlan{return r.keyPlan||{pickup:r.site==='aurora'?'aurora-office':'',dropoff:r.site==='aurora'?'aurora-office':'',mover:r.booking?.driver||'',receiver:r.site==='aurora'?'Ben Carter':'',method:'Driver collects and returns',note:''}}
export function custodySearch(store:Store,r:Transport){const current=currentKey(store,r),plan=plannedKeys(r);return [keyRef(r),current?.holder,placeLabel(current?.place||''),placeLabel(plan.pickup),placeLabel(plan.dropoff),plan.mover,plan.receiver,plan.deliveryWorker,plan.method,plan.note].join(' ')}
function requireIt(value:unknown,message:string):asserts value {if(!value)throw Error(message)}
export function executeWithKeys(store:Store,c:Command,ctx:CommandContext){
 const r=store.records.find(v=>v.id===c.record);requireIt(r&&permitted(r,ctx.role,ctx.load),'Record unavailable in your approved sites.');
 const oldKey=currentKey(store,r!),actor=actors[ctx.role].name;
 if(c.type!=='keyplan'&&c.type!=='keymove'){
  if(c.type==='custody')requireIt(ctx.frontlineAuthority,'Scoped staff custody authority is not configured. Ask the authorised Fleet team.');
  if(c.type==='checkout'&&!store.operations[c.id]){requireIt(oldKey?.kind==='stored','The vehicle key is still with a worker or its location is unknown. Resolve custody before collection.');requireIt(c.payload.giver===oldKey.holder,'The actual giver must match the current key custody record.');requireIt(c.payload.time>=oldKey.time,'Collection cannot predate the current custody record.')}
  if((c.type==='return'||c.type==='custody')&&c.payload.keys===true&&!store.operations[c.id]){requireIt(oldKey?.record===r!.id,'This booking is not the current recorded key custody. Record a reconciliation instead of overwriting a newer holder.');requireIt(c.payload.time>=oldKey.time,'A key receipt cannot predate the current custody record.')}
  const result=executeBase(store,c,ctx);if(result.replayed)return result;const updated=result.store.records.find(v=>v.id===c.record)!;
  if(updated.booking&&(c.type==='checkout'||['return','custody'].includes(c.type)&&c.payload.keys===true)){
   (result.store.keyEvents||=[]).push({id:'KEY-'+(++result.store.sequence),vehicle:updated.booking.vehicle,record:updated.id,time:c.payload.time,kind:c.type==='checkout'?'collected':'received',holder:c.type==='checkout'?actor:c.type==='custody'?actor:c.payload.receiver,place:'with_worker',actor,note:c.type==='checkout'?'Collected for '+updated.booking.id:'Physical receipt recorded. Placement into storage still needs its own confirmation.'});
  }return result;
 }
 requireIt(ctx.load==='ready','Refresh the source before recording key custody.');
 const fingerprint=JSON.stringify({type:c.type,record:c.record,version:c.version,payload:c.payload});
 const prior=store.operations[c.id];if(prior){requireIt(prior.fingerprint===fingerprint,'This retry has changed observations. Recover the original record.');return {store,result:prior.result,replayed:true}}
 requireIt(r!.version===c.version,'The record changed. Refresh before saving.');requireIt(!!r!.booking,'Allocate a vehicle before planning its keys.');
 const next=structuredClone(store),target=next.records.find(v=>v.id===r!.id)!,p=c.payload;let result='';
 if(c.type==='keyplan'){
  requireIt(ctx.role==='allocator','The transport allocator manages this collection and return plan.');requireIt(!r!.checkout,'Keys have already been collected. Keep the original plan and record actual custody changes.');
  for(const id of [p.pickup,p.dropoff])requireIt(keyPlaces.some(v=>v.id===id&&v.site===r!.site&&v.id!=='kowhai-office'),'Choose a confirmed key point at the approved site.');
  requireIt(p.dropoff==='aurora-office','Choose a confirmed secure return storage point.');requireIt(p.mover===r!.booking!.driver&&p.receiver==='Ben Carter','Choose the assigned driver and an approved receiving worker.');requireIt(['Driver collects and returns','Named worker delivers to driver'].includes(p.method),'Select how the keys will reach the driver.');
  requireIt(p.method!=='Named worker delivers to driver'||p.deliveryWorker==='Ben Carter','Choose the named worker responsible for delivery.');requireIt(p.method!=='Named worker delivers to driver'||String(p.note||'').trim(),'Describe the meeting place and delivery arrangement.');
  target.keyPlan={pickup:p.pickup,dropoff:p.dropoff,mover:p.mover,receiver:p.receiver,method:p.method,deliveryWorker:p.method==='Named worker delivers to driver'?p.deliveryWorker:undefined,note:String(p.note||'')};result='Key collection and return plan saved';
 }else{
  requireIt(ctx.frontlineAuthority,'Scoped staff custody authority is not configured. Ask the authorised Fleet team.');requireIt(ctx.role==='receiver'&&oldKey?.holder===actor,'Only the current receiving worker can confirm physical storage.');
  requireIt(c.payload.expectedKey===oldKey?.id,'Key custody changed. Recheck the current holder.');requireIt(oldKey?.record===r!.id&&oldKey.kind==='received','This record is not the current received key custody.');
  requireIt(keyPlaces.some(v=>v.id===p.place&&v.site===r!.site&&v.id==='aurora-office'),'Choose a confirmed secure storage point at this site.');requireIt(validDate(p.time)&&p.time>=oldKey!.time,'Use a valid observation time after receipt.');requireIt(p.confirmed===true,'Confirm the keys were physically placed at this storage point.');requireIt(String(p.note||'').trim(),'Add a storage observation.');
  (next.keyEvents||=[]).push({id:'KEY-'+(++next.sequence),vehicle:r!.booking!.vehicle,record:r!.id,time:p.time,kind:'stored',holder:actor,place:p.place,actor,note:p.note});result='Key storage confirmed';
 }
 target.version++;target.events.push({id:'EV-'+(++next.sequence),source:c.type==='keymove'?next.keyEvents!.at(-1)!.id:keyRef(target),time:p.time||NOW,actor,title:result,detail:c.type==='keyplan'?placeLabel(p.pickup)+' → '+placeLabel(p.dropoff)+'. '+p.method+'. '+p.note:placeLabel(p.place)+'. '+p.note});next.operations[c.id]={fingerprint,result};return {store:next,result,replayed:false};
}
