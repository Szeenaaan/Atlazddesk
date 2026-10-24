// UI fixtures only. No real authentication, SMTP or business-record writes.
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const {response} = require('./ui-pages.cjs');
const fs = require('node:fs');
(async () => {
 const browser = await chromium.launch({executablePath: process.env.CHROMIUM_PATH, args:['--no-sandbox']});
 const results=[];
 try {
  for(const theme of ['light','dark']) for(const width of [390,1440]) for(const role of ['admin','customer_admin','customer_user','technician','super_admin']) {
   const context=await browser.newContext({viewport:{width,height:1000},ignoreHTTPSErrors:true,reducedMotion:'reduce'});
   await context.addInitScript(theme=>localStorage.setItem('atlazddesk-theme',theme),theme);
   const page=await context.newPage();page.setDefaultTimeout(7000);
   const errors=[];page.on('pageerror',e=>errors.push(e.message));
   await page.route('**/api/maintenance-status',r=>r.fulfill({json:{maintenance:false}}));
   await page.route('**/api/v2/**',r=>r.fulfill({json:response(new URL(r.request().url()).pathname.replace('/api/v2',''),{id:2,name:'UI review account',email:'review@example.test',role,customer_id:role.startsWith('customer')?1:null})}));
   await page.goto(process.env.UI_BASE_URL || 'https://localhost:8443');await page.locator('.sidebar').waitFor();
   const names=await page.locator('.sidebar .nav-item').allTextContents();
   for(const name of names) {
    if(width<800 && !(await page.locator('.sidebar').evaluate(e=>e.classList.contains('shown')))) await page.locator('.mobile-menu').click();
    await page.locator('.sidebar .nav-item').filter({hasText:new RegExp('^'+name.trim()+'$')}).click();
    await page.waitForTimeout(140);
    const issues=await page.evaluate(()=>{
     const issues=[];const visible=e=>e.getClientRects().length && getComputedStyle(e).visibility!=='hidden';
     const inspect=(selector,check)=>document.querySelectorAll(selector).forEach(e=>{if(visible(e)){const issue=check(e,getComputedStyle(e),e.getBoundingClientRect());if(issue)issues.push({element:selector,text:e.textContent.trim().slice(0,45),issue});}});
     if(document.documentElement.scrollWidth>innerWidth+1)issues.push({issue:'Page overflow'});
     inspect('.page-header h1',(e,s)=>s.fontSize!=='24px'||s.lineHeight!=='32px'?'Page title '+s.fontSize+'/'+s.lineHeight:null);
     inspect('.ui-button',(e,s,r)=>r.height<43.5?'Button height '+r.height:s.fontSize!=='16px'||s.lineHeight!=='24px'?'Button typography '+s.fontSize+'/'+s.lineHeight:null);
     inspect('.ui-table th',(e,s)=>s.fontSize!=='14px'||s.lineHeight!=='20px'||s.textTransform!=='none'||s.borderTopLeftRadius!=='0px'?'Table header contract':null);
     inspect('.grid-sort',(e,s,r)=>r.height<43.5?'Sort target '+r.height:null);
     inspect('.page-header p,.ui-state,.ui-field-label,small,.ui-notice',(e,s)=>parseFloat(s.fontSize)<14?'Small text '+s.fontSize:null);
     inspect('.panel>h3,.panel-head h3',(e,s)=>s.fontSize!=='20px'||s.lineHeight!=='28px'?'Section title '+s.fontSize+'/'+s.lineHeight:null);
     return issues;
    });
    results.push({theme,width,role,page:name.trim(),issues,errors:[...errors]});
    if((role==='admin' && width===390 && theme==='dark' && name.trim()==='Overview') || (role==='super_admin' && width===1440 && theme==='light' && name.trim()==='System Support')) {
      await page.screenshot({path:`/tmp/supportdesk-ui-${theme}-${role}-${width}.png`,fullPage:true});
    }
   }
   await context.close();
  }
 } finally {await browser.close();}
 const failures=results.filter(r=>r.issues.length||r.errors.length);
 fs.writeFileSync(process.env.UI_REPORT_PATH || '/tmp/supportdesk-ui-consistency.json',JSON.stringify(results,null,2));
 console.log(JSON.stringify({screens:results.length,failures},null,2));if(failures.length)process.exitCode=1;
})().catch(e=>{console.error(e);process.exit(1);});
