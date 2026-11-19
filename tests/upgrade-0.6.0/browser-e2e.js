const { chromium, devices } = require('playwright');
const B='http://127.0.0.1:8080/crm';
const SH='./shots/';
let pass=0, fail=0; const fails=[];
const ok=(c,l)=>{ if(c){pass++; console.log('  PASS',l);} else {fail++; fails.push(l); console.log('  FAIL',l);} };
async function login(ctx,email,pw){
  const p=await ctx.newPage(); p._errs=[];
  p.on('pageerror',e=>p._errs.push(String(e))); p.on('console',m=>{ if(m.type()==='error' && !/402|403|favicon/.test(m.text())) p._errs.push(m.text()); });
  await p.goto(B+'/login'); await p.fill('input[type=email]',email); await p.fill('input[type=password]',pw);
  await p.click('button[type=submit]'); await p.waitForURL(u=>!/login/.test(u.toString()),{timeout:15000}); await p.waitForLoadState('networkidle');
  return p;
}
const navText=async p=>(await p.locator('aside, nav').allInnerTexts()).join(' ');
(async()=>{
  const br=await chromium.launch();
  // ---------- owner desktop
  let ctx=await br.newContext({viewport:{width:1440,height:900},deviceScaleFactor:1});
  let p=await login(ctx,'owner@studio.test','Owner#12345');
  await p.waitForTimeout(1500);
  const dash=await p.innerText('main');
  ok(/Total Revenue|Revenue/.test(dash),'owner dashboard shows revenue');
  ok(/Customi[sz]e/.test(await p.innerText('body')),'dashboard has Customize button');
  await p.screenshot({path:SH+'owner-dashboard.png',fullPage:true});
  const nav=await navText(p);
  for (const n of ['Payments','Reports','Team','Settings']) ok(nav.includes(n),'owner nav has '+n);
  // customize: open drawer, hide a widget, save, reload
  await p.getByRole('button',{name:/Customi[sz]e/}).first().click(); await p.waitForTimeout(800);
  await p.screenshot({path:SH+'owner-customize.png'});
  const drawer=await p.innerText('body'); ok(/Reset|Restore/.test(drawer),'customize drawer opens');
  await p.keyboard.press('Escape');
  // leads list multi-day
  await p.goto(B+'/leads'); await p.waitForLoadState('networkidle'); await p.waitForTimeout(800);
  const lt=await p.innerText('main'); ok(/Multi Day Couple/.test(lt),'leads list shows new lead');
  ok(/4 days|10.*13|10-02-2027/.test(lt),'leads list shows multi-day event');
  await p.screenshot({path:SH+'leads.png'});
  // deliverables by client
  await p.goto(B+'/deliverables'); await p.waitForLoadState('networkidle'); await p.waitForTimeout(800);
  const dt=await p.innerText('main'); ok(/By client/i.test(dt),'deliverables has By client view');
  await p.screenshot({path:SH+'deliverables.png'});
  // quotation builder
  await p.goto(B+'/quotations/new'); await p.waitForLoadState('networkidle'); await p.waitForTimeout(800);
  const qt=await p.innerText('main'); ok(/Client & events|Services & packages/i.test(qt),'quotation step builder loads');
  await p.screenshot({path:SH+'quotation-builder.png'});
  // settings tabs
  await p.goto(B+'/settings'); await p.waitForLoadState('networkidle'); await p.waitForTimeout(800);
  const st=await p.innerText('main');
  for (const t of ['Quote packages','Reminders','Google Calendar','Online payments','Plan']) ok(st.includes(t),'settings tab '+t);
  await p.goto(B+'/settings?tab=calendar'); await p.waitForTimeout(1200); ok(/Google Calendar/.test(await p.innerText('main')),'Google Calendar tab renders');
  await p.screenshot({path:SH+'settings-gcal.png'});
  await p.goto(B+'/settings?tab=reminders'); await p.waitForTimeout(1200); ok(/remind/i.test(await p.innerText('main')),'Reminders tab renders');
  await p.goto(B+'/settings?tab=packages'); await p.waitForTimeout(1200); ok(/package/i.test(await p.innerText('main')),'Packages tab renders');
  // billing
  await p.goto(B+'/billing'); await p.waitForLoadState('networkidle'); await p.waitForTimeout(1000);
  const bt=await p.innerText('main'); ok(/Business/.test(bt),'billing page shows current plan');
  await p.screenshot({path:SH+'billing.png',fullPage:true});
  // bell
  ok(await p.locator('[aria-label*="otification" i], [aria-label*="Reminder" i]').count()>0,'notification bell present');
  for (const path of ['/payments','/reports','/team','/calendar','/bookings','/clients','/tasks','/pipeline']) { await p.goto(B+path); await p.waitForLoadState('networkidle'); await p.waitForTimeout(500); ok(!/Something went wrong|Not found/i.test(await p.innerText('main')),'owner opens '+path); }
  ok(p._errs.length===0,'owner: no JS errors '+p._errs.slice(0,3).join(' | '));
  await ctx.close();
  // ---------- sales desktop
  ctx=await br.newContext({viewport:{width:1440,height:900}});
  p=await login(ctx,'m_sales@studio.test','Team#12345'); await p.waitForTimeout(1500);
  const sn=await navText(p);
  for (const n of ['Payments','Reports','Team']) ok(!sn.includes(n),'sales nav hides '+n);
  const sd=await p.innerText('main'); ok(!/Total Revenue|Pending Payments/.test(sd),'sales dashboard has no money widgets');
  await p.screenshot({path:SH+'sales-dashboard.png',fullPage:true});
  await p.goto(B+'/payments'); await p.waitForTimeout(1200); const sp=await p.innerText('main'); ok(!/Record payment|Collected/i.test(sp),'sales /payments shows no payment data');
  await p.screenshot({path:SH+'sales-payments.png'});
  ok(p._errs.length===0,'sales: no JS errors '+p._errs.slice(0,3).join(' | '));
  await ctx.close();
  // ---------- photographer phone
  ctx=await br.newContext({...devices['iPhone 13']});
  p=await login(ctx,'m_photographer@studio.test','Team#12345'); await p.waitForTimeout(1500);
  await p.screenshot({path:SH+'photographer-phone.png',fullPage:true});
  const pd=await p.innerText('body'); ok(!/Total Revenue|Pending Payments/.test(pd),'photographer phone dashboard has no money');
  const sw=await p.evaluate(()=>document.documentElement.scrollWidth); ok(sw<=400,'no horizontal scroll on phone ('+sw+')');
  ok(p._errs.length===0,'photographer: no JS errors '+p._errs.slice(0,3).join(' | '));
  await ctx.close();
  // ---------- owner phone
  ctx=await br.newContext({...devices['iPhone 13']});
  p=await login(ctx,'owner@studio.test','Owner#12345'); await p.waitForTimeout(1500);
  await p.screenshot({path:SH+'owner-phone.png'});
  for (const path of ['/leads','/deliverables','/quotations/new','/billing','/settings?tab=reminders']) { await p.goto(B+path); await p.waitForLoadState('networkidle'); await p.waitForTimeout(600); const w=await p.evaluate(()=>document.documentElement.scrollWidth); ok(w<=400,'phone '+path+' fits width ('+w+')'); }
  await p.goto(B+'/leads'); await p.waitForTimeout(800); await p.screenshot({path:SH+'owner-phone-leads.png'});
  ok(p._errs.length===0,'owner phone: no JS errors '+p._errs.slice(0,3).join(' | '));
  await ctx.close();
  // ---------- homepage pricing + client quote page
  ctx=await br.newContext({viewport:{width:1440,height:900}}); p=await ctx.newPage();
  await p.goto('http://127.0.0.1:8080/'); const ht=await p.innerText('body'); ok(/Starter/.test(ht)&&/Professional/.test(ht)&&/Business/.test(ht),'homepage pricing has 3 plans');
  await p.screenshot({path:SH+'home.png'});
  await ctx.close();
  await br.close();
  console.log(`RESULT pass=${pass} fail=${fail}`); fails.forEach(f=>console.log('FAILED:',f));
})().catch(e=>{console.error(e); process.exit(1);});
