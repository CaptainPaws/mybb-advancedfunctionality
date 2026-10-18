'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),{execFileSync}=require('node:child_process'),{chromium}=require('playwright');
(async()=>{
 const css=JSON.parse(fs.readFileSync(process.argv[2]||'work/background-paint.json','utf8'));
 const red=execFileSync('python3',['-c','from PIL import Image; import sys; Image.new("RGB",(2,2),(220,30,30)).save(sys.stdout.buffer,format="PNG")']);
 const browser=await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox']});
 try {
 const page=await browser.newPage();await page.route('**/*',r=>r.fulfill({contentType:'image/png',body:red}));
 await page.setContent('<body class="atf-active"><div id="aa-studio-preview"><article class="atf-post af-atf-display af-aa-postbit-user-7" data-element="fire"><header class="atf-post__topbar"></header><div class="atf-post__sidebar-inner"></div><div class="atf-post__layout"></div><a id="control" href="#" style="color:rgb(0,255,0)">Click</a></article></div></body>');
 await page.addStyleTag({path:'inc/plugins/advancedfunctionality/addons/adaptivethemeframework/assets/surfaces/postbit.css'});
 await page.addStyleTag({content:'.atf-post{--atf-post-accent-soft:transparent!important}.atf-post__topbar,.atf-post__sidebar-inner,.atf-post__layout{position:relative!important;width:120px!important;height:100px!important;min-height:100px!important;padding:0!important;margin:0!important;border:0!important;border-radius:0!important;display:block!important}'});
 const style=await page.addStyleTag({content:'.paint-placeholder{}'});
 const samples={};
 for(const [name,text] of Object.entries(css)){
  await style.evaluate((el,text)=>el.textContent=text,text);
  await page.evaluate(async()=>{await Promise.all(Array.from(document.images).map(i=>i.decode().catch(()=>{})));const img=new Image();img.src='https://images.example.org/red.png';await img.decode();});
  samples[name]=[];
  for(const selector of ['.atf-post__topbar','.atf-post__sidebar-inner','.atf-post__layout']){
   const image=await page.locator(selector).screenshot();
   const pixel=JSON.parse(execFileSync('python3',['-c','from PIL import Image;import sys,json,io;im=Image.open(io.BytesIO(sys.stdin.buffer.read())).convert("RGB");print(json.dumps(im.getpixel((10,50))))'],{input:image}).toString());
   samples[name].push(pixel);
   assert.equal(await page.locator(selector).evaluate(el=>getComputedStyle(el).filter),'none','Filter leaked to content');
  }
 }
 for(let i=0;i<3;i++){
  assert.ok(samples.full[i].every(v=>v<=2),'100% is not opaque black: '+JSON.stringify(samples));
  const gray=samples.gray[i];assert.ok(Math.max(...gray)-Math.min(...gray)<=2,'0% saturation is not gray');
  const color=samples.color[i],half=samples.half[i];assert.ok(color[0]-color[1]>150,'100% saturation lost source color');
  assert.ok(half[0]-half[1]>5&&half[0]-half[1]<color[0]-color[1],'50% saturation does not interpolate');
 }
 assert.equal(await page.locator('#control').evaluate(el=>getComputedStyle(el).color),'rgb(0, 255, 0)');
 await page.locator('#control').evaluate(el=>{window.paintClicks=0;el.addEventListener('click',()=>window.paintClicks++);});await page.locator('#control').click();assert.equal(await page.evaluate(()=>window.paintClicks),1);
 console.log('Background pixel checks passed:',JSON.stringify(samples));
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
