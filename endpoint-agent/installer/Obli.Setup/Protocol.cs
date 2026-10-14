using System.Text.Json;
using System.Text.RegularExpressions;
namespace Oblivion.Setup;

public sealed record InstallationInput(string Role,string Package,string ComputerProfile,string CollectorProfile) {
 public IReadOnlyList<string> Arguments(string command) {
  if(command is not ("review" or "install") || Role is not ("computer" or "site" or "both"))throw new InvalidOperationException("Choose an installation type.");
  bool computer=Role is "computer" or "both",site=Role is "site" or "both";
  if(computer!=!string.IsNullOrEmpty(ComputerProfile)||site!=!string.IsNullOrEmpty(CollectorProfile))throw new InvalidOperationException("Choose the profiles needed for this installation.");
  CheckPath(Package,".zip");if(computer)CheckPath(ComputerProfile,".json");if(site)CheckPath(CollectorProfile,".json");
  var args=new List<string>{command,"--package",Package,"--role",Role};
  if(computer){args.Add("--bootstrap");args.Add(ComputerProfile);}if(site){args.Add("--collector-bootstrap");args.Add(CollectorProfile);}return args;
 }
 internal static void CheckPath(string value,string extension){if(value.Length>1024||!Path.IsPathFullyQualified(value)||value.Any(char.IsControl)||!string.Equals(Path.GetExtension(value),extension,StringComparison.OrdinalIgnoreCase))throw new InvalidOperationException("Choose a valid installation file.");}
}
public sealed record InstallationReview(string Role,string Version,long Revision,string Architecture,string Organisation,string Computer,string Site,string Server,string CollectorServer,DateTimeOffset Expires,string Digest) {
 public static InstallationReview Parse(string text,string expectedRole) {
  using var doc=JsonDocument.Parse(text,new JsonDocumentOptions{MaxDepth=3});var root=doc.RootElement;
  var fields=new HashSet<string>{"schema","role","version","revision","architecture","organisation","computer","site","server_url","collector_url","expires_at","review_digest"};
  if(root.ValueKind!=JsonValueKind.Object)throw new InvalidDataException("Setup review is invalid.");
  foreach(var p in root.EnumerateObject())if(!fields.Remove(p.Name))throw new InvalidDataException("Setup review contains unexpected details.");
  if(fields.Count!=0||root.GetProperty("schema").GetInt32()!=1)throw new InvalidDataException("Setup review is incomplete.");
  string Read(string name,int max=300){var value=root.GetProperty(name).GetString()??throw new InvalidDataException();if(value.Length>max||value.Any(c=>char.IsControl(c)||char.GetUnicodeCategory(c)==System.Globalization.UnicodeCategory.Format))throw new InvalidDataException();return value;}
  string role=Read("role"),version=Read("version"),architecture=Read("architecture"),org=Read("organisation"),computer=Read("computer"),site=Read("site"),server=Read("server_url",2048),collector=Read("collector_url",2048),digest=Read("review_digest");
  long revision=root.GetProperty("revision").GetInt64();
  if(role!=expectedRole||role is not ("computer" or "site" or "both")||!Regex.IsMatch(version,@"^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$")||revision<1||revision>9007199254740991||architecture is not ("amd64" or "arm64")||org.Length==0||!Origin(server)||!Regex.IsMatch(digest,"^[a-f0-9]{64}$"))throw new InvalidDataException("Setup review does not match this installation.");
  if((role is "computer" or "both")!=(computer.Length>0)||(role is "site" or "both")!=(site.Length>0)||(role=="computer"?collector!="":!Origin(collector)))throw new InvalidDataException("Setup roles do not match.");
  if(!DateTimeOffset.TryParse(Read("expires_at"),System.Globalization.CultureInfo.InvariantCulture,System.Globalization.DateTimeStyles.None,out var expires)||expires<=DateTimeOffset.UtcNow||expires>DateTimeOffset.UtcNow.AddMinutes(31))throw new InvalidDataException("Installation profile expired. Download a new one.");
  return new(role,version,revision,architecture,org,computer,site,server,collector,expires,digest);
 }
 internal static bool Origin(string value)=>Uri.TryCreate(value,UriKind.Absolute,out var u)&&u.Scheme=="https"&&u.UserInfo==""&&u.AbsolutePath=="/"&&u.Query==""&&u.Fragment==""&&!value.EndsWith('/')&&!value.Contains('\\')&&!value.Any(char.IsWhiteSpace);
 public bool IsCurrent=>Expires>DateTimeOffset.UtcNow;
 public IReadOnlyList<string> InstallArguments(InstallationInput input) {
  if(!IsCurrent||input.Role!=Role)throw new InvalidOperationException("Review the installation again.");
  var args=input.Arguments("install").ToList();args.AddRange(new[]{"--confirm-server",Server,"--review-digest",Digest,"--control-stdin"});
  if(CollectorServer.Length>0)args.AddRange(new[]{"--confirm-collector-server",CollectorServer});return args;
 }
}
public interface IInstallerEngine {
 Task<InstallationReview> ReviewAsync(InstallationInput input,CancellationToken cancellation);
 Task InstallAsync(InstallationInput input,InstallationReview review,CancellationToken cancellation);
}
