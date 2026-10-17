const {chromium}=require('playwright');const fs=require('fs');
(async()=>{const browser=await chromium.launch({headless:true,executablePath:process.env.AA_CHROMIUM||(fs.existsSync('/usr/bin/chromium')?'/usr/bin/chromium':undefined),args:['--no-sandbox']});const page=await browser.newPage();await page.setContent('<body class="atf-active"><article class="atf-post af-atf-display af-aa-postbit-user-7" data-element=""><header class="post_head atf-post__topbar">Header</header><div class="atf-post__layout"><div class="atf-post__sidebar-inner">Avatar</div><div class="atf-post__content">Text</div></div></article>');await page.route('**/*',r=>r.abort());await page.addStyleTag({content:fs.readFileSync('inc/plugins/advancedfunctionality/addons/adaptivethemeframework/assets/surfaces/postbit.css','utf8')});
await page.addStyleTag({content:fs.readFileSync(process.argv[2]||'work/compiled.css','utf8')});
for(const [key,selector,pseudo]of [['topbar','.atf-post__topbar',null],['sidebar','.atf-post__sidebar-inner',null],['message_background','.atf-post__layout','::before']]){const result=await page.evaluate(({selector,pseudo})=>{const el=document.querySelector(selector);const c=getComputedStyle(el,pseudo);return {image:c.backgroundImage,size:c.backgroundSize,position:c.backgroundPosition,repeat:c.backgroundRepeat,opacity:getComputedStyle(el).opacity,filter:getComputedStyle(el).filter}}, {selector,pseudo});if(!result.image.includes('original.gif')||!result.image.includes('90deg')||!result.image.includes('115deg')||result.opacity!=='1'||!result.size.includes('contain')||!result.position.includes('100%'))throw Error(JSON.stringify({key,result}));console.log(key+': PHP preset reaches real ATF background; independent mask, haze, GIF, size, position; content opacity=1');}
// Raster check: full-strength masks must change the actual background pixels.
await page.addStyleTag({content:'.atf-post__topbar,.atf-post__sidebar-inner,.atf-post__layout{display:block!important;position:relative!important;width:160px!important;height:80px!important;min-height:0!important;padding:0!important;border:0!important;box-shadow:none!important;margin:0!important;background-color:#808080!important;color:#eeeeee!important}.atf-post__content{display:none!important}'});
const rasterStyle=await page.addStyleTag({content:'/* raster cases */'});
const cases=JSON.parse(fs.readFileSync((process.argv[2]||'work/compiled.css')+'.json','utf8'));
for(const [key,selector]of [['topbar','.atf-post__topbar'],['sidebar','.atf-post__sidebar-inner'],['message_background','.atf-post__layout']]){
  // Use separate fixtures so no child's paint hides the layout pseudo-element.
  await page.locator('.atf-post__sidebar-inner').evaluate((el,key)=>el.style.setProperty('display',key==='message_background'?'none':'block','important'),key);
  const pixels={};
  for(const mode of ['darken','lighten','none','zero']){
    await rasterStyle.evaluate((el,css)=>el.textContent=css,cases[mode]);
    const png=await page.locator(selector).screenshot();
    pixels[mode]=await page.evaluate(async url=>{const image=new Image();image.src=url;await image.decode();const canvas=document.createElement('canvas');canvas.width=image.width;canvas.height=image.height;const c=canvas.getContext('2d');c.drawImage(image,0,0);return [...c.getImageData(image.width-12,image.height-12,1,1).data]},'data:image/png;base64,'+png.toString('base64'));
  }
  if(pixels.darken.slice(0,3).some(v=>v>2)||pixels.lighten.slice(0,3).some(v=>v<253)||JSON.stringify(pixels.none)!==JSON.stringify(pixels.zero))throw Error(JSON.stringify({key,pixels}));
  console.log(key+': raster pixels confirm black/white full masks and zero intensity = no mask');
}
await browser.close()})().catch(e=>{console.error(e);process.exit(1)});
