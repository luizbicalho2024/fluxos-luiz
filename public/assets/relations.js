(() => {
  'use strict';

  const host = document.getElementById('relationGraph');
  if (!host) return;
  const canvas = host.querySelector('canvas');
  const ctx = canvas.getContext('2d');
  const url = host.dataset.url;
  let width = 0, height = 0, dpr = 1;
  let nodes = [], links = [], broken = [];
  let zoom = 1, panX = 0, panY = 0;
  let draggingNode = null, draggingCanvas = false, lastX = 0, lastY = 0;
  let hovered = null, raf = null, running = true;

  const palette = {
    published: '#12b76a', approved: '#2e90fa', in_review: '#f79009', archived: '#98a2b3', draft: '#7f56d9'
  };

  function resize() {
    const rect = host.getBoundingClientRect();
    dpr = Math.max(1, Math.min(2, window.devicePixelRatio || 1));
    width = Math.max(320, rect.width);
    height = Math.max(320, rect.height);
    canvas.width = Math.round(width * dpr);
    canvas.height = Math.round(height * dpr);
    canvas.style.width = `${width}px`;
    canvas.style.height = `${height}px`;
    ctx.setTransform(dpr,0,0,dpr,0,0);
    draw();
  }

  function graphPoint(clientX, clientY) {
    const rect = canvas.getBoundingClientRect();
    return {x:(clientX-rect.left-panX)/zoom, y:(clientY-rect.top-panY)/zoom};
  }

  function hitTest(clientX, clientY) {
    const p = graphPoint(clientX, clientY);
    for (let i=nodes.length-1;i>=0;i--) {
      const n = nodes[i];
      const r = n.radius || 22;
      if ((n.x-p.x)**2 + (n.y-p.y)**2 <= (r+6)**2) return n;
    }
    return null;
  }

  function seedPositions() {
    const count = Math.max(1,nodes.length);
    const radius = Math.min(width,height) * .32;
    nodes.forEach((n,i) => {
      const a = (Math.PI*2*i/count)-Math.PI/2;
      n.x = width/2 + Math.cos(a)*radius;
      n.y = height/2 + Math.sin(a)*radius;
      n.vx = 0; n.vy = 0; n.radius = 22;
    });
  }

  function physics() {
    if (!running || !nodes.length) return;
    const centerX = width/2, centerY = height/2;
    const byId = new Map(nodes.map(n=>[n.id,n]));

    for (let i=0;i<nodes.length;i++) {
      const a=nodes[i];
      for (let j=i+1;j<nodes.length;j++) {
        const b=nodes[j];
        let dx=a.x-b.x, dy=a.y-b.y;
        const d2=Math.max(350,dx*dx+dy*dy), d=Math.sqrt(d2);
        const force=1800/d2;
        dx/=d;dy/=d;
        a.vx+=dx*force; a.vy+=dy*force;
        b.vx-=dx*force; b.vy-=dy*force;
      }
    }

    for (const l of links) {
      const a=byId.get(l.source), b=byId.get(l.target);
      if(!a||!b)continue;
      let dx=b.x-a.x,dy=b.y-a.y,d=Math.max(1,Math.hypot(dx,dy));
      const target=150, force=(d-target)*.0035;
      dx/=d;dy/=d;
      a.vx+=dx*force;a.vy+=dy*force;b.vx-=dx*force;b.vy-=dy*force;
    }

    for (const n of nodes) {
      if (n===draggingNode) continue;
      n.vx += (centerX-n.x)*.00035;
      n.vy += (centerY-n.y)*.00035;
      n.vx *= .88; n.vy *= .88;
      n.x += n.vx; n.y += n.vy;
      n.x=Math.max(45,Math.min(width-45,n.x));
      n.y=Math.max(45,Math.min(height-45,n.y));
    }
  }

  function roundedRect(x,y,w,h,r) {
    ctx.beginPath();
    ctx.roundRect(x,y,w,h,r);
  }

  function drawArrow(a,b,isBroken=false) {
    const dx=b.x-a.x,dy=b.y-a.y,d=Math.max(1,Math.hypot(dx,dy));
    const ux=dx/d,uy=dy/d;
    const start={x:a.x+ux*(a.radius+3),y:a.y+uy*(a.radius+3)};
    const end={x:b.x-ux*(b.radius+9),y:b.y-uy*(b.radius+9)};
    ctx.strokeStyle=isBroken?'#f04438':'rgba(124,140,161,.72)';
    ctx.lineWidth=isBroken?2.5:1.5;
    ctx.setLineDash(isBroken?[6,5]:[]);
    ctx.beginPath();ctx.moveTo(start.x,start.y);ctx.lineTo(end.x,end.y);ctx.stroke();
    ctx.setLineDash([]);
    const angle=Math.atan2(end.y-start.y,end.x-start.x);
    ctx.fillStyle=ctx.strokeStyle;
    ctx.beginPath();
    ctx.moveTo(end.x,end.y);
    ctx.lineTo(end.x-Math.cos(angle-.45)*9,end.y-Math.sin(angle-.45)*9);
    ctx.lineTo(end.x-Math.cos(angle+.45)*9,end.y-Math.sin(angle+.45)*9);
    ctx.closePath();ctx.fill();
  }

  function draw() {
    if (!ctx) return;
    ctx.setTransform(dpr,0,0,dpr,0,0);
    ctx.clearRect(0,0,width,height);
    ctx.save();
    ctx.translate(panX,panY); ctx.scale(zoom,zoom);
    const byId = new Map(nodes.map(n=>[n.id,n]));
    const brokenPairs = new Set(broken.map(x=>`${x.source_flow_id}|${x.target_flow_id}`));

    for (const l of links) {
      const a=byId.get(l.source),b=byId.get(l.target);if(!a||!b)continue;
      drawArrow(a,b,brokenPairs.has(`${l.source}|${l.target}`));
    }

    for (const n of nodes) {
      const active=n===hovered||n===draggingNode;
      ctx.shadowColor=active?'rgba(21,111,101,.35)':'rgba(20,35,55,.16)';
      ctx.shadowBlur=active?18:8;
      ctx.fillStyle=palette[n.status]||'#667085';
      ctx.beginPath();ctx.arc(n.x,n.y,n.radius,0,Math.PI*2);ctx.fill();
      ctx.shadowBlur=0;
      ctx.fillStyle='#fff';ctx.textAlign='center';ctx.textBaseline='middle';ctx.font='700 11px system-ui';
      ctx.fillText((n.role||'flow').slice(0,4).toUpperCase(),n.x,n.y);

      const label=String(n.name||n.id);
      ctx.font='700 12px system-ui';
      const tw=Math.min(220,ctx.measureText(label).width+18);
      roundedRect(n.x-tw/2,n.y+n.radius+8,tw,27,8);
      const dark=document.documentElement.dataset.theme==='dark';
      ctx.fillStyle=dark?'rgba(19,29,43,.96)':'rgba(255,255,255,.96)';ctx.fill();
      ctx.strokeStyle=dark?'#26364b':'#dfe6ef';ctx.stroke();
      ctx.fillStyle=dark?'#edf2f7':'#162033';
      const short=label.length>28?label.slice(0,26)+'…':label;
      ctx.fillText(short,n.x,n.y+n.radius+21.5);
    }
    ctx.restore();
  }

  function loop() {
    physics(); draw();
    raf=requestAnimationFrame(loop);
  }

  canvas.addEventListener('mousedown',e=>{
    const n=hitTest(e.clientX,e.clientY);
    lastX=e.clientX;lastY=e.clientY;
    if(n){draggingNode=n;canvas.style.cursor='grabbing';}
    else{draggingCanvas=true;canvas.style.cursor='grabbing';}
  });
  window.addEventListener('mousemove',e=>{
    if(draggingNode){const p=graphPoint(e.clientX,e.clientY);draggingNode.x=p.x;draggingNode.y=p.y;draggingNode.vx=draggingNode.vy=0;}
    else if(draggingCanvas){panX+=e.clientX-lastX;panY+=e.clientY-lastY;lastX=e.clientX;lastY=e.clientY;}
    else{hovered=hitTest(e.clientX,e.clientY);canvas.style.cursor=hovered?'pointer':'grab';}
  });
  window.addEventListener('mouseup',()=>{draggingNode=null;draggingCanvas=false;canvas.style.cursor=hovered?'pointer':'grab';});
  canvas.addEventListener('dblclick',e=>{const n=hitTest(e.clientX,e.clientY);if(n)location.href=`/processos/${encodeURIComponent(n.id)}/editor`;});
  canvas.addEventListener('wheel',e=>{
    e.preventDefault();
    const before=graphPoint(e.clientX,e.clientY);
    const factor=e.deltaY<0?1.1:.9;
    zoom=Math.max(.35,Math.min(2.5,zoom*factor));
    const rect=canvas.getBoundingClientRect();
    panX=(e.clientX-rect.left)-before.x*zoom;
    panY=(e.clientY-rect.top)-before.y*zoom;
  },{passive:false});

  async function init(){
    resize();
    try{
      const r=await fetch(url,{headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}});
      if(!r.ok)throw new Error(`HTTP ${r.status}`);
      const data=await r.json();nodes=(data.nodes||[]).map(x=>({...x}));links=data.links||[];broken=data.broken||[];
      seedPositions();
      if(raf)cancelAnimationFrame(raf);loop();
    }catch(err){
      ctx.fillStyle='#b42318';ctx.font='600 14px system-ui';ctx.fillText(`Falha ao carregar o mapa: ${err.message}`,20,40);
    }
  }

  window.addEventListener('resize',resize);
  new MutationObserver(draw).observe(document.documentElement,{attributes:true,attributeFilter:['data-theme']});
  init();
})();
