// Inline PDF Editor Fix - Compact version for direct injection
(function(){
// CSS Fixes
const s=document.createElement('style');
s.textContent=`.pdf-image-container{position:absolute!important;overflow:visible!important;z-index:100!important;border:1px dashed rgba(0,123,255,.3);cursor:move}.pdf-image-container.active{border:2px solid #007bff;box-shadow:0 0 10px rgba(0,123,255,.3)}.pdf-image,.pdf-image-container img,.pdf-vector{display:block!important;visibility:visible!important;opacity:1!important}.pdf-image,.pdf-image-container img{width:100%!important;height:100%!important;object-fit:contain!important;max-width:none!important;max-height:none!important}.pdf-image-controls{position:absolute;top:-35px;right:0;display:none;background:#fff;border:1px solid #ddd;border-radius:4px;padding:4px;box-shadow:0 2px 5px rgba(0,0,0,.2);z-index:1000}.pdf-image-container:hover .pdf-image-controls,.pdf-image-container.active .pdf-image-controls{display:flex!important;gap:4px}.pdf-image-controls button{padding:4px 8px;border:1px solid #ddd;background:#fff;cursor:pointer;border-radius:3px;font-size:12px}.pdf-image-controls button:hover{background:#f0f0f0}.resize-handle{position:absolute;width:10px;height:10px;background:#007bff;border:1px solid #fff;border-radius:50%;cursor:pointer;z-index:1001;display:none}.pdf-image-container:hover .resize-handle,.pdf-image-container.active .resize-handle{display:block!important}.resize-handle.nw{top:-5px;left:-5px;cursor:nw-resize}.resize-handle.ne{top:-5px;right:-5px;cursor:ne-resize}.resize-handle.sw{bottom:-5px;left:-5px;cursor:sw-resize}.resize-handle.se{bottom:-5px;right:-5px;cursor:se-resize}`;
document.head.appendChild(s);

// Fix existing images
function fix(){
const imgs=document.querySelectorAll('.pdf-image,.pdf-vector,img[src^="data:image"]');
imgs.forEach(i=>{
i.style.display='block';
i.style.visibility='visible';
i.style.opacity='1';
const c=i.closest('.pdf-image-container');
if(c){
c.style.overflow='visible';
c.style.zIndex='100';
if(!c.querySelector('.resize-handle')){
['nw','ne','sw','se'].forEach(p=>{
const h=document.createElement('div');
h.className='resize-handle '+p;
c.appendChild(h);
});
makeDrag(c);
makeResize(c);
}
// Don't add controls if they already exist
if(!c.querySelector('.pdf-image-controls')){
// Controls are already present in the HTML, don't add more
}
}
});
}

// Make draggable
function makeDrag(e){
let d=0,sx,sy,il,it;
e.addEventListener('mousedown',function(v){
if(v.target.classList.contains('resize-handle')||v.target.tagName==='BUTTON')return;
d=1;sx=v.clientX;sy=v.clientY;il=e.offsetLeft;it=e.offsetTop;
e.classList.add('active');v.preventDefault();
});
document.addEventListener('mousemove',function(v){
if(!d)return;
e.style.left=(il+v.clientX-sx)+'px';
e.style.top=(it+v.clientY-sy)+'px';
});
document.addEventListener('mouseup',function(){
if(d){d=0;e.classList.remove('active');}
});
}

// Make resizable
function makeResize(e){
e.querySelectorAll('.resize-handle').forEach(h=>{
let r=0,sx,sy,sw,sh,sl,st;
h.addEventListener('mousedown',function(v){
r=1;sx=v.clientX;sy=v.clientY;sw=e.offsetWidth;sh=e.offsetHeight;
sl=e.offsetLeft;st=e.offsetTop;e.classList.add('active');
v.preventDefault();v.stopPropagation();
});
document.addEventListener('mousemove',function(v){
if(!r)return;
const dx=v.clientX-sx,dy=v.clientY-sy,p=h.className.split(' ')[1];
switch(p){
case'se':e.style.width=(sw+dx)+'px';e.style.height=(sh+dy)+'px';break;
case'sw':e.style.width=(sw-dx)+'px';e.style.height=(sh+dy)+'px';e.style.left=(sl+dx)+'px';break;
case'ne':e.style.width=(sw+dx)+'px';e.style.height=(sh-dy)+'px';e.style.top=(st+dy)+'px';break;
case'nw':e.style.width=(sw-dx)+'px';e.style.height=(sh-dy)+'px';e.style.left=(sl+dx)+'px';e.style.top=(st+dy)+'px';break;
}
});
document.addEventListener('mouseup',function(){
if(r){r=0;e.classList.remove('active');}
});
});
}

// Initialize
if(document.readyState==='loading'){
document.addEventListener('DOMContentLoaded',function(){setTimeout(fix,100);});
}else{
setTimeout(fix,100);
}

// Monitor for changes
const o=new MutationObserver(function(){setTimeout(fix,100);});
o.observe(document.body,{childList:true,subtree:true});

console.log('PDF Editor Image Fix Applied');
})();