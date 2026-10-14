using System;
using System.Collections.Generic;
using System.Globalization;
using System.IO;
using System.Linq;
using System.Text;
using System.Text.Json;
using System.Text.RegularExpressions;

namespace Oblivion.Obli;

public sealed class DiagnosticSnapshot
{
    public const string Prefix = "\n\nTechnical snapshot selected by the user (informational, not remote attestation):\n";
    static readonly string[] GroupNames = {"system","software","services"};
    static readonly Dictionary<string,string> ProductNames = new() {
        ["microsoft_edge"]="Microsoft Edge",["google_chrome"]="Google Chrome",["microsoft_teams"]="Microsoft Teams",["teams_installer"]="Teams machine-wide installer"
    };
    static readonly Dictionary<string,string> ServiceNames = new() {
        ["print_spooler"]="Print spooler",["windows_update"]="Windows Update",["background_transfer"]="Background transfers",
        ["windows_defender"]="Windows Defender",["windows_firewall"]="Windows Firewall"
    };
    readonly JsonElement data;
    public DateTimeOffset ObservedAt {get;}
    public IReadOnlyList<string> Groups => GroupNames.Where(name=>data.TryGetProperty(name,out _)).ToArray();
    public string ProviderText => Prefix+data.GetRawText();
    public override string ToString() => "Selected computer diagnostics";
    DiagnosticSnapshot(JsonElement element, DateTimeOffset observed) { data=element.Clone();ObservedAt=observed; }
    static Dictionary<string,JsonElement> Fields(JsonElement element, params string[] allowed)
    {
        if(element.ValueKind!=JsonValueKind.Object)throw new InvalidDataException("Invalid computer checks.");
        var fields=new Dictionary<string,JsonElement>(StringComparer.Ordinal);
        foreach(var property in element.EnumerateObject())
            if(!allowed.Contains(property.Name)||!fields.TryAdd(property.Name,property.Value))throw new InvalidDataException("Unsupported computer check.");
        return fields;
    }
    static void Exact(JsonElement element,params string[] fields) { if(Fields(element,fields).Count!=fields.Length)throw new InvalidDataException(); }
    static string Text(JsonElement element,string field)=>ObliClient.Text(element,field,80);
    static ulong? Number(JsonElement value,ulong max=9007199254740991) {
        if(value.ValueKind==JsonValueKind.Null)return null;
        if(!value.TryGetUInt64(out ulong number)||number>max)throw new InvalidDataException();
        return number;
    }
    public static DiagnosticSnapshot? ParseEnvelope(ReadOnlyMemory<byte> bytes, DateTimeOffset now,Uri expectedOrigin)
    {
        if(bytes.Length is < 1 or > StatusProtocol.MaximumBytes)throw new InvalidDataException();
        using var document=JsonDocument.Parse(bytes,new JsonDocumentOptions{MaxDepth=8});
        var root=document.RootElement;var fields=Fields(root,"protocol","available","snapshot","server_origin");
        if(root.GetProperty("protocol").GetInt32()!=1)throw new InvalidDataException();
        bool available=root.GetProperty("available").GetBoolean();
        if(!available) { if(fields.Count!=2)throw new InvalidDataException();return null; }
        if(fields.Count!=4||ObliClient.ValidateOrigin(ObliClient.Text(root,"server_origin",2048))!=expectedOrigin)throw new InvalidDataException();
        return Parse(root.GetProperty("snapshot"),now,true);
    }
    internal static DiagnosticSnapshot Parse(JsonElement root,DateTimeOffset now,bool fresh)
    {
        Fields(root,"schema","observed_at","system","software","services");
        if(root.GetProperty("schema").GetInt32()!=1 ||
            !DateTimeOffset.TryParseExact(Text(root,"observed_at"),"yyyy-MM-dd'T'HH:mm:ss'Z'",CultureInfo.InvariantCulture,
                DateTimeStyles.AssumeUniversal|DateTimeStyles.AdjustToUniversal,out var observed) ||
            now-observed>TimeSpan.FromSeconds(fresh?360:86400) || observed-now>TimeSpan.FromSeconds(30))
            throw new InvalidDataException("The computer checks are too old. Request a fresh preview.");
        if(!GroupNames.Any(name=>root.TryGetProperty(name,out _)))throw new InvalidDataException();
        if(root.TryGetProperty("system",out var system)) {
            Exact(system,"windows_version","architecture","memory_total_bytes","memory_available_bytes","uptime_seconds","cpu_percent","disks");
            if(!Regex.IsMatch(Text(system,"windows_version"),@"\A(unknown|[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,8}(\.[0-9]{1,8})?)\z") ||
                Text(system,"architecture") is not ("amd64" or "arm64" or "unknown"))throw new InvalidDataException();
            var total=Number(system.GetProperty("memory_total_bytes"));var available=Number(system.GetProperty("memory_available_bytes"));
            if(total==0 || (available!=null && (total==null || available>total)))throw new InvalidDataException();
            _=Number(system.GetProperty("uptime_seconds"),3155760000);
            var cpu=system.GetProperty("cpu_percent");
            if(cpu.ValueKind!=JsonValueKind.Null && (!cpu.TryGetDouble(out var value)||!double.IsFinite(value)||value<0||value>100))throw new InvalidDataException();
            var disks=system.GetProperty("disks");if(disks.ValueKind!=JsonValueKind.Array||disks.GetArrayLength()>8)throw new InvalidDataException();
            var seen=new HashSet<string>();
            foreach(var disk in disks.EnumerateArray()){
                Exact(disk,"name","total_bytes","free_bytes");var name=Text(disk,"name");
                var size=Number(disk.GetProperty("total_bytes"));var free=Number(disk.GetProperty("free_bytes"));
                if(!Regex.IsMatch(name,@"\A[A-Z]:\z")||!seen.Add(name)||size==null||size==0||free==null||free>size)throw new InvalidDataException();
            }
        }
        foreach(var group in new[]{"software","services"})if(root.TryGetProperty(group,out var items)){
            if(items.ValueKind!=JsonValueKind.Array||items.GetArrayLength()>(group=="software"?4:5))throw new InvalidDataException();
            var seen=new HashSet<string>();
            foreach(var item in items.EnumerateArray()){
                bool software=group=="software";Exact(item,"name",software?"version":"state");
                var name=Text(item,"name");if(!seen.Add(name))throw new InvalidDataException();
                if(software){if(!ProductNames.ContainsKey(name)||!Regex.IsMatch(Text(item,"version"),@"\A[0-9]{1,9}(\.[0-9]{1,9}){1,5}\z"))throw new InvalidDataException();}
                else if(!ServiceNames.ContainsKey(name)||Text(item,"state") is not ("running" or "stopped" or "unknown"))throw new InvalidDataException();
            }
        }
        return new DiagnosticSnapshot(root,observed);
    }
    public bool IsFresh(DateTimeOffset now)=>now-ObservedAt<=TimeSpan.FromMinutes(6)&&ObservedAt-now<=TimeSpan.FromSeconds(30);
    public DiagnosticSnapshot Select(IEnumerable<string> groups)
    {
        var wanted=groups.Distinct().ToArray();
        if(wanted.Length==0||wanted.Any(name=>!Groups.Contains(name)))throw new InvalidDataException();
        var selected=new Dictionary<string,object> {["schema"]=1,["observed_at"]=data.GetProperty("observed_at").GetString()!};
        foreach(var name in GroupNames)if(wanted.Contains(name))selected[name]=data.GetProperty(name);
        return new DiagnosticSnapshot(JsonSerializer.SerializeToElement(selected),ObservedAt);
    }
    public JsonElement ToWire()=>data;
    public string Preview()
    {
        var text=new StringBuilder("Computer check from "+ObservedAt.ToLocalTime().ToString("d MMM yyyy, h:mm:ss tt",CultureInfo.GetCultureInfo("en-NZ"))+".\n");
        string Metric(JsonElement value,string suffix)=>value.ValueKind==JsonValueKind.Null?"Not reported":value.GetRawText()+suffix;
        if(data.TryGetProperty("system",out var system)){
            text.AppendLine("\nSYSTEM HEALTH");
            text.AppendLine("Windows version: "+Text(system,"windows_version"));
            text.AppendLine("Architecture: "+Text(system,"architecture"));
            text.AppendLine("Memory total: "+Metric(system.GetProperty("memory_total_bytes")," bytes"));
            text.AppendLine("Memory available: "+Metric(system.GetProperty("memory_available_bytes")," bytes"));
            text.AppendLine("Uptime: "+Metric(system.GetProperty("uptime_seconds")," seconds"));
            text.AppendLine("CPU use at check: "+Metric(system.GetProperty("cpu_percent"),"%"));
            foreach(var disk in system.GetProperty("disks").EnumerateArray())text.AppendLine("Drive "+Text(disk,"name")+": "+disk.GetProperty("free_bytes")+" bytes free / "+disk.GetProperty("total_bytes")+" total");
            if(system.GetProperty("disks").GetArrayLength()==0)text.AppendLine("Disk sizes: Not reported");
        }
        if(data.TryGetProperty("software",out var software)){
            text.AppendLine("\nAPPROVED SOFTWARE VERSIONS");
            foreach(var item in software.EnumerateArray())text.AppendLine(ProductNames[Text(item,"name")]+": "+Text(item,"version"));
            if(software.GetArrayLength()==0)text.AppendLine("No recognised versions reported.");
            text.AppendLine("Machine-wide entries only; other apps and publishers are excluded.");
        }
        if(data.TryGetProperty("services",out var services)){
            text.AppendLine("\nWINDOWS SERVICE STATES");
            foreach(var item in services.EnumerateArray())text.AppendLine(ServiceNames[Text(item,"name")]+": "+Text(item,"state"));
            if(services.GetArrayLength()==0)text.AppendLine("No recognised services reported.");
            text.AppendLine("A stopped service alone does not mean a fault.");
        }
        return text.ToString().TrimEnd();
    }
}
