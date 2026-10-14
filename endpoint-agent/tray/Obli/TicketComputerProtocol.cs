using System.Net;
using System.Buffers.Binary;
using System;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.IO;
using System.Linq;
using System.Globalization;
namespace Oblivion.Obli;
internal static class TicketComputerProtocol
{
 internal static bool Hex(string value)=>value.Length==64&&value.All(c=>c is >= '0' and <= '9' or >= 'a' and <= 'f');
 internal static string OriginHash(Uri origin)
 {
  var uri=ObliClient.ValidateOrigin(origin.AbsoluteUri);
  string host=uri.IdnHost.ToLowerInvariant();
  if(uri.HostNameType==UriHostNameType.IPv6)
  {
   if(!IPAddress.TryParse(host.Trim('[',']'),out var address)||address.AddressFamily!=System.Net.Sockets.AddressFamily.InterNetworkV6)throw new InvalidDataException("The configured Oblivion server is invalid.");
   byte[] bytes=address.GetAddressBytes();
   host="["+string.Join(":",Enumerable.Range(0,8).Select(i=>BinaryPrimitives.ReadUInt16BigEndian(bytes.AsSpan(i*2,2)).ToString("x4",CultureInfo.InvariantCulture)))+"]";
  }
  string canonical="https://"+host+(uri.IsDefaultPort?"":":"+uri.Port.ToString(CultureInfo.InvariantCulture));
  return Convert.ToHexStringLower(SHA256.HashData(Encoding.UTF8.GetBytes(canonical)));
 }
 internal static object Request(string challenge,Uri origin)
 {
  if(!Hex(challenge))throw new ArgumentException("Computer confirmation is invalid.");
  return new{version=1,operation="confirm_ticket_computer",challenge,expected_origin_hash=OriginHash(origin)};
 }
 internal static bool Parse(byte[] bytes)
 {
  using var json=JsonDocument.Parse(bytes,new JsonDocumentOptions{MaxDepth=2});var root=json.RootElement;
  if(root.ValueKind!=JsonValueKind.Object||root.EnumerateObject().Count()!=2||root.EnumerateObject().Any(p=>p.Name is not("protocol" or "status"))||root.EnumerateObject().Select(p=>p.Name).Distinct().Count()!=2||root.GetProperty("protocol").GetInt32()!=1)throw new InvalidDataException("Computer confirmation is unconfirmed.");
  return root.GetProperty("status").GetString() switch {"confirmed"=>true,"unavailable"=>false,_=>throw new InvalidDataException("Computer confirmation is unconfirmed.")};
 }
}

