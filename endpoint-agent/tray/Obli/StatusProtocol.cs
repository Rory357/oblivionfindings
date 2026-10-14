using System;
using System.Buffers.Binary;
using System.Collections.Generic;
using System.IO;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;

namespace Oblivion.Obli;

public sealed record AgentStatus(int Protocol,string AgentVersion,string ServerOrigin,string Connection,DateTimeOffset? LastAcknowledgedAt,bool AIAvailable,bool TicketsAvailable,bool RemoteAvailable)
{
 public string ConnectionLabel(DateTimeOffset now) => Connection switch
 {
  "connected" when LastAcknowledgedAt.HasValue && now-LastAcknowledgedAt.Value<=TimeSpan.FromMinutes(6) => "Connected to Oblivion",
  "connected" => "Waiting for a recent check-in",
  "connecting" => "Connecting to Oblivion",
  "retrying" => "Connection interrupted — retrying",
  "review_required" => "Your IT team needs to check this connection",
  _ => "Starting computer support"
 };
}
public static class StatusProtocol
{
 public const int MaximumBytes=4096;
 public static AgentStatus Parse(ReadOnlyMemory<byte> bytes)
 {
  if(bytes.Length==0 || bytes.Length>MaximumBytes)throw new InvalidDataException("Invalid status size.");
  using var document=JsonDocument.Parse(bytes,new JsonDocumentOptions{MaxDepth=4,CommentHandling=JsonCommentHandling.Disallow,AllowTrailingCommas=false});
  if(document.RootElement.ValueKind!=JsonValueKind.Object)throw new InvalidDataException("Invalid status.");
  var fields=new Dictionary<string,JsonElement>(StringComparer.Ordinal);
  foreach(var field in document.RootElement.EnumerateObject())
  {
   if(field.Name is not ("protocol" or "agent_version" or "server_origin" or "connection" or "last_acknowledged_at" or "ai_available" or "tickets_available" or "remote_available") || !fields.TryAdd(field.Name,field.Value))throw new InvalidDataException("Unsupported status field.");
  }
  string Text(string key,int maximum){var text=fields[key].GetString()??throw new InvalidDataException();if(text.Length>maximum)throw new InvalidDataException();foreach(char c in text)if(char.IsControl(c))throw new InvalidDataException();return text;}
  var protocol=fields["protocol"].GetInt32();
  var version=Text("agent_version",64);
  var origin=Text("server_origin",2048);
  var connection=Text("connection",32);
  if(protocol!=1 || version.Length==0 || connection is not ("starting" or "connecting" or "connected" or "retrying" or "review_required"))throw new InvalidDataException("Unsupported status protocol.");
  if(origin.Length>0)
  {
   if(!Uri.TryCreate(origin,UriKind.Absolute,out var server)||server.Scheme!="https"||server.UserInfo.Length>0||server.Query.Length>0||server.Fragment.Length>0||server.AbsolutePath!="/")throw new InvalidDataException("Invalid deployment origin.");
  }
  else if(connection!="starting")throw new InvalidDataException("Missing deployment origin.");
  DateTimeOffset? last=null;
  if(fields.TryGetValue("last_acknowledged_at",out var time))
  {
   if(!time.TryGetDateTimeOffset(out var parsed)||parsed>DateTimeOffset.UtcNow.AddMinutes(5))throw new InvalidDataException("Invalid acknowledgement time.");
   last=parsed;
  }
  if(connection=="connected"&&!last.HasValue)throw new InvalidDataException("Missing accepted check-in.");
  return new(protocol,version,origin,connection,last,fields["ai_available"].GetBoolean(),fields["tickets_available"].GetBoolean(),fields["remote_available"].GetBoolean());
 }
 public static async Task<AgentStatus> ReadAsync(Stream stream,CancellationToken cancellation)
 {
  var header=new byte[4];await stream.ReadExactlyAsync(header,cancellation);
  uint length=BinaryPrimitives.ReadUInt32LittleEndian(header);
  if(length==0||length>MaximumBytes)throw new InvalidDataException("Invalid status size.");
  var bytes=new byte[(int)length];await stream.ReadExactlyAsync(bytes,cancellation);
  return Parse(bytes);
 }
}
