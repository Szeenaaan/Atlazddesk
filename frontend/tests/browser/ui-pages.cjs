// UI-only fixtures. All API traffic is intercepted; no real credentials or business writes.
const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'playwright'), fs=require('fs');
const out=(process.env.UI_REPORT_DIR || require('node:path').join(require('node:os').tmpdir(),'atlazddesk-ui-review'));fs.mkdirSync(out,{recursive:true});
const roles=['admin','customer_admin','customer_user','technician','super_admin'];
const customer={id:1,company_name:'UI test customer',customer_code:'UI-1',city:'Kochi',status:'active'};
const asset={id:1,name:'Assigned workstation',asset_tag:'ASSET-1',customer_id:1,category_id:1,status:'active',customer,category:{id:1,name:'Hardware'}};
const ticket={id:1,ticket_number:'UI-001',subject:'Long ticket subject to verify wrapping and sidebar layout across narrow viewports',description:'Stored ticket description. '.repeat(20),customer_id:1,customer:customer.company_name,category:'Hardware',priority:'Critical',status:'New',assigned_to:4,assignee:'Assigned technician',created_at:'2026-10-20T10:00:00Z',sla_response_due_at:'2026-10-20T12:00:00Z',sla_resolution_due_at:'2026-10-21T10:00:00Z',asset:asset.name,comments:[{author:'Assigned technician',text:'A long internal note '.repeat(12),internal:true,time:'2026-10-20T10:15:00Z'}]};
const contract={id:1,contract_number:'AMC-UI-001',customer_id:1,customer,contract_type:'Annual maintenance',start_date:'2026-01-01',end_date:'2027-01-01',status:'active',contract_value:'1000.00',currency:'INR',sla_policy:{id:1,name:'Business hours'},services:[{id:1,name:'Support',description:'Service coverage',is_active:true}],notes:'Stored notes'};
const settings={installation_label:'SupportDesk',support_email:'',queue_warning_depth:100,disk_warning_percent:15,country_code:'IN',timezone:'Asia/Kolkata'};
function response(path,user){
 if(path==='/auth/me')return {user};
 if(path==='/bootstrap')return {user,tickets:[ticket],customers:user.role==='technician'?[]:[customer],assets:user.role==='technician'?[]:[asset],contracts:user.role==='admin'?[contract]:[],maintenance:[],technicians:[{id:4,name:'Assigned technician',email:'tech@example.test',role:'technician',status:'active'}]};
 if(path==='/lookups')return {customers:[customer],categories:[{id:1,name:'Hardware'}],priorities:[{id:1,name:'Critical'}],statuses:[{id:1,name:'New',slug:'new'}],technicians:[],customer_locations:['Kochi'],asset_types:[],sla_policies:[]};
 if(path==='/installation/timezone')return {data:{country_code:'IN',timezone:'Asia/Kolkata'}};
 if(path==='/reports/summary')return {total:1,open:1,resolved:0,critical_open:1,unassigned_open:0,priorities:{Critical:1},recent_tickets:[ticket],daily:[{day:'Mon',at:'2026-10-19T00:00:00Z',tickets:0,resolved:0},{day:'Tue',at:'2026-10-20T00:00:00Z',tickets:1,resolved:0}]};
 if(path==='/notifications')return {data:[],unread:0};
 if(path==='/tickets'||path==='/tickets/inbox')return {data:[ticket],meta:{last_page:1,total:1}};
 if(path==='/tickets/1')return {data:ticket};
 if(path.endsWith('/attachments'))return [];
 if(path.endsWith('/work-reports')||path.endsWith('/work-logs'))return {data:[]};
 if(path==='/directory/customers')return {data:[customer],total:1,last_page:1};
 if(path==='/directory/assets')return {data:[asset],total:1,last_page:1};
 if(path==='/directory/contracts')return {data:[contract],total:1,last_page:1};
 if(path==='/directory/technicians')return {data:[{id:4,name:'Assigned technician',email:'tech@example.test',role:'technician',status:'active'}],total:1,last_page:1};
 if(path==='/directory/customers/1')return customer;
 if(path==='/directory/assets/1')return asset;
 if(path==='/directory/contracts/1')return contract;
 if(path==='/directory/technicians/4')return {id:4,name:'Assigned technician',email:'tech@example.test',status:'active',phone:'+910000000000'};
 if(path==='/contract-options')return {policies:[{id:1,name:'Business hours',is_active:true,critical_response_minutes:30,critical_resolution_minutes:240}],services:[{id:1,name:'Support',is_active:true}]};
 if(path==='/categories')return [{id:1,name:'Hardware',slug:'hardware',is_active:true}];
 if(path==='/users')return {data:[{id:2,name:'Managed user',email:'user@example.test',role:'customer_user',customer_id:1,status:'active'}],total:1,last_page:1};
 if(path==='/users/2')return {id:2,name:'Managed user',email:'user@example.test',role:'customer_user',customer,phone:'+910000000000',status:'active',assets:[asset],created_at:'2026-01-01T00:00:00Z'};
 if(path==='/auth/mfa/status')return {totp_enabled:false,email_enabled:false,email_verified:false,required:false,recovery_remaining:0,available:{totp_enabled:false,email_enabled:false}};
 if(path==='/admin/security/mfa')return {data:{totp_enabled:false,email_enabled:false}};
 if(path==='/admin/mail/providers')return {data:{providers:[],default_provider_id:null}};
 if(path==='/admin/metrics')return {failed_jobs:0,incidents_open:0,queue_connection:'redis',cache_store:'redis',php_version:'8.4',laravel_version:'12'};
 if(path.includes('incidents'))return {data:[]};
 if(path==='/system/support/overview')return {data:{generated_at:'2026-10-20T10:00:00Z',installation:'SupportDesk',checks:{database:{status:'healthy',detail:'Available'}},runtime:{php:'8.4'},limitations:[]}};
 if(path==='/system/support/settings')return {data:settings};
 if(path==='/system/support/timezones')return {data:['Asia/Kolkata']};
 if(path==='/system/admins')return {data:[{id:7,name:'Contractor administrator with a long name',email:'administrator@example.test',status:'active',created_at:'2026-10-20T10:00:00Z'}]};
 if(path==='/system/security')return {data:{mail:{enabled:false,driver:'smtp',from_name:'',from_email:'',credential_configured:false},mfa:{totp_enabled:false,email_enabled:false},personal_mfa:{totp_enabled:false}}};
 if(path.includes('retention'))return {data:[{category:'audit',label:'Audit history',minimum_days:30,enabled:true,retention_days:90,run_every_days:1,batch_size:100,max_batches:2,eligible_count:45,last_run_at:null,last_deleted_count:0}]};
 if(path.includes('telemetry'))return {data:{sampled_requests:0,average_latency_ms:0,average_db_ms:0,server_error_observations:0,routes:[],recent_traces:[],settings:{enabled:false,sample_percent:10,slow_request_ms:1000,slow_query_ms:100,retention_hours:24},limitations:[],scope:'Test observations'}};
 if(path.includes('failed-jobs')||path.includes('logs'))return {data:[],categories:[],levels:[],pagination:{total:0,last_page:1}};
 return {data:[],total:0,last_page:1};
}
module.exports={response,customer,asset,ticket,contract};
if(require.main===module)(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});const results=[];
 for(const theme of ['dark','light'])for(const size of [{width:1440,height:1000},{width:390,height:844}])for(const role of roles){
  const context=await browser.newContext({viewport:size});const page=await context.newPage();await page.route('**/api/maintenance-status', route => route.fulfill({json:{maintenance:false}}));const errors=[];page.setDefaultTimeout(5000);
  page.on('pageerror',e=>errors.push(e.message));
  await context.addInitScript(theme=>localStorage.setItem('atlazddesk-theme',theme),theme);
  const user={id:role==='technician'?4:2,name:'UI test account',email:'test@example.test',role,customer_id:role.startsWith('customer')?1:null};
  await page.route('**/api/v2/**',async route=>{const path=new URL(route.request().url()).pathname.replace('/api/v2','');const data=response(path,user);await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify(data)});});
  await page.goto((process.env.UI_BASE_URL || 'http://127.0.0.1:4173'));await page.waitForTimeout(700);
  if (!(await page.locator('.sidebar').count())) {results.push({theme,role,width:size.width,failure:'No sidebar after restore',errors});fs.writeFileSync(out+'/results.json',JSON.stringify(results,null,2));await context.close();continue;}
  const pages=await page.locator('.sidebar .nav-item').allTextContents();
  for(const name of pages){
   if (!(await page.locator('.sidebar').count())) {results.push({theme,role,width:size.width,failure:'Page crashed',errors});break;}
   if(size.width<800&&!(await page.locator('.sidebar').evaluate(e=>e.classList.contains('shown')))){await page.locator('.mobile-menu').click();}
   const before=errors.length;
   try{
    await page.locator('.sidebar .nav-item').filter({hasText:new RegExp('^'+name.trim()+'$')}).click();await page.waitForTimeout(650);
    console.log(role,size.width,name.trim());fs.writeFileSync(out+'/results.json',JSON.stringify(results,null,2));
    const controls=await page.locator('.search-box input,.select-search-input input').evaluateAll(nodes=>nodes.filter(n=>n.getBoundingClientRect().width>0).map(n=>({border:getComputedStyle(n).borderTopWidth,font:getComputedStyle(n).fontSize})));
    if(controls.some(c=>c.border!=='0px'||c.font!=='16px'))results.push({theme,role,width:size.width,page:name.trim(),failure:'Inconsistent compound field',controls});
    const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1);
    results.push({theme,role,width:size.width,page:name.trim(),overflow,errors:errors.slice(before)});
    if(['My Inbox','Tickets'].includes(name.trim())&&await page.locator('tbody tr').count()){
      await page.locator('tbody tr').first().click();await page.waitForTimeout(250);
      await page.screenshot({path:`${out}/${theme}-${role}-${size.width}-ticket.png`,fullPage:true});
      const dialog=page.locator('dialog[open]').first();
      if(await dialog.count()){results.push({theme,role,width:size.width,page:'Ticket drawer',overflow:await dialog.evaluate(e=>e.scrollWidth>e.clientWidth+1),errors:errors.slice(before)});await page.keyboard.press('Escape');await page.waitForTimeout(250);}
    }
    if(name.trim()==='Overview')await page.screenshot({path:`${out}/${theme}-${role}-${size.width}-overview.png`,fullPage:true});
    if(name.trim()==='Contracts'&&await page.locator('tbody tr').count()){
      await page.locator('tbody tr').first().click();await page.waitForTimeout(250);await page.screenshot({path:`${out}/${theme}-${role}-${size.width}-contract.png`,fullPage:true});await page.keyboard.press('Escape');await page.waitForTimeout(250);
    }
    if(name.trim()==='System Support'){
      await page.getByRole('tab',{name:'Configuration',exact:true}).click();await page.waitForTimeout(400);await page.screenshot({path:`${out}/${theme}-${role}-${size.width}-configuration.png`,fullPage:true});
      const vertical=await page.locator('.support-config-grid').first().evaluate(e=>getComputedStyle(e).flexDirection==='column');results.push({theme,role,width:size.width,page:'System Support Configuration',vertical,errors:errors.slice(before)});
    }
   }catch(e){results.push({theme,role,width:size.width,page:name.trim(),failure:e.message.slice(0,240),errors:errors.slice(before)});break;}
  }
  await context.close();
 }
 await browser.close();fs.writeFileSync(out+'/results.json',JSON.stringify(results,null,2));if(results.some(r=>r.failure||r.overflow||r.errors?.length||r.vertical===false))process.exitCode=1;console.log(JSON.stringify({screens:results.length,failures:results.filter(x=>x.failure||x.overflow||x.errors?.length||x.vertical===false)},null,2));
})();
