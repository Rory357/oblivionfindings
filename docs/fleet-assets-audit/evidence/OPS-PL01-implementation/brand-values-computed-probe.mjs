import {readFileSync} from 'node:fs';
import assert from 'node:assert/strict';
import {JSDOM} from 'jsdom';
const cases=JSON.parse(readFileSync(new URL('./brand-values-main-probe.json',import.meta.url),'utf8').replace(/^\uFEFF/,''));
const results=cases.map(probe=>{
    const dom=new JSDOM('<!doctype html><html><head><style>html:root{--status-warning:orange;--status-warning-foreground:black}</style>'+probe.rendered+'</head><body></body></html>');
    const css=dom.window.getComputedStyle(dom.window.document.documentElement);
    const result={case:probe.case,warning:css.getPropertyValue('--status-warning').trim(),warningForeground:css.getPropertyValue('--status-warning-foreground').trim(),primary:css.getPropertyValue('--primary').trim()};
    assert.equal(result.warning,'orange');
    assert.equal(result.warningForeground,'black');
    assert.equal(result.primary,probe.case==='ordinary'?'oklch(0.5 0.12 190)':'');
    dom.window.close();
    return result;
});
console.log(JSON.stringify(results,null,2));
