using System;
using System.Runtime.InteropServices;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Interop;
using System.Windows.Media;

namespace Oblivion.Obli;

/// <summary>
/// S&D Phase I (windows.md I): the bar at the top of the screen while Obli works. It says what Obli is
/// doing and which step it's on, with Stop (Esc). It never takes focus, stays on top, and is left out
/// of screen captures, so Obli's pictures show the person's screen, not the bar.
/// </summary>
public sealed class FixBar:Window
{
 readonly TextBlock what=new(){VerticalAlignment=VerticalAlignment.Center,TextTrimming=TextTrimming.CharacterEllipsis,FontSize=14};
 public event Action? StopClicked;
 public IntPtr Handle{get;private set;}

 public FixBar()
 {
  WindowStyle=WindowStyle.None;ResizeMode=ResizeMode.NoResize;Topmost=true;ShowInTaskbar=false;ShowActivated=false;
  AllowsTransparency=false;SizeToContent=SizeToContent.Manual;Width=760;Height=48;Title="Obli is fixing";
  var area=SystemParameters.WorkArea;Left=area.Left+(area.Width-Width)/2;Top=area.Top+8;
  var stop=new Button{Content="Stop (Esc)",Padding=new Thickness(14,4,14,4),Margin=new Thickness(12,0,0,0),VerticalAlignment=VerticalAlignment.Center};
  stop.Click+=(_,_)=>StopClicked?.Invoke();
  var grid=new Grid{Margin=new Thickness(16,0,8,0)};
  grid.ColumnDefinitions.Add(new ColumnDefinition{Width=new GridLength(1,GridUnitType.Star)});
  grid.ColumnDefinitions.Add(new ColumnDefinition{Width=GridLength.Auto});
  grid.Children.Add(what);Grid.SetColumn(stop,1);grid.Children.Add(stop);
  Content=new Border{Child=grid,BorderThickness=new Thickness(1),CornerRadius=new CornerRadius(10)};
  Loaded+=(_,_)=>{
   if(Application.Current?.TryFindResource("BackgroundBrush") is Brush background)Background=background;
   if(Application.Current?.TryFindResource("ForegroundBrush") is Brush foreground)what.Foreground=foreground;
   if(Application.Current?.TryFindResource("BorderBrush") is Brush border)((Border)Content).BorderBrush=border;
  };
  SourceInitialized+=(_,_)=>{
   Handle=new WindowInteropHelper(this).Handle;
   // Never takes focus from the person's app, and stays out of screen pictures (WDA_EXCLUDEFROMCAPTURE).
   SetWindowLongPtr(Handle,-20,new IntPtr(GetWindowLongPtr(Handle,-20).ToInt64()|0x08000000L|0x00000080L));
   _=SetWindowDisplayAffinity(Handle,0x11);
  };
 }

 /// <summary>"Obli is fixing: Printer won’t print · step 1 of 3 · cancelling stuck jobs".</summary>
 public void Show(string title,int step,int of,string narration)
 {
  string progress=of>0?$" · step {Math.Max(1,step)} of {of}":"";
  string doing=narration.Length>0?" · "+narration:"";
  what.Text="Obli is fixing: "+title+progress+doing;
  if(!IsVisible)Show();
 }

 [DllImport("user32.dll")]static extern IntPtr GetWindowLongPtr(IntPtr window,int index);
 [DllImport("user32.dll")]static extern IntPtr SetWindowLongPtr(IntPtr window,int index,IntPtr value);
 [DllImport("user32.dll")][return:MarshalAs(UnmanagedType.Bool)]static extern bool SetWindowDisplayAffinity(IntPtr window,uint affinity);
}
