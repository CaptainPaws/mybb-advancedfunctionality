'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),{chromium}=require('playwright');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.AA_TEST_CHROMIUM||'/usr/bin/chromium',args:['--no-sandbox']});
 try {
  const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  const base='inc/plugins/advancedfunctionality/addons/';
  const source=fs.readFileSync(base+'adaptivethemeframework/postbit-signature.php','utf8');
  const frame=Array.from(source.match(/function af_adaptivethemeframework_gothic_frame\(\): string \{([\s\S]*?)\n\}/)[1].matchAll(/'([^']*)'/g),m=>m[1]).join('');
  assert.ok(frame.includes('atf-gothic-frame'));
  await page.setContent(`<body class="atf-active"><article id="post_101" class="atf-post af-atf-display af-aa-postbit-user-7" data-author-uid="7"><div class="atf-post__slot--before"></div><div class="atf-post__sidebar-inner"><div class="atf-post__secondary-avatar"><img src="https://example.test/first.png">${frame}</div><button id="profile-control">Profile</button></div><div class="atf-post__primary-avatar-media"><img src="https://example.test/main.png"></div></article><script type="application/json" id="af-aa-mask-seed">{"uid":7,"endpoint":"apstudio.php?api=mask"}</script></body>`);
  await page.addStyleTag({content:fs.readFileSync(base+'adaptivethemeframework/assets/surfaces/postbit.css','utf8')});
  await page.evaluate(()=>{
   window.maskResponse={masked:true,css:'',canvas:{},topbar_html:'<img src="https://example.test/main.png">',sidebar_html:'<img src="https://example.test/first.png">'};
   window.fetch=async()=>({ok:true,json:async()=>({ok:true,data:window.maskResponse})});
   const side=document.querySelector('.atf-post__secondary-avatar');
   window.originalFrame=side.querySelector('.atf-gothic-frame');window.originalImage=side.querySelector('img');window.originalSidebar=side.parentElement;
   window.clicks=0;document.querySelector('#profile-control').addEventListener('click',()=>window.clicks++);
  });
  await page.addScriptTag({content:fs.readFileSync(base+'advancedappearance/assets/post-masks.js','utf8')});
  const checkFrame=async()=>{
   assert.equal(await page.locator('.atf-post__secondary-avatar > .atf-gothic-frame').count(),1);
   assert.equal(await page.locator('.atf-post__secondary-avatar .atf-gothic-corner').count(),4);
   assert.ok(await page.evaluate(()=>window.originalFrame===document.querySelector('.atf-post__secondary-avatar > .atf-gothic-frame')&&window.originalSidebar===document.querySelector('.atf-post__sidebar-inner')));
  };
  await page.evaluate(()=>window.afAppearanceMasks.refresh(101));await checkFrame();
  assert.ok(await page.evaluate(()=>window.originalImage===document.querySelector('.atf-post__secondary-avatar > img')),'Unchanged image replaced');
  await page.evaluate(async()=>{window.maskResponse.sidebar_html='<img src="https://example.test/second.png">';await window.afAppearanceMasks.refresh(101);});await checkFrame();
  assert.match(await page.locator('.atf-post__secondary-avatar > img').getAttribute('src'),/second.png/);
  await page.evaluate(async()=>{window.maskResponse.masked=false;window.maskResponse.sidebar_html='';await window.afAppearanceMasks.refresh(101);});await checkFrame();
  assert.equal(await page.locator('.atf-post__secondary-avatar > img').count(),0);
  // Dialog preview inherits original inner HTML (including decoration): keep one original frame.
  await page.evaluate(frame=>window.afAppearanceMasks.updateAvatar(document.querySelector('.atf-post__secondary-avatar'),'<img src="https://example.test/restored.gif">'+frame),frame);await checkFrame();
  await page.locator('#profile-control').click();assert.equal(await page.evaluate(()=>window.clicks),1);
  assert.deepEqual(errors,[]);
  console.log('Mask frame passed: real ATF decoration survives unchanged/changed/reset avatar refresh, preview restore, with original frame/sidebar nodes and handlers.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
