'use strict';
const {chromium}=require('playwright'),fs=require('fs'),path=require('path'),http=require('http'),assert=require('node:assert/strict'),{execFileSync}=require('child_process');
const root=path.resolve(__dirname,'..'),addon='inc/plugins/advancedfunctionality/addons/adaptivethemeframework/',php=process.env.PHP_BIN||'php';
const html=execFileSync(php,[path.join(__dirname,'fixtures/stage6_thread.php')],{encoding:'utf8'});
const surfaces=['navigation','showthread','postbit','compose'];
const current=fs.readFileSync(path.join(root,addon,'assets/adaptivethemeframework.css'),'utf8')+surfaces.map(s=>fs.readFileSync(path.join(root,addon,'assets/surfaces/'+s+'.css'),'utf8')).join('\n');
const baseline=process.env.BASELINE_CSS?fs.readFileSync(process.env.BASELINE_CSS,'utf8'):null;
const requests=[];
const server=http.createServer((req,res)=>{
 requests.push(req.url);
 if(req.url.startsWith('/inc/')){const file=path.join(root,req.url.split('?')[0]);res.setHeader('content-type',file.endsWith('.js')?'application/javascript':'text/css');return res.end(fs.readFileSync(file));}
 if(req.url.startsWith('/sheet'))return res.end('<div>Lazy character sheet</div>');
 const mode=req.url.includes('baseline')?'baseline':'current';
 const css=mode==='baseline'&&baseline?baseline:current;
 const extra=`<style>html,body{margin:0} .af-am-navigation{position:fixed;top:0;height:56px;width:100%;background:#222;z-index:100}main{padding-top:70px}p{margin:1rem 0}</style><style>${css}</style>`;
 let result=html.replace('</head>',extra+'</head>');
 if(mode==='baseline'&&process.env.BASELINE_STICKY)result=result.replace(/<script[^>]*postbit-sticky[^>]*><\/script>/,'<script src="/baseline-sticky.js"></script>');
 if(req.url==='/baseline-sticky.js'){res.setHeader('content-type','application/javascript');return res.end(fs.readFileSync(process.env.BASELINE_STICKY,'utf8'));}
 result=result.replace('</body>','<script src="/inc/plugins/advancedfunctionality/addons/charactersheets/assets/charactersheets-trigger.js"></script></body>');
 res.end(result);
});
async function frames(page,n=4){await page.evaluate(n=>new Promise(resolve=>{function step(){if(--n<=0)resolve();else requestAnimationFrame(step);}requestAnimationFrame(step);}),n);}
async function snapshot(page){return page.evaluate(()=>[...document.querySelectorAll('.atf-thread,.atf-post,.atf-post *, .atf-quick-reply,.atf-quick-reply *')].map(el=>{const style=getComputedStyle(el),rect=el.getBoundingClientRect();return {tag:el.tagName,cls:el.className,rect:[rect.x,rect.y,rect.width,rect.height].map(x=>Math.round(x*100)/100),style:Object.fromEntries(['display','position','box-sizing','overflow','overflow-x','overflow-y','color','background-color','background-image','border-top','border-right','border-bottom','border-left','border-radius','padding','margin','font-family','font-size','font-weight','line-height','gap','grid-template-columns','grid-template-areas','align-items','justify-content','transform','width','height','min-width','max-width','min-height','max-height','box-shadow','z-index','opacity','clip-path','object-fit','object-position'].map(p=>[p,style.getPropertyValue(p)]))};}));}
(async()=>{
 await new Promise(r=>server.listen(0,'127.0.0.1',r));const url=`http://127.0.0.1:${server.address().port}`;
 const browser=await chromium.launch({executablePath:process.env.CHROMIUM||'/usr/bin/chromium',headless:true,args:['--no-sandbox']});
 try{
 const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
 const comparisons=[];
 const ownership=Object.fromEntries(['adaptivethemeframework.css',...fs.readdirSync(path.join(root,addon,'assets/surfaces')).map(f=>'surfaces/'+f)].map(f=>[f,fs.readFileSync(path.join(root,addon,'assets',f),'utf8')]));
 await page.goto(url+'/current');
 const duplicateRules=await page.evaluate(ownership=>{const owners=new Map(),duplicates=[];function walk(rules,file,context=''){for(const rule of rules){if(rule.cssRules)walk(rule.cssRules,file,context+' '+rule.conditionText);else if(rule.selectorText){const key=context+' '+rule.selectorText+' '+rule.style.cssText;if(owners.has(key)&&owners.get(key)!==file)duplicates.push([owners.get(key),file,rule.selectorText]);else owners.set(key,file);}}}for(const [file,css] of Object.entries(ownership)){const sheet=new CSSStyleSheet();sheet.replaceSync(css);walk(sheet.cssRules,file);}return duplicates;},ownership);
 assert.deepEqual(duplicateRules,[], 'A CSS rule is owned by more than one surface');
 for(const width of [1440,1000,769,768,600,375]){
  await page.setViewportSize({width,height:900});
  for(const scroll of width>768?[0,400,1100]:[0]){
   console.error('checking',width,scroll);
   let before;if(baseline){await page.goto(url+'/baseline');await frames(page);await page.evaluate(y=>scrollTo(0,y),scroll);await frames(page);before=await snapshot(page);}
   await page.goto(url+'/current');await frames(page);await page.evaluate(y=>scrollTo(0,y),scroll);await frames(page);
   const after=await snapshot(page);
   if(before){const diffs=[];for(let i=0;i<before.length;i++)if(JSON.stringify(before[i])!==JSON.stringify(after[i]))diffs.push({i,cls:before[i].cls,rectBefore:before[i].rect,rectAfter:after[i].rect,properties:Object.keys(before[i].style).filter(k=>before[i].style[k]!==after[i].style[k]).map(k=>[k,before[i].style[k],after[i].style[k]])});assert.deepEqual(diffs,[],`CSS/sticky differences at width=${width} scroll=${scroll}`);}
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
   comparisons.push({width,scroll,elements:after.length,equal:!!before});
  }
 }
 await page.setViewportSize({width:1440,height:900});await page.goto(url+'/current');await frames(page);
 // No geometry reads during scroll-only frames after initial observer delivery.
 await page.evaluate(()=>{window.geometryReads=0;const real=Element.prototype.getBoundingClientRect;Element.prototype.getBoundingClientRect=function(){window.geometryReads++;return real.call(this);};});
 await page.evaluate(()=>scrollTo(0,500));await frames(page);assert.equal(await page.evaluate(()=>window.geometryReads),0);
 // Internal sidebar resizing must invalidate geometry even if the post stays tall.
 await page.evaluate(()=>{document.querySelector('.atf-post__sidebar-inner').style.height='700px';});await frames(page);
 assert((await page.evaluate(()=>window.geometryReads))>0);
 await page.evaluate(()=>{window.geometryReads=0;scrollTo(0,700);});await frames(page);assert.equal(await page.evaluate(()=>window.geometryReads),0);
 // Quick reply can append a real post; the new controller must join the stack.
 await page.evaluate(()=>{const post=document.querySelector('.atf-post').cloneNode(true);post.id='post_added';document.getElementById('posts').appendChild(post);});await frames(page);
 assert.equal(Number(await page.locator('#post_added .atf-post__topbar').getAttribute('data-atf-sticky-translate')),0); // below viewport: unchanged transform
 await page.setViewportSize({width:375,height:900});await frames(page);
 await page.evaluate(()=>{window.geometryReads=0;scrollTo(0,1000);});await frames(page);assert.equal(await page.evaluate(()=>window.geometryReads),0);
 assert.equal(await page.locator('.atf-post__topbar').first().evaluate(e=>e.style.transform),'');
 await page.setViewportSize({width:1440,height:900});await frames(page);
 await page.evaluate(()=>scrollTo(0,0));await frames(page);
 // Actual lazy provider opens/closes after ATF mounts it into the canonical host.
 await page.locator('[data-afcs-open]').first().click();await frames(page);
 assert.equal(await page.locator('#atf-global-modal-host [data-afcs-modal]').count(),1);
 await page.keyboard.press('Escape');assert.equal(await page.locator('iframe').count(),0);
 // Observer only scans the inserted subtree and removes late duplicate roots.
 await page.evaluate(()=>{window.documentScans=0;const original=document.querySelectorAll.bind(document);document.querySelectorAll=function(s){window.documentScans++;return original(s);};const wrapper=document.createElement('div');wrapper.innerHTML='<div data-af-balance-modal hidden></div>';document.body.appendChild(wrapper);});await frames(page);
 assert.equal(await page.evaluate(()=>window.documentScans),0);assert.equal(await page.locator('#atf-global-modal-host [data-af-balance-modal]').count(),1);
 await page.evaluate(()=>{const x=document.createElement('div');x.setAttribute('data-af-balance-modal','');document.body.appendChild(x);});await frames(page);assert.equal(await page.locator('[data-af-balance-modal]').count(),1);
 assert.deepEqual(errors,[]);console.log(JSON.stringify({passed:true,comparisons,scrollGeometryReads:0,mobileGeometryReads:0,modalMutationDocumentScans:0},null,2));
 }finally{await browser.close();server.closeAllConnections();server.close();}
})().catch(e=>{console.error(e);server.closeAllConnections();server.close();process.exitCode=1;});
