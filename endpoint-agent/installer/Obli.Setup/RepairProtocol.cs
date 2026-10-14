using System.Globalization;
using System.Text.Json;
using System.Text.RegularExpressions;
namespace Oblivion.Setup;

public sealed record RepairInput(string Package) {
 public IReadOnlyList<string> ReviewArguments(){InstallationInput.CheckPath(Package,".zip");return new[]{"review-repair","--package",Package};}
}
public sealed record RepairReview(string Role,string Version,long Revision,string Architecture,string Computer,string Server,string CollectorServer,string AgentId,string CollectorId,long SiteId,string Attempt,string Manifest,string Stage,DateTimeOffset Expires,string Digest) {
 public bool IsCurrent=>Expires>DateTimeOffset.UtcNow;
 static string Read(JsonElement root,string key,int max=300){
  string value=root.GetProperty(key).GetString()??throw new InvalidDataException("Repair review is invalid.");
  if(value.Length>max||value.Any(c=>char.IsControl(c)||char.GetUnicodeCategory(c)==UnicodeCategory.Format))throw new InvalidDataException("Repair review is invalid.");
  return value;
 }
 static void Fields(JsonElement root,params string[] names){
  if(root.ValueKind!=JsonValueKind.Object)throw new InvalidDataException("Repair details are invalid.");
  var expected=new HashSet<string>(names);
  foreach(var field in root.EnumerateObject())if(!expected.Remove(field.Name))throw new InvalidDataException("Repair details are unexpected.");
  if(expected.Count!=0)throw new InvalidDataException("Repair details are incomplete.");
 }
 static bool Hex(string value,int length)=>Regex.IsMatch(value,"^[a-f0-9]{"+length+"}$");
 static bool Id(string value)=>Regex.IsMatch(value,"^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$");
 public static RepairReview Parse(string text){
  using var doc=JsonDocument.Parse(text,new JsonDocumentOptions{MaxDepth=3});var r=doc.RootElement;
  Fields(r,"schema","action","role","version","revision","architecture","computer","server_url","collector_url","agent_uuid","collector_uuid","site_id","installation_attempt","manifest_sha256","stage","expires_at","review_digest");
  if(r.GetProperty("schema").GetInt32()!=1||Read(r,"action")!="repair_services")throw new InvalidDataException("Unsupported repair.");
  string role=Read(r,"role"),version=Read(r,"version"),architecture=Read(r,"architecture"),computer=Read(r,"computer"),server=Read(r,"server_url",2048),collector=Read(r,"collector_url",2048),agent=Read(r,"agent_uuid"),collectorId=Read(r,"collector_uuid"),attempt=Read(r,"installation_attempt"),manifest=Read(r,"manifest_sha256"),stage=Read(r,"stage"),digest=Read(r,"review_digest");
  long revision=r.GetProperty("revision").GetInt64(),site=r.GetProperty("site_id").GetInt64();
  bool hasComputer=role is "computer" or "both",hasSite=role is "site" or "both";
  if(role is not ("computer" or "site" or "both")||!Regex.IsMatch(version,@"^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$")||revision<1||revision>9007199254740991||architecture is not ("amd64" or "arm64")||computer.Length==0||!InstallationReview.Origin(server)||!Hex(attempt,32)||!Hex(manifest,64)||!Hex(digest,64)||!Regex.IsMatch(stage,"^r-"+revision.ToString(CultureInfo.InvariantCulture)+"-[a-f0-9]{32}$"))throw new InvalidDataException("Repair does not match an installed release.");
  if(hasComputer?!Id(agent):agent!="" )throw new InvalidDataException("Computer identity is invalid.");
  if(hasSite?(!Id(collectorId)||site<1||site>999999999999999||!InstallationReview.Origin(collector)):(collectorId!=""||site!=0||collector!=""))throw new InvalidDataException("Site identity is invalid.");
  if(!DateTimeOffset.TryParse(Read(r,"expires_at"),CultureInfo.InvariantCulture,DateTimeStyles.None,out var expires)||expires<=DateTimeOffset.UtcNow||expires>DateTimeOffset.UtcNow.AddMinutes(11))throw new InvalidDataException("The repair review expired. Review the installed connection again.");
  return new(role,version,revision,architecture,computer,server,collector,agent,collectorId,site,attempt,manifest,stage,expires,digest);
 }
 public IReadOnlyList<string> RepairArguments(RepairInput input){
  _=input.ReviewArguments();if(!IsCurrent)throw new InvalidOperationException("Review the installed connection again.");
  var args=new List<string>{"repair-services","--package",input.Package,"--role",Role,"--confirm-server",Server,"--confirm-service-repair","--review-digest",Digest,"--review-expires",Expires.ToUniversalTime().ToString("O",CultureInfo.InvariantCulture),"--control-stdin"};
  if(CollectorServer!="")args.AddRange(new[]{"--confirm-collector-server",CollectorServer});return args;
 }
 public void CheckResult(string text){
  using var doc=JsonDocument.Parse(text,new JsonDocumentOptions{MaxDepth=3});var r=doc.RootElement;
  Fields(r,"schema","repair_id","action","installation_attempt","manifest_sha256","stage","role","revision","status","step","started_at","updated_at");
  if(r.GetProperty("schema").GetInt32()!=1||Read(r,"action")!="repair_services"||Read(r,"status")!="local_services_ready"||Read(r,"step")!="complete"||!Hex(Read(r,"repair_id"),32)||Read(r,"installation_attempt")!=Attempt||Read(r,"manifest_sha256")!=Manifest||Read(r,"stage")!=Stage||Read(r,"role")!=Role||r.GetProperty("revision").GetInt64()!=Revision||
   !DateTimeOffset.TryParse(Read(r,"started_at"),CultureInfo.InvariantCulture,DateTimeStyles.None,out var started)||!DateTimeOffset.TryParse(Read(r,"updated_at"),CultureInfo.InvariantCulture,DateTimeStyles.None,out var updated)||updated<started)throw new InvalidDataException("The service repair could not be confirmed. Ask your IT team to review the repair record.");
 }
}
public interface IServiceRepairEngine{
 Task<RepairReview> ReviewRepairAsync(RepairInput input,CancellationToken cancellation);
 Task RepairAsync(RepairInput input,RepairReview review,CancellationToken cancellation);
}
public sealed partial class InstallerEngine {
 public async Task<RepairReview> ReviewRepairAsync(RepairInput input,CancellationToken cancellation)=>RepairReview.Parse(await RunAsync(input.ReviewArguments(),false,cancellation,true));
 public async Task RepairAsync(RepairInput input,RepairReview review,CancellationToken cancellation)=>review.CheckResult(await RunAsync(review.RepairArguments(input),true,cancellation,true));
}
