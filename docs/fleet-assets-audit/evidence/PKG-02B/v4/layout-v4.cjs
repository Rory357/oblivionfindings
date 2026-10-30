const fs=require('node:fs'),path=require('node:path');const base=path.resolve(__dirname,'../../../previews/PKG-02B/v4');function edit(file,fn){const p=path.join(base,file);fs.writeFileSync(p,fn(fs.readFileSync(p,'utf8').replace(/\r\n/g,'\n')));}function rep(s,a,b){if(!s.includes(a))throw Error('Missing '+a.slice(0,90));return s.replace(a,b);}
edit('main.tsx',s=>{
 s="import {StudioSurface,StudioChecks} from './studio';\nimport {MapStudio,TripStudio,seedFences,type SharedFence} from './map-studio';\nimport {RecordStudio,BookingsStudio} from './record-studio';\n"+s;
 s=rep(s,"import './styles.css';","import './styles.css';\nimport './studio.css';");
 s=rep(s,'    MapPin,','    MapPin,\n    Route,\n    Camera,');
 s=rep(s,"    { key: 'map', label: 'Map', icon: MapPin },","    { key: 'map', label: 'Map', icon: MapPin },\n    { key: 'trips', label: 'Trip history', icon: Route },");
 s=rep(s,"    map: [{ key: 'summary', label: 'Map & observations', icon: MapPin }],","    map: [{ key: 'summary', label: 'Location & geofences', icon: MapPin }],\n    trips: [{key:'summary',label:'Vehicle trips',icon:Route}],");
 s=rep(s,'function App() {',"function App() {\n    const [fences,setFences]=useState<SharedFence[]>(seedFences),[selectedFences,setSelectedFences]=useState<(string|number)[]>(['GEO-DEMO-01']);");
 s=rep(s,'<BookingPanel model={model} />','<BookingsStudio model={model} />');
 s=rep(s,'<WorkRecord','<RecordStudio');
 s=rep(s,'<VehicleSurface','<StudioSurface');
 const a=s.indexOf("                                        {view === 'map' && ("),b=s.indexOf('                                    </div>\n                                </>',a);
 if(a<0||b<0)throw Error('Surface bounds');
 s=s.slice(0,a)+`                                        {view==='map'&&<MapStudio model={model} dark={dark} noTracker={stale||empty} unavailable={fault==='map'} onTrips={()=>nav('trips')} fences={fences} setFences={setFences} selectedFences={selectedFences} setSelectedFences={setSelectedFences}/>}
                                        {view==='trips'&&<TripStudio model={model} dark={dark} noTracker={stale||empty}/>}
                                        {view==='checks'&&<StudioChecks model={model} runs={runs} templates={sub==='templates'} onStart={template=>{setCheckTemplate(template);setDialog('check');}} onView={r=>{setRun({...r,attachments:[...(r.attachments||[]),...model.data.documents.filter(f=>f.owner===r.id)]});setDialog('run');}}/>}
`+s.slice(b);
 const mark='<div className="eh-mark-ring">';let p=s.indexOf(mark,s.indexOf('title={')); // use exact full block instead
 s=rep(s,`<div className="eh-mark-ring">
                                            {work ? (
                                                <Wrench size={23} />
                                            ) : (
                                                <Car size={25} />
                                            )}
                                        </div>`,`<button className="eh-mark-ring photo-header-trigger" disabled={!!work||!model.canManage} aria-label="Upload vehicle profile photo" onClick={()=>model.setPhotoOpen(true)}>{work?<Wrench size={23}/>:model.data.photo?.url?<img src={model.data.photo.url} alt="Kōwhai van"/>:<Car size={25}/>} {!work&&<Camera size={15} className="photo-corner"/>}</button>`);
 s=rep(s,'                                items={model.calendarItems}',`                                items={model.calendarItems}
                                actionsFor={id=>{const b=model.data.bookings.find(b=>b.id===id);const w=model.data.works.find(w=>w.id===id);return b&&model.canManage&&!['Returned','Cancelled'].includes(b.status)?[{label:'Change booking',run:()=>model.booking(b.start,b.id,b.block),disabled:b.status==='Checked out'},{label:b.status==='Pending approval'?'Review & approve':b.status==='Confirmed'?'Check out':'Record return',run:()=>model.bookingTransition(b.id,b.status==='Pending approval'?'approve':b.status==='Confirmed'?'out':'return'),disabled:!!b.block},{label:'Cancel booking',run:()=>model.bookingTransition(b.id,'cancel'),disabled:b.status==='Checked out'}]:w&&model.canManage?[{label:'Manage appointment',run:()=>model.plan(w.id)}]:[];}}
`);
 return s;
});
edit('vehicle-calendar.tsx',s=>{
 s=rep(s,"import { useMemo, useState } from 'react';","import { useMemo, useState, useRef } from 'react';");
 s=rep(s,'    onOpen,\n}: {','    onOpen,\n    actionsFor,\n}: {');
 s=rep(s,'    onOpen: (id: string) => void;','    onOpen: (id: string) => void;\n    actionsFor?: (id:string)=>{label:string;run:()=>void;disabled?:boolean}[];');
 s=rep(s,"    const [view, setView] = useState<View>('month');","    const rightClick=useRef<{x:number;y:number}|null>(null);\n    const [eventMenu,setEventMenu]=useState<{x:number;y:number;entry:Decorated}|null>(null);\n    const [view, setView] = useState<View>('month');");
 s=rep(s,'    const onSelect = (entry: Decorated) => onOpen(entry.id);',`    const onSelect = (entry: Decorated) => {if(rightClick.current){setEventMenu({...rightClick.current,entry});rightClick.current=null;}else onOpen(entry.id);};`);
 s=rep(s,'                  e.preventDefault();\n                  setCreation(', '                  e.preventDefault();\n                  rightClick.current=null;\n                  setCreation(');
 s=rep(s,'            className="vehicle-calendar"',`            className="vehicle-calendar"
            onContextMenuCapture={e=>{rightClick.current={x:e.clientX,y:e.clientY};}}
            onKeyDownCapture={()=>{rightClick.current=null;}}`);
 s=rep(s,'            onPointerDownCapture={(event) => {',`            onPointerDownCapture={(event) => {
                rightClick.current=event.button===2?{x:event.clientX,y:event.clientY}:null;`);
 s=rep(s,'            {creation && (',`            {eventMenu&&<DropdownMenu open onOpenChange={open=>{if(!open){setEventMenu(null);rightClick.current=null;}}}><DropdownMenuTrigger style={{position:'fixed',left:eventMenu.x,top:eventMenu.y,width:1,height:1}} aria-label="Calendar entry actions"/><DropdownMenuContent align="start"><DropdownMenuLabel>{eventMenu.entry.title}</DropdownMenuLabel><DropdownMenuItem onSelect={()=>{onOpen(eventMenu.entry.id);setEventMenu(null);}}>Open source record</DropdownMenuItem>{actionsFor?.(eventMenu.entry.id).map(action=><DropdownMenuItem key={action.label} disabled={action.disabled} onSelect={()=>{action.run();setEventMenu(null);}}>{action.label}</DropdownMenuItem>)}</DropdownMenuContent></DropdownMenu>}
            {creation && (`);
 return s;
});
