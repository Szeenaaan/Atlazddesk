// UI-only entity fixtures; requests are intercepted and no business records are written.
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const {response}=require('./ui-pages.cjs');
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),os=require('node:os');
const out=process.env.UI_REPORT_DIR||path.join(os.tmpdir(),'supportdesk-ui-review');fs.mkdirSync(out,{recursive:true});
(async()=>{const browser=await chromium.launch({headless:true,args:['--no-sandbox']});const results=[];
for(const theme of ['dark','light'])for(const width of [1440,390]){
 const ctx=await browser.newContext({viewport:{width,height:900},reducedMotion:'reduce'});await ctx.addInitScript(t=>localStorage.setItem('supportdesk-theme',t),theme);
 const page=await ctx.newPage();await page.route('**/api/maintenance-status', route => route.fulfill({json:{maintenance:false}}));page.setDefaultTimeout(6000);const errors=[];page.on('pageerror',e=>errors.push(e.message));const user={id:2,name:'UI review',email:'test@example.test',role:'admin',customer_id:null};const queries=[];
 await page.route('**/api/v2/**',async route=>{const url=new URL(route.request().url());queries.push(url);const data=response(url.pathname.replace('/api/v2',''),user);await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify(data)});});
 const go=async name=>{if(width<800)await page.locator('.mobile-menu').click();await page.locator('.sidebar .nav-item').filter({hasText:new RegExp('^'+name+'$')}).click();await page.waitForTimeout(500);};
 const note=(name,extra={})=>results.push({theme,width,name,errors:[...errors],...extra});
 try{
  await page.goto(process.env.UI_BASE_URL||'http://127.0.0.1:4173');await page.waitForTimeout(600);
  for(const name of ['Customers','Assets','Contracts','Technicians','User Management']){
   await go(name);
   if(name==='Assets'){await page.getByText('Manage categories',{exact:true}).click();await page.getByRole('textbox',{name:'Category name',exact:true}).fill('UI-only category');note('Expanded category form');}
   await page.locator('tbody .record-link').first().click();await page.waitForTimeout(250);
   const drawer=page.locator('dialog[open]').last();assert.equal(await drawer.evaluate(e=>e.scrollWidth>e.clientWidth+1),false);assert.ok(Math.abs((await drawer.boundingBox()).y)<=1,'Drawer must start at viewport top');
   await page.screenshot({path:path.join(out,`${theme}-${width}-${name.replaceAll(' ','-')}-details.png`),fullPage:false});note(name+' details drawer');await page.keyboard.press('Escape');await page.waitForTimeout(250);
  }
  await go('Customers');await page.getByRole('button',{name:'+ Register client',exact:true}).click();await page.waitForTimeout(200);const registration=page.getByRole('dialog',{name:'Register client',exact:true});assert.equal(await registration.evaluate(e=>e.scrollWidth>e.clientWidth+1),false);await page.keyboard.press('Escape');note('Registration dialog');
  await go('Technicians');await page.getByRole('button',{name:'+ Add technician',exact:true}).click();assert.ok(await page.getByRole('dialog').count());await page.keyboard.press('Escape');note('Technician account dialog');
  await go('Overview');await page.getByRole('button',{name:'+ New ticket',exact:true}).click();const ticket=page.getByRole('dialog',{name:'New ticket',exact:true});
  const choose=async(name,search,match)=>{await ticket.getByRole('combobox',{name,exact:true}).fill(search);await page.getByRole('listbox').getByRole('option').filter({hasText:match}).first().click();};
  await choose('Customer','UI test','UI test customer');await choose('Category','Hardware','Hardware');await page.waitForTimeout(400);await choose('Asset','Assigned','Assigned workstation');
  assert.ok(queries.some(url=>url.pathname.endsWith('/directory/assets')&&url.searchParams.get('customer_id')==='1'&&url.searchParams.get('category_id')==='1'));
  assert.equal(await ticket.evaluate(e=>e.scrollWidth>e.clientWidth+1),false);note('Ticket form preserves customer/category asset filtering');
 }catch(e){note('FAILED',{failure:e.message});}
 await ctx.close();}
await browser.close();fs.writeFileSync(path.join(out,'records.json'),JSON.stringify(results,null,2));const failures=results.filter(r=>r.failure||r.errors.length);console.log(JSON.stringify({checks:results.length,failures},null,2));if(failures.length)process.exitCode=1;})();
