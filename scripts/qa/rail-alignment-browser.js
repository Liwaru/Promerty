async(page)=>{
const c=page.context().browser().contexts().findLast(c=>c.__gold),p=c.__goldPage,side=c.__goldSide,rows=[],errors=[],network=[];
const root='C:/Users/Hendra Huang/Documents/new laravel/Promerty/docs/customer/qa/rail-align-';
p.on('pageerror',e=>errors.push(e.message));p.on('response',r=>{if(r.status()>=400)network.push({status:r.status(),url:r.url()})});
const measure=()=>p.evaluate(()=>{
 const box=e=>{const r=e.getBoundingClientRect();return{x:r.x,y:r.y,w:r.width,h:r.height,cx:r.x+r.width/2,cy:r.y+r.height/2}};
 const css=e=>{const s=getComputedStyle(e);return {display:s.display,padding:s.padding,gap:s.gap,border:s.borderWidth,boxSizing:s.boxSizing,align:s.alignItems,justify:s.justifyContent,transform:s.transform,lineHeight:s.lineHeight}};
 const rail=document.querySelector('.f-sidebar'),logo=rail.querySelector('.p-brand-logo'),form=rail.querySelector('#sidebar-search'),button=form.querySelector('button'),svg=button.querySelector('svg');
 const bb=svg.getBBox(),m=svg.getScreenCTM(),point=new DOMPoint(bb.x+bb.width/2,bb.y+bb.height/2).matrixTransform(m);
 return{theme:document.documentElement.dataset.theme,rail:box(rail),railContentCenter:rail.getBoundingClientRect().x+(rail.clientWidth/2),logo:box(logo),form:box(form),button:box(button),svg:box(svg),svgInk:{box:{x:bb.x,y:bb.y,w:bb.width,h:bb.height},cx:point.x,cy:point.y},styles:{rail:css(rail),form:css(form),button:css(button),svg:css(svg)},nav:[...rail.querySelectorAll('.f-nav')].map(e=>({label:e.getAttribute('aria-label'),active:e.hasAttribute('aria-current'),expanded:e.getAttribute('aria-expanded'),box:box(e),icon:box(e.querySelector('svg')),indicator:{position:getComputedStyle(e,'::before').position,width:getComputedStyle(e,'::before').width}})),header:box(document.querySelector('.f-header')),main:box(document.querySelector('.f-main')),overflow:document.documentElement.scrollWidth>innerWidth};
});
for(const [width,height]of[[1920,1080],[1440,900],[1366,768],[1280,720]]){
 await p.setViewportSize({width,height});
 for(const theme of ['light','dark']){
  await p.goto('http://localhost:8000/');await p.waitForSelector('.c-empty-editorial');await p.evaluate(t=>window.PromertyTheme.set(t),theme);await p.waitForTimeout(300);
  for(const state of ['normal','search','more']){
   if(state==='search'){await p.locator('#sidebar-search button').click();await p.waitForSelector('#customer-search-drawer[open]');}
   if(state==='more'){await p.getByRole('button',{name:'Lainnya',exact:true}).first().click();await p.waitForSelector('#customer-more[open]');}
   await p.mouse.move(1100,650);await p.waitForTimeout(300);rows.push({width,height,state,...await measure()});await p.screenshot({path:root+side+'-'+state+'-'+theme+'-'+width+'.png'});
   if(state!=='normal'){await p.keyboard.press('Escape');await p.waitForFunction(()=>!document.querySelector('#customer-more').open&&!document.querySelector('#customer-search-drawer').open);}
  }
 }
}
return c['__rail'+side]={side,rows,errors,network};
}
