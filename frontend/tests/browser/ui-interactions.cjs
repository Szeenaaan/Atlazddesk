// UI-only fixtures. All API traffic is intercepted; no real credentials or business writes.
const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'playwright'),assert=require('node:assert/strict'),fs=require('fs');
const {response}=require('./ui-pages.cjs');const out=(process.env.UI_REPORT_DIR || require('node:path').join(require('node:os').tmpdir(),'supportdesk-ui-review'));fs.mkdirSync(out,{recursive:true});
(async()=>{const browser=await chromium.launch({headless:true,args:['--no-sandbox']});const results=[];
for(const theme of ['dark','light'])for(const width of [1440,390]){
 const ctx=await browser.newContext({viewport:{width,height:900},reducedMotion:'reduce'});await ctx.addInitScript(t=>localStorage.setItem('supportdesk-theme',t),theme);
 const page=await ctx.newPage();await page.route('**/api/maintenance-status', route => route.fulfill({json:{maintenance:false}}));page.setDefaultTimeout(6000);const errors=[];page.on('pageerror',e=>errors.push(e.message));let role='admin',post=null,mode='normal',login=false;
 await page.route('**/api/v2/**',async route=>{const req=route.request(),path=new URL(req.url()).pathname.replace('/api/v2','');const user={id:2,name:'UI test account',email:'test@example.test',role,customer_id:role.startsWith('customer')?1:null};
  if(path==='/auth/me'&&login)return route.fulfill({status:401,contentType:'application/json',body:'{}'});
  if(path==='/auth/login')return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({status:'mfa_required',methods:['totp','recovery'],required_methods:['totp'],remaining_methods:['totp']})});
  if(req.method()!=='GET'){post=req.postDataJSON();return route.fulfill({status:422,contentType:'application/json',body:JSON.stringify({message:'Validation test',errors:{contract_number:['Contract number is already in use.']}})});}
  if(mode==='error'&&path==='/directory/customers')return route.fulfill({status:503,contentType:'application/json',body:'{"message":"Temporarily unavailable"}'});
  if(mode==='loading'&&path==='/directory/customers')await new Promise(r=>setTimeout(r,1200));
  if(mode==='empty'&&path==='/directory/customers')return route.fulfill({status:200,contentType:'application/json',body:'{"data":[],"total":0,"last_page":1}'});
  await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify(response(path,user))});
 });
 await page.route('**/sanctum/csrf-cookie',r=>r.fulfill({status:204,body:''}));
 const go=async name=>{if(width<800)await page.locator('.mobile-menu').click();await page.locator('.sidebar .nav-item').filter({hasText:new RegExp('^'+name+'$')}).click();await page.waitForTimeout(450);};
 const record=(name,extra={})=>results.push({theme,width,name,errors:[...errors],...extra});
 try{
  await page.goto((process.env.UI_BASE_URL || 'http://127.0.0.1:4173'));await page.waitForTimeout(600);
  await go('Contracts');await page.getByRole('button',{name:'Add contract',exact:true}).click();await page.waitForTimeout(300);
  const dialog=page.getByRole('dialog',{name:'Add contract',exact:true});
  const choose=async(label,term,option)=>{const input=dialog.getByLabel(label,{exact:true});await input.fill(term);const list=page.getByRole('listbox');await list.getByRole('option',{name:option,exact:true}).click();assert.equal(await input.inputValue(),option);};
  await dialog.getByLabel('Contract number').fill('TEST-UI');
  await choose('Customer','UI test','UI test customer');await choose('Contract type','Annual','Annual maintenance');
  await dialog.getByLabel('Start date').fill('2026-01-01');await dialog.getByLabel('Expiry date').fill('2027-01-01');
  await choose('Currency','AED','AED — United Arab Emirates Dirham');
  await dialog.getByRole('button',{name:'Save contract',exact:true}).click();await page.waitForTimeout(300);
  assert.equal(post.customer_id,1);assert.equal(post.currency,'AED');assert.equal(post.contract_type,'Annual maintenance');
  assert.ok(await dialog.getByRole('alert').count());
  record('Contract searchable selections, native validation and API payload preserved');
  assert.equal(await dialog.evaluate(e=>e.scrollWidth>e.clientWidth+1),false);
  await dialog.getByLabel('Currency',{exact:true}).fill('INR');
  const box=await page.getByRole('listbox').boundingBox();assert.ok(box.x>=0&&box.x+box.width<=width+1&&box.y>=0&&box.y+box.height<=900+1);record('Drawer dropdown visible within viewport');
  await page.keyboard.press('Escape');assert.ok(await dialog.count());await page.keyboard.press('Escape');await page.waitForTimeout(250);assert.equal(await page.locator('dialog[open]').count(),0);record('Escape closes menu before drawer');
  await page.keyboard.press('Control+k');await page.getByRole('combobox').count().catch(()=>0);
  assert.ok(await page.getByRole('dialog',{name:'Quick navigation'}).count());await page.keyboard.press('Tab');assert.ok(await page.locator('dialog').evaluate(e=>e.contains(document.activeElement)));await page.keyboard.press('Escape');record('Quick navigation focus containment');
  await page.locator('tbody tr').first().click();await page.waitForTimeout(250);
  const details=page.getByRole('dialog',{name:'Contract details',exact:true});
  await details.getByRole('button',{name:'Documents',exact:true}).click();await page.waitForTimeout(250);
  const docs=page.locator('dialog.contract-documents');
  assert.equal(await docs.evaluate(e=>e.scrollWidth>e.clientWidth+1),false);
  await docs.locator('input[type=file]').setInputFiles({name:'long-attachment-name-for-ui-review.txt',mimeType:'text/plain',buffer:Buffer.from('Test attachment; never written to the API.')});
  await page.screenshot({path:`${out}/${theme}-${width}-document-upload.png`,fullPage:true});record('Contract document picker and nested dialog');
  await page.keyboard.press('Escape');await page.keyboard.press('Escape');await page.waitForTimeout(250);
  role='technician';await page.reload();await page.waitForTimeout(550);await page.locator('tbody tr').first().click();await page.waitForTimeout(250);
  const work=page.getByRole('dialog',{name:'Ticket details',exact:true});
  await work.getByRole('button',{name:'Work logs',exact:true}).click();await page.waitForTimeout(300);
  const field=work.getByLabel('Start time');assert.equal(await field.evaluate(e=>getComputedStyle(e).fontSize),'16px');
  assert.ok(await work.getByLabel('Work description').count());
  await work.getByRole('button',{name:'Activity',exact:true}).click();await page.waitForTimeout(300);
  const reply=work.locator('.comment-compose textarea'),send=work.getByRole('button',{name:'Post comment',exact:true});
  const gap=await work.locator('.comment-compose').evaluate(e=>{const a=e.querySelector('textarea').getBoundingClientRect(),b=e.querySelector('.btn').getBoundingClientRect();return b.top-a.bottom;});assert.ok(gap>=15.5,`Reply action gap: ${gap}`);
  assert.equal(await work.evaluate(e=>e.scrollWidth>e.clientWidth+1),false);
  await page.screenshot({path:`${out}/${theme}-${width}-ticket-activity.png`,fullPage:true});record('Technician work logs, attachments and reply action spacing');
  await page.keyboard.press('Escape');await page.waitForTimeout(250);role='admin';await page.reload();await page.waitForTimeout(500);
  for(const state of ['loading','empty','error']){mode=state;await go('Customers');await page.waitForTimeout(state==='loading'?100:400);await page.screenshot({path:`${out}/${theme}-${width}-${state}.png`,fullPage:true});record('Customer '+state+' state');await go('Contracts');}
  mode='normal';role='super_admin';await page.reload();await page.waitForTimeout(500);await go('System Support');
  for(const tab of ['Health & runtime','Metrics & traces','Failed jobs','System logs','Audit history','Configuration']){await page.getByRole('tab',{name:tab,exact:true}).click();await page.waitForTimeout(300);record('System Support '+tab);}
  const cards=await page.locator('.support-config-grid').first().evaluate(e=>({direction:getComputedStyle(e).flexDirection,overflow:e.scrollWidth>e.clientWidth+1}));assert.equal(cards.direction,'column');assert.equal(cards.overflow,false);
  login=true;await page.reload();await page.waitForTimeout(400);await page.getByLabel('Email address').fill('test@example.test');await page.getByLabel('Password').fill('Test-only-value');await page.getByRole('button',{name:'Sign in',exact:true}).click();await page.waitForTimeout(300);
  assert.ok(await page.getByRole('heading',{name:'Verify your sign-in'}).count());await page.getByLabel('Use a one-time recovery code instead').check();assert.ok(await page.getByLabel('Recovery code').count());assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false);await page.screenshot({path:`${out}/${theme}-${width}-mfa-recovery.png`,fullPage:true});record('MFA and recovery-code input');
 }catch(e){record('FAILED',{failure:e.message});}
 await ctx.close();}
await browser.close();fs.writeFileSync(out+'/interactions.json',JSON.stringify(results,null,2));if(results.some(r=>r.failure||r.errors?.length))process.exitCode=1;console.log(JSON.stringify({checks:results.length,failures:results.filter(r=>r.failure||r.errors.length)},null,2));})();
