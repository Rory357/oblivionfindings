// Lossless packing keeps the expanded demo inside the host's 16 KiB snapshot limit.
// Legacy, unpacked snapshots remain readable. No content is discarded to make room.
function packHubData(value){
 const input=new TextEncoder().encode(JSON.stringify(value)),dictionary=new Map(),codes=[];let next=256,phrase='';
 for(const byte of input){const char=String.fromCharCode(byte),joined=phrase+char;if(!phrase||dictionary.has(joined)){phrase=joined;continue;}codes.push(phrase.length===1?phrase.charCodeAt(0):dictionary.get(phrase));if(next<65536)dictionary.set(joined,next++);phrase=char;}
 if(phrase)codes.push(phrase.length===1?phrase.charCodeAt(0):dictionary.get(phrase));
 let binary='';for(const code of codes)binary+=String.fromCharCode(code>>8,code&255);return btoa(binary);
}
function unpackHubSnapshot(value){
 if(value?.hubPacked!==1)return value;
 try{const binary=atob(value.data);if(binary.length%2||binary.length>100000)return null;const dictionary=Array.from({length:256},(_,i)=>String.fromCharCode(i));let previous='',output='',next=256;
 for(let i=0;i<binary.length;i+=2){const code=(binary.charCodeAt(i)<<8)|binary.charCodeAt(i+1),entry=dictionary[code]||(code===next&&previous?previous+previous[0]:null);if(entry===null)return null;output+=entry;if(output.length>250000)return null;if(previous&&next<65536)dictionary[next++]=previous+entry[0];previous=entry;}
 return JSON.parse(new TextDecoder().decode(Uint8Array.from(output,c=>c.charCodeAt(0))));
 }catch{return null;}
}
const storageEnvelopeBase=stateEnvelope;
stateEnvelope=function(candidate=state){const envelope=storageEnvelopeBase(candidate),packed={hubPacked:1,data:packHubData(envelope.privateContent)};if(JSON.stringify(packed).length<JSON.stringify(envelope.privateContent).length)envelope.privateContent=packed;return envelope;};
