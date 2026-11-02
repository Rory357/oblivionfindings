using System;
using System.Collections.Generic;
using System.Globalization;
using System.IO;
using System.Linq;
using System.Text.Json;

namespace Oblivion.Obli;

// S&D Phase I: what the server says in an Obli fix session (sd-obli-fix-protocol.md). Parsed
// strictly: known kinds only, bounded text, no control characters. Unknown extra fields are
// ignored so the server can add detail without breaking older trays.
public sealed record FixCheck(string Tone,string Text);
public sealed record FixPlanStep(string Text,string Kind,string? FixKey,bool ItAllows);
public sealed record FixOffer(string Key,string Label);
public sealed record FixRefusal(string Text,IReadOnlyList<FixOffer> Offers);
public sealed record FixStepRecord(string Text,string Result);
public sealed record FixTicketRef(int Id,string Reference);
public sealed record FixSession(Guid Id,string State,string? Challenge,string Title,IReadOnlyList<FixCheck> Checks,IReadOnlyList<FixPlanStep> Plan,
 int Minutes,string Note,FixRefusal? Refusal,DateTimeOffset? ExpiresAt,IReadOnlyList<FixStepRecord> Steps,string? Outcome,FixTicketRef? Ticket)
{public override string ToString()=>"Private Obli fix session";}

/// <summary>The one next thing the server asks for. Screen actions use picture coordinates.</summary>
public sealed record FixAction(string Type,int X=0,int Y=0,string? Text=null,IReadOnlyList<string>? Keys=null,string? Direction=null,int Amount=0,
 int Seconds=0,string? FixKey=null,string? Label=null,bool Fixed=false,string? Summary=null,IReadOnlyList<string>? Done=null,int Pictures=0,string? DeletedOn=null,FixTicketRef? Ticket=null)
{
 public bool TouchesScreen=>Type is "click" or "double_click" or "right_click" or "type" or "key" or "scroll" or "move";
 public bool Ends=>Type is "done" or "handed_to_it" or "stopped";
 public override string ToString()=>"Obli action "+Type;
}
public sealed record FixReply(int Turn,int Step,int Of,string Narration,FixAction Action);

public static class FixProtocol
{
 public static readonly string[] SessionStates={"confirming","planned","refused","needs_it","working","paused","waiting_it","done","handed_to_it","stopped","failed","expired"};
 public static readonly string[] ScreenActions={"click","double_click","right_click","type","key","scroll","move","wait"};
 public static readonly string[] OtherActions={"fix_running","waiting_it","pause_password","done","handed_to_it","stopped"};

 internal static string Text(JsonElement root,string name,int limit,bool required=true)
 {
  if(!root.TryGetProperty(name,out var value)||value.ValueKind==JsonValueKind.Null){if(required)throw new InvalidDataException("Obli’s answer is missing "+name+".");return "";}
  string text=value.GetString()??throw new InvalidDataException();
  if(text.Length>limit||text.Any(c=>char.IsControl(c)&&c is not('\n' or '\r' or '\t')))throw new InvalidDataException("Obli’s answer has an unexpected "+name+".");
  return text;
 }
 static int Int(JsonElement root,string name,int min,int max,bool required=true)
 {
  if(!root.TryGetProperty(name,out var value)||value.ValueKind==JsonValueKind.Null){if(required)throw new InvalidDataException("Obli’s answer is missing "+name+".");return min;}
  int number=value.GetInt32();
  if(number<min||number>max)throw new InvalidDataException("Obli’s answer has an unexpected "+name+".");
  return number;
 }
 static bool Bool(JsonElement root,string name)=>root.TryGetProperty(name,out var value)&&value.ValueKind==JsonValueKind.True;
 static IEnumerable<JsonElement> List(JsonElement root,string name,int max)
 {
  if(!root.TryGetProperty(name,out var value)||value.ValueKind==JsonValueKind.Null)yield break;
  if(value.ValueKind!=JsonValueKind.Array||value.GetArrayLength()>max)throw new InvalidDataException("Obli’s answer has too many "+name+".");
  foreach(var item in value.EnumerateArray())yield return item;
 }
 static FixTicketRef? Ticket(JsonElement root)
 {
  if(!root.TryGetProperty("ticket",out var t)||t.ValueKind==JsonValueKind.Null)return null;
  int id=Int(t,"id",1,int.MaxValue);string reference=Text(t,"reference",40);
  return new(id,reference);
 }

 public static FixSession ParseSession(ReadOnlyMemory<byte> bytes)
 {
  if(bytes.Length==0||bytes.Length>262144)throw new InvalidDataException("Obli’s answer is too large.");
  using var json=JsonDocument.Parse(bytes,new JsonDocumentOptions{MaxDepth=6});
  var root=json.RootElement;
  if(root.ValueKind!=JsonValueKind.Object)throw new InvalidDataException();
  if(!Guid.TryParseExact(Text(root,"uuid",36),"D",out var id)||id==Guid.Empty)throw new InvalidDataException("Obli’s answer has no session.");
  string state=Text(root,"state",20);
  if(!SessionStates.Contains(state))throw new InvalidDataException("Obli’s answer has an unknown state.");
  string? challenge=null;
  if(state=="confirming"){challenge=Text(root,"challenge",64);if(!TicketComputerProtocol.Hex(challenge))throw new InvalidDataException("Computer confirmation is invalid.");}
  var checks=List(root,"checks",20).Select(c=>{string tone=Text(c,"tone",10);if(tone is not("ok" or "warning" or "critical"))throw new InvalidDataException();return new FixCheck(tone,Text(c,"text",300));}).ToList();
  var plan=List(root,"plan",12).Select(p=>{string kind=Text(p,"kind",10);if(kind is not("screen" or "fix"))throw new InvalidDataException();
   string key=Text(p,"fix_key",64,false);return new FixPlanStep(Text(p,"text",300),kind,key==""?null:key,Bool(p,"it_allows"));}).ToList();
  FixRefusal? refusal=null;
  if(root.TryGetProperty("refusal",out var r)&&r.ValueKind==JsonValueKind.Object)
   refusal=new(Text(r,"text",500),List(r,"offers",4).Select(o=>new FixOffer(Text(o,"key",40),Text(o,"label",60))).ToList());
  if(state=="refused"&&refusal==null)throw new InvalidDataException("Obli’s answer refuses without saying why.");
  DateTimeOffset? expires=null;
  string expiresText=Text(root,"expires_at",40,false);
  if(expiresText!="")expires=DateTimeOffset.Parse(expiresText,CultureInfo.InvariantCulture,DateTimeStyles.AssumeUniversal);
  var steps=List(root,"steps",80).Select(s=>new FixStepRecord(Text(s,"text",300),Text(s,"result",12))).ToList();
  string outcome=Text(root,"outcome",1000,false);
  return new(id,state,challenge,Text(root,"title",120,state!="confirming"),checks,plan,Int(root,"minutes",0,60,false),Text(root,"note",300,false),refusal,expires,steps,outcome==""?null:outcome,Ticket(root));
 }

 public static FixReply ParseReply(ReadOnlyMemory<byte> bytes,int expectedTurn,int pictureWidth,int pictureHeight)
 {
  if(bytes.Length==0||bytes.Length>65536)throw new InvalidDataException("Obli’s answer is too large.");
  using var json=JsonDocument.Parse(bytes,new JsonDocumentOptions{MaxDepth=5});
  var root=json.RootElement;
  int turn=Int(root,"turn",1,1000);
  if(turn!=expectedTurn)throw new InvalidDataException("Obli’s answer is for another turn.");
  int of=Int(root,"of",0,12,false),step=Int(root,"step",0,12,false);
  if(step>of)throw new InvalidDataException();
  string narration=Text(root,"narration",120,false);
  if(!root.TryGetProperty("action",out var a)||a.ValueKind!=JsonValueKind.Object)throw new InvalidDataException("Obli’s answer has no next step.");
  string type=Text(a,"type",20);
  FixAction action=type switch{
   "click" or "double_click" or "right_click" or "move"=>new(type,Int(a,"x",0,pictureWidth-1),Int(a,"y",0,pictureHeight-1)),
   "scroll"=>new(type,Int(a,"x",0,pictureWidth-1),Int(a,"y",0,pictureHeight-1),Direction:Direction(Text(a,"direction",4)),Amount:Int(a,"amount",1,10)),
   "type"=>new(type,Text:TypedText(Text(a,"text",500))),
   "key"=>new(type,Keys:FixSafety.ValidateKeys(List(a,"keys",3).Select(k=>k.GetString()??"").ToList())),
   "wait"=>new(type,Seconds:Int(a,"seconds",1,10)),
   "fix_running"=>new(type,FixKey:Text(a,"fix_key",64),Label:Text(a,"label",120)),
   "waiting_it"=>new(type,Label:Text(a,"asked_at",40,false)),
   "pause_password"=>new(type,Text:Text(a,"text",300)),
   "done"=>new(type,Fixed:Bool(a,"fixed"),Summary:Text(a,"summary",500),Done:List(a,"steps",20).Select(s=>s.GetString()??"").Where(s=>s.Length is >0 and <=300&&!s.Any(char.IsControl)).ToList(),Pictures:Int(a,"pictures",0,200,false),DeletedOn:Text(a,"deleted_on",40,false)),
   "handed_to_it"=>new(type,Summary:Text(a,"summary",500),Ticket:Ticket(a)),
   "stopped"=>new(type,Summary:Text(a,"summary",500,false)),
   _=>throw new InvalidDataException("Obli asked for something the helper doesn’t do.")};
  return new(turn,step,of,narration,action);
 }
 static string Direction(string value)=>value is "up" or "down"?value:throw new InvalidDataException();
 static string TypedText(string value)=>value.Length>0&&!value.Any(c=>char.IsControl(c)&&c is not('\n' or '\t'))?value:throw new InvalidDataException("Obli asked to type something it can’t.");
}
