import {test} from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {createRequire} from 'node:module';
import {buildSync} from 'esbuild';
const root=path.resolve(import.meta.dirname,'../src');
test('theme has one stylesheet entry, explicit cascade layers and no important overrides',()=>{
  const entry=fs.readFileSync(path.join(root,'main.tsx'),'utf8');
  assert.deepEqual([...entry.matchAll(/import ['"]([^'"]+\.css)['"]/g)].map(m=>m[1]),['./styles/index.css']);
  for(const filename of ['tokens.css','screens.css','components.css']) assert.doesNotMatch(fs.readFileSync(path.join(root,'styles',filename),'utf8'),/!important/);
  assert.match(fs.readFileSync(path.join(root,'styles/index.css'),'utf8'),/@layer tokens, screens, components/);
});
test('shared primitives preserve native form attributes and semantic variants',()=>{
  const compiled=buildSync({stdin:{contents:`
    import React from 'react';import {renderToStaticMarkup} from 'react-dom/server';
    import {Button,Input,Textarea,Checkbox,Switch,FileUpload,UIState,Field,Tabs} from './components/ui';
    export const html=renderToStaticMarkup(<><Button type="submit" disabled variant="danger">Delete</Button><Field htmlFor="contact">Email<Input id="contact" type="email" name="contact" required/></Field><Textarea rows={4} name="notes"/><Checkbox defaultChecked aria-label="Covered"/><Switch aria-label="Enabled"/><FileUpload accept=".pdf"/><UIState tone="error">Invalid input</UIState><Tabs id="ticket-1" label="Ticket sections" items={[["summary","Summary"],["attachments","Attachments"]]} value="attachments" onChange={()=>{}}/></>);
  `,resolveDir:root,loader:'tsx'},write:false,bundle:true,platform:'node',format:'cjs'}).outputFiles[0].text;
  const module={exports:{}};
  new Function('require','module','exports',compiled)(createRequire(import.meta.url),module,module.exports);
  const html=module.exports.html;
  assert.match(html,/type="submit"/);assert.match(html,/disabled=""/);assert.match(html,/btn-danger/);
  assert.match(html,/type="email"/);assert.match(html,/name="contact"/);assert.match(html,/required=""/);assert.match(html,/ui-required/);assert.match(html,/for="contact"/);
  assert.match(html,/rows="4"/);assert.match(html,/type="checkbox"/);assert.match(html,/role="switch"/);
  assert.match(html,/type="file"/);assert.match(html,/accept=".pdf"/);assert.match(html,/role="alert"/);
  assert.match(html,/id="ticket-1-attachments-tab" aria-controls="ticket-1-attachments-panel" aria-selected="true" tabindex="0"/);
  assert.match(html,/id="ticket-1-summary-tab" aria-controls="ticket-1-summary-panel" aria-selected="false" tabindex="-1"/);
});
test('password inputs have an accessible reveal button without nesting it in the label',()=>{
  const compiled=buildSync({stdin:{contents:`
    import React from 'react';import {renderToStaticMarkup} from 'react-dom/server';
    import {Field,Input} from './components/ui';
    export const html=renderToStaticMarkup(<Field title="Current password">Current password<Input type="password" name="password" required autoComplete="current-password" minLength={8}/></Field>);
  `,resolveDir:root,loader:'tsx'},write:false,bundle:true,platform:'node',format:'cjs'}).outputFiles[0].text;
  const module={exports:{}};
  new Function('require','module','exports',compiled)(createRequire(import.meta.url),module,module.exports);
  const html=module.exports.html;
  assert.match(html,/<label[^>]*title="Current password"[^>]*for="([^"]+)"[^>]*>Current password/);
  const inputId=html.match(/<input[^>]*id="([^"]+)"/i)?.[1];
  assert.ok(inputId);
  assert.match(html,new RegExp(`for="${inputId}"`));
  const input=html.match(/<input[^>]*>/i)?.[0] || '';
  for(const attribute of ['type="password"','name="password"','required=""','autoComplete="current-password"','minLength="8"']) assert.ok(input.includes(attribute),attribute);
  const button=html.match(/<button[^>]*>/i)?.[0] || '';
  for(const attribute of ['type="button"','aria-label="Show password"','aria-pressed="false"']) assert.ok(button.includes(attribute),attribute);
  assert.ok(html.indexOf('</label>')<html.indexOf('<button'));
});

test('refresh control uses an icon with an accessible action name',()=>{
  const compiled=buildSync({stdin:{contents:`
    import React from 'react';import {renderToStaticMarkup} from 'react-dom/server';
    import {RefreshButton} from './components/ui';
    export const html=renderToStaticMarkup(<RefreshButton label="Refresh escalations" disabled/>);
  `,resolveDir:root,loader:'tsx'},write:false,bundle:true,platform:'node',format:'cjs'}).outputFiles[0].text;
  const module={exports:{}};
  new Function('require','module','exports',compiled)(createRequire(import.meta.url),module,module.exports);
  const html=module.exports.html;
  assert.match(html,/aria-label="Refresh escalations"/);
  assert.match(html,/type="button"/);
  assert.match(html,/disabled=""/);
  assert.match(html,/ui-refresh-button/);
  assert.doesNotMatch(html,/>Refresh escalations</);
});

test('shared light-theme text and action colors meet normal text contrast',()=>{
  const css=fs.readFileSync(path.join(root,'styles/tokens.css'),'utf8');
  const palette=Object.fromEntries([...css.matchAll(/(--palette-[\w-]+):\s*(#[\da-f]{6});/gi)].map(m=>[m[1],m[2]]));
  const luminance=hex=>[0,2,4].map(index=>parseInt(hex.slice(1+index,3+index),16)/255).map(c=>c<=.04045?c/12.92:((c+.055)/1.055)**2.4).reduce((sum,c,index)=>sum+c*[.2126,.7152,.0722][index],0);
  for(const [foreground,background] of [['muted','white'],['subtle','white'],['white','brand'],['white','brand-bright'],['error','error-soft'],['info','info-soft'],['white','error'],['success-text','white'],['warning-text','white'],['dark-info','dark-surface'],['critical','dark-surface'],['success','dark-surface']]){
    const a=luminance(palette['--palette-'+foreground]),b=luminance(palette['--palette-'+background]);
    assert.ok((Math.max(a,b)+.05)/(Math.min(a,b)+.05)>=4.5,`${foreground} on ${background}`);
  }
});
