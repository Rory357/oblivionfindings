using System;
using System.ComponentModel;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using System.Windows.Media.Animation;
namespace Oblivion.Obli;
public partial class ObliOrb:UserControl
{
 bool motion=true;
 public ObliOrb(){InitializeComponent();Loaded+=(_,_)=>{SystemParameters.StaticPropertyChanged+=SettingsChanged;UpdateMotion();};Unloaded+=(_,_)=>{SystemParameters.StaticPropertyChanged-=SettingsChanged;StopMotion();};IsVisibleChanged+=(_,_)=>UpdateMotion();}
 public void SetMotion(bool enabled){motion=enabled;UpdateMotion();}
 void SettingsChanged(object? sender,PropertyChangedEventArgs args){if(args.PropertyName==nameof(SystemParameters.ClientAreaAnimation))UpdateMotion();}
 void StopMotion()=>((RotateTransform)Orbit.RenderTransform).BeginAnimation(RotateTransform.AngleProperty,null);
 void UpdateMotion()
 {
  StopMotion();
  if(!motion||!IsVisible||!SystemParameters.ClientAreaAnimation)return;
  ((RotateTransform)Orbit.RenderTransform).BeginAnimation(RotateTransform.AngleProperty,new DoubleAnimation(0,360,TimeSpan.FromSeconds(18)){RepeatBehavior=RepeatBehavior.Forever});
 }
}
