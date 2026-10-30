using System.Windows;
namespace Oblivion.Setup;
public partial class App:Application {
 protected override void OnStartup(StartupEventArgs e) {base.OnStartup(e);if(e.Args.Length!=0){Shutdown(2);return;}new SetupWindow(new InstallerEngine()).Show();}
}