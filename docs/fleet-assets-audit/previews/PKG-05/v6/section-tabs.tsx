import React from 'react';
import {TierTwoTabs,type GroupedProfileNavTab} from '@/components/page/grouped-profile-nav';

// Use the app's Rule 2 component unchanged: bare row, positional tones,
// neutral inactive chips, keyboard navigation, and no enclosing card.
export function SectionTabs({tabs,value,onChange,label,id}:{tabs:GroupedProfileNavTab[];value:string;onChange:(key:string)=>void;label:string;id:string}){
 return <TierTwoTabs tabs={tabs} activeTab={value} onTab={onChange} ariaLabel={label} testIdPrefix={id} renderLink={(tab,className,inner,accessibility)=><a href={tab.href} className={className} {...accessibility}>{inner}</a>}/>;
}
