(() => {
  'use strict';

  const boot = window.FLUXOS_BOOT;
  if (!boot) return;

  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const stage = document.getElementById('editorStage');
  const world = document.getElementById('flowWorld');
  const lanesLayer = document.getElementById('lanesLayer');
  const nodesLayer = document.getElementById('nodesLayer');
  const edgeLayer = document.getElementById('edgeLayer');
  const statusEl = document.getElementById('editorStatus');

  let doc = structuredClone(boot.document || {});
  let revision = Number(boot.revision || 1);
  let selectedNodeId = null;
  let selectedEdgeId = null;
  let connectSource = null;
  let dirty = false;
  let scale = 1;
  let drag = null;

  const $ = id => document.getElementById(id);
  const uid = prefix => `${prefix}_${crypto.randomUUID().replaceAll('-','').slice(0,10)}`;
  const nodeById = id => (doc.nodes || []).find(n => n.id === id);
  const edgeById = id => (doc.edges || []).find(e => e.id === id);
  const laneById = id => (doc.lanes || []).find(l => l.id === id);

  function markDirty(message='Alterações não salvas') {
    dirty = true;
    statusEl.textContent = message;
    statusEl.classList.add('dirty');
    statusEl.classList.remove('saved');
  }
  function markSaved(message='Salvo') {
    dirty = false;
    statusEl.textContent = message;
    statusEl.classList.remove('dirty');
    statusEl.classList.add('saved');
    setTimeout(() => {
      if (!dirty) {
        statusEl.textContent = 'Sem alterações';
        statusEl.classList.remove('saved');
      }
    }, 2500);
  }
  function notify(message, type='info') {
    statusEl.textContent = message;
    statusEl.classList.toggle('dirty', type === 'error');
    statusEl.classList.toggle('saved', type === 'success');
  }

  async function api(url, method='POST', body=null) {
    const options = {
      method,
      headers: {'Accept':'application/json','X-CSRF-TOKEN':csrf,'X-Requested-With':'XMLHttpRequest'}
    };
    if (body !== null) {
      options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(body);
    }
    const response = await fetch(url, options);
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      const error = new Error(data.message || data.error || `HTTP ${response.status}`);
      error.response = response;
      error.data = data;
      throw error;
    }
    return data;
  }

  function laneLayout() {
    let y = 20;
    const map = new Map();
    [...(doc.lanes || [])].sort((a,b)=>(a.order||0)-(b.order||0)).forEach((lane, i) => {
      const h = Math.max(110, Math.min(1600, Number(lane.height || 240)));
      map.set(lane.id, {y, h, order:i});
      y += h + 18;
    });
    return {map, total:y+100};
  }

  function ensureNodeInLane(node) {
    if (!node.laneId || !laneById(node.laneId)) node.laneId = doc.lanes?.[0]?.id || null;
  }

  function render() {
    const layout = laneLayout();
    world.style.transform = `scale(${scale})`;
    world.style.width = '2200px';
    world.style.height = `${Math.max(1600, layout.total)}px`;

    lanesLayer.innerHTML = '';
    for (const lane of doc.lanes || []) {
      const p = layout.map.get(lane.id);
      const el = document.createElement('div');
      el.className = 'lane';
      el.style.top = `${p.y}px`;
      el.style.height = `${p.h}px`;
      el.style.background = `color-mix(in srgb, ${lane.color || '#E8F5F0'} 22%, transparent)`;
      el.innerHTML = `<div class="lane-title">${escapeHtml(lane.name || 'Raia')} · ${escapeHtml(lane.owner || '')}</div>`;
      lanesLayer.appendChild(el);
    }

    nodesLayer.innerHTML = '';
    for (const node of doc.nodes || []) {
      ensureNodeInLane(node);
      const el = document.createElement('div');
      el.className = `node${selectedNodeId === node.id ? ' selected' : ''}`;
      el.dataset.nodeId = node.id;
      el.dataset.type = node.type || 'task';
      el.style.left = `${Number(node.position?.x || 0)}px`;
      el.style.top = `${Number(node.position?.y || 0)}px`;
      el.innerHTML = `
        <div class="node-type">${escapeHtml(node.type || 'task')}</div>
        <div class="node-label">${escapeHtml(node.data?.label || node.id)}</div>
        <div class="node-owner">${escapeHtml(node.data?.owner || 'Sem responsável')}</div>`;
      el.addEventListener('mousedown', startDrag);
      el.addEventListener('click', e => {
        e.stopPropagation();
        selectNode(node.id);
      });
      nodesLayer.appendChild(el);
    }

    renderEdges();
    refreshInspector();
  }

  function renderEdges() {
    edgeLayer.innerHTML = `
      <defs>
        <marker id="arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
          <path d="M 0 0 L 10 5 L 0 10 z" fill="#7c8ca1"></path>
        </marker>
      </defs>`;
    for (const edge of doc.edges || []) {
      const source = nodeById(edge.source);
      const target = nodeById(edge.target);
      if (!source || !target) continue;
      const x1 = Number(source.position?.x || 0) + 190;
      const y1 = Number(source.position?.y || 0) + 40;
      const x2 = Number(target.position?.x || 0);
      const y2 = Number(target.position?.y || 0) + 40;
      const mid = Math.max(50, Math.abs(x2-x1)*0.45);
      const d = `M ${x1} ${y1} C ${x1+mid} ${y1}, ${x2-mid} ${y2}, ${x2} ${y2}`;

      const path = document.createElementNS('http://www.w3.org/2000/svg','path');
      path.setAttribute('d',d);
      path.setAttribute('marker-end','url(#arrow)');
      path.style.pointerEvents = 'stroke';
      path.style.cursor = 'pointer';
      path.style.stroke = selectedEdgeId === edge.id ? 'var(--primary)' : edgeColor(edge);
      path.style.strokeWidth = selectedEdgeId === edge.id ? '4' : '2';
      path.addEventListener('click', e => {e.stopPropagation(); selectEdge(edge.id);});
      edgeLayer.appendChild(path);

      const label = edge.label || edge.condition || '';
      if (label) {
        const text = document.createElementNS('http://www.w3.org/2000/svg','text');
        text.textContent = label;
        text.setAttribute('x', String((x1+x2)/2));
        text.setAttribute('y', String((y1+y2)/2 - 7));
        text.setAttribute('text-anchor','middle');
        edgeLayer.appendChild(text);
      }
    }
  }

  function edgeColor(edge) {
    const source = nodeById(edge.source);
    if (source?.type === 'decision') {
      const text = `${edge.label || ''} ${edge.condition || ''}`.toLowerCase();
      if (/\b(sim|yes|aprov|ok|verdadeiro)\b/.test(text)) return '#12b76a';
      if (/\b(não|nao|no|reprov|falso)\b/.test(text)) return '#f04438';
    }
    return '#7c8ca1';
  }

  function startDrag(e) {
    if (e.button !== 0) return;
    const id = e.currentTarget.dataset.nodeId;
    const node = nodeById(id);
    if (!node || node.data?.locked) return;
    selectNode(id);
    drag = {id, startX:e.clientX, startY:e.clientY, x:Number(node.position.x||0), y:Number(node.position.y||0)};
    e.preventDefault();
  }
  window.addEventListener('mousemove', e => {
    if (!drag) return;
    const node = nodeById(drag.id);
    if (!node) return;
    const grid = doc.settings?.snapToGrid ? Number(doc.settings?.gridSize || 20) : 1;
    let x = drag.x + (e.clientX-drag.startX)/scale;
    let y = drag.y + (e.clientY-drag.startY)/scale;
    if (grid > 1) {x = Math.round(x/grid)*grid; y = Math.round(y/grid)*grid;}
    node.position = {x:Math.max(0,x),y:Math.max(0,y)};
    markDirty();
    render();
  });
  window.addEventListener('mouseup',()=>drag=null);

  function selectNode(id) {
    selectedNodeId = id;
    selectedEdgeId = null;
    render();
  }
  function selectEdge(id) {
    selectedEdgeId = id;
    selectedNodeId = null;
    render();
  }
  stage.addEventListener('click', e => {
    if (e.target === stage || e.target === world || e.target === lanesLayer || e.target === nodesLayer || e.target === edgeLayer) {
      selectedNodeId = null; selectedEdgeId = null; render();
    }
  });

  function refreshInspector() {
    $('flowName').value = doc.flow?.name || '';
    $('flowDescription').value = doc.flow?.description || '';

    const node = selectedNodeId ? nodeById(selectedNodeId) : null;
    $('nodeInspector').hidden = !node;
    $('nodeInspectorEmpty').hidden = !!node;
    if (node) {
      $('nodeType').value = node.type || 'task';
      $('nodeLabel').value = node.data?.label || '';
      $('nodeDescription').value = node.data?.description || '';
      $('nodeOwner').value = node.data?.owner || '';
      $('nodeCriticality').value = node.data?.criticality || 'medium';
      $('nodeTags').value = (node.data?.tags || []).join(', ');
      $('nodeLinkedFlow').value = node.data?.linkedFlowId || '';
      $('nodeLinkedEntry').value = node.data?.linkedFlowEntryNodeId || '';
      $('nodeLinkedExit').value = node.data?.linkedFlowExitNodeId || '';
      $('nodeLane').innerHTML = (doc.lanes || []).map(l=>`<option value="${attr(l.id)}"${l.id===node.laneId?' selected':''}>${escapeHtml(l.name)}</option>`).join('');
    }

    const edge = selectedEdgeId ? edgeById(selectedEdgeId) : null;
    $('edgeInspector').hidden = !edge;
    $('edgeInspectorEmpty').hidden = !!edge;
    if (edge) {
      $('edgeLabel').value = edge.label || '';
      $('edgeCondition').value = edge.condition || '';
    }
  }

  function bindValue(id, apply) {
    $(id)?.addEventListener('input', e => {apply(e.target.value); markDirty(); render();});
  }
  bindValue('flowName',v=>doc.flow.name=v);
  bindValue('flowDescription',v=>doc.flow.description=v);
  bindValue('nodeType',v=>{const n=nodeById(selectedNodeId);if(n)n.type=v});
  bindValue('nodeLabel',v=>{const n=nodeById(selectedNodeId);if(n)n.data.label=v});
  bindValue('nodeDescription',v=>{const n=nodeById(selectedNodeId);if(n)n.data.description=v});
  bindValue('nodeOwner',v=>{const n=nodeById(selectedNodeId);if(n)n.data.owner=v});
  bindValue('nodeLane',v=>{const n=nodeById(selectedNodeId);if(n)n.laneId=v});
  bindValue('nodeCriticality',v=>{const n=nodeById(selectedNodeId);if(n)n.data.criticality=v});
  bindValue('nodeTags',v=>{const n=nodeById(selectedNodeId);if(n)n.data.tags=v.split(',').map(x=>x.trim()).filter(Boolean)});
  bindValue('nodeLinkedFlow',v=>{const n=nodeById(selectedNodeId);if(n)n.data.linkedFlowId=v||null});
  bindValue('nodeLinkedEntry',v=>{const n=nodeById(selectedNodeId);if(n)n.data.linkedFlowEntryNodeId=v||null});
  bindValue('nodeLinkedExit',v=>{const n=nodeById(selectedNodeId);if(n)n.data.linkedFlowExitNodeId=v||null});
  bindValue('edgeLabel',v=>{const e=edgeById(selectedEdgeId);if(e)e.label=v});
  bindValue('edgeCondition',v=>{const e=edgeById(selectedEdgeId);if(e)e.condition=v});

  $('addNodeBtn').addEventListener('click',()=>{
    const type=$('nodeTypeSelect').value;
    const lane=doc.lanes?.[0];
    const layout=laneLayout().map.get(lane?.id);
    const x=(stage.scrollLeft + 280)/scale;
    const y=Math.max((layout?.y||20)+60,(stage.scrollTop + 160)/scale);
    const id=uid('node');
    doc.nodes.push({
      id,type,laneId:lane?.id||null,position:{x,y},
      data:{label:type==='task'?'Nova atividade':type.charAt(0).toUpperCase()+type.slice(1),description:'',owner:'',enabled:true,locked:false,slaMinutes:null,tags:[],level:type==='api'?'technical':'operational',category:'process',criticality:'medium',linkedFlowId:null,linkedFlowEntryNodeId:null,linkedFlowExitNodeId:null,preferredEdgeId:null,documentationUrl:'',raci:{responsible:'',accountable:'',consulted:[],informed:[]}}
    });
    selectedNodeId=id;selectedEdgeId=null;markDirty();render();
  });

  $('addLaneBtn').addEventListener('click',()=>{
    const order=(doc.lanes?.length||0)+1;
    doc.lanes.push({id:uid('lane'),name:`Raia ${order}`,owner:'',orientation:'horizontal',order,color:'#EAF4FF',collapsed:false,enabled:true,height:260});
    markDirty();render();
  });

  $('connectBtn').addEventListener('click',()=>{
    if (!selectedNodeId) {notify('Selecione um card primeiro.','error');return;}
    if (!connectSource) {
      connectSource=selectedNodeId;
      notify(`Origem selecionada: ${nodeById(connectSource)?.data?.label}. Agora selecione o destino e clique em Conectar.`);
      return;
    }
    if (connectSource===selectedNodeId) {notify('Origem e destino não podem ser o mesmo card.','error');return;}
    const source=nodeById(connectSource);
    const branch=(doc.edges||[]).filter(e=>e.source===connectSource).length;
    doc.edges.push({
      id:uid('edge'),source:connectSource,target:selectedNodeId,
      sourceHandle:source?.type==='decision'?`branch-${branch}`:'output',targetHandle:'input',
      type:'step',label:'',condition:'',enabled:true
    });
    connectSource=null;markDirty();render();
  });

  $('deleteSelectedBtn').addEventListener('click',()=>{
    if (selectedNodeId) {
      const id=selectedNodeId;
      doc.nodes=doc.nodes.filter(n=>n.id!==id);
      doc.edges=doc.edges.filter(e=>e.source!==id&&e.target!==id);
      selectedNodeId=null;markDirty();render();return;
    }
    if (selectedEdgeId) {
      doc.edges=doc.edges.filter(e=>e.id!==selectedEdgeId);selectedEdgeId=null;markDirty();render();
    }
  });
  $('deleteEdgeBtn').addEventListener('click',()=>{
    if(!selectedEdgeId)return;doc.edges=doc.edges.filter(e=>e.id!==selectedEdgeId);selectedEdgeId=null;markDirty();render();
  });

  $('fitBtn').addEventListener('click',()=>{
    const nodes=doc.nodes||[];
    if(!nodes.length){scale=1;stage.scrollTo(0,0);render();return;}
    const minX=Math.min(...nodes.map(n=>Number(n.position.x||0)));
    const maxX=Math.max(...nodes.map(n=>Number(n.position.x||0)+190));
    const minY=Math.min(...nodes.map(n=>Number(n.position.y||0)));
    const maxY=Math.max(...nodes.map(n=>Number(n.position.y||0)+100));
    const sx=(stage.clientWidth-80)/Math.max(300,maxX-minX);
    const sy=(stage.clientHeight-80)/Math.max(200,maxY-minY);
    scale=Math.max(.35,Math.min(1.25,Math.min(sx,sy)));
    render();
    stage.scrollTo({left:Math.max(0,minX*scale-30),top:Math.max(0,minY*scale-30),behavior:'smooth'});
  });

  stage.addEventListener('wheel',e=>{
    if(!e.ctrlKey)return;
    e.preventDefault();
    scale=Math.max(.35,Math.min(1.6,scale+(e.deltaY<0?.08:-.08)));
    render();
  },{passive:false});

  $('exportBtn').addEventListener('click',()=>{
    const blob=new Blob([JSON.stringify(doc,null,2)],{type:'application/json'});
    const a=document.createElement('a');a.href=URL.createObjectURL(blob);
    a.download=`${(doc.flow?.name||'fluxo').replace(/[^\w.-]+/g,'_')}.json`;
    a.click();URL.revokeObjectURL(a.href);
  });

  $('importInput').addEventListener('change',async e=>{
    const file=e.target.files?.[0];if(!file)return;
    try{
      const raw=JSON.parse(await file.text());
      const result=await api(boot.urls.import,'POST',{document:raw});
      if(result.errors?.length){alert('O arquivo ainda possui erros:\n'+result.errors.join('\n'));return;}
      doc=result.document;
      markDirty(result.warnings?.length?`Importado com ${result.warnings.length} correção(ões)`:'Importado. Salve para persistir.');
      render();
      if(result.warnings?.length)alert(result.warnings.join('\n'));
    }catch(err){alert('Falha ao importar: '+err.message)}
    finally{e.target.value=''}
  });

  $('saveDraftBtn').addEventListener('click',async()=>{
    try{
      notify('Salvando rascunho...');
      await api(boot.urls.draft,'POST',{document:doc,base_revision:revision});
      markSaved('Rascunho salvo no MongoDB');
    }catch(err){notify('Falha ao salvar rascunho: '+err.message,'error')}
  });

  $('loadDraftBtn')?.addEventListener('click',()=>{
    if(!boot.draft)return;
    doc=structuredClone(boot.draft);
    revision=Number(boot.draftBaseRevision||revision);
    markDirty('Rascunho carregado; salve uma versão para consolidar.');
    render();
  });

  $('discardDraftBtn')?.addEventListener('click',async()=>{
    if(!confirm('Descartar o rascunho salvo no MongoDB?'))return;
    try{await api(boot.urls.discardDraft,'DELETE');notify('Rascunho descartado.','success');$('loadDraftBtn')?.remove();$('discardDraftBtn')?.remove()}
    catch(err){notify('Falha ao descartar rascunho: '+err.message,'error')}
  });

  $('saveBtn').addEventListener('click',async()=>{
    try{
      notify('Validando e salvando...');
      const result=await api(boot.urls.save,'POST',{document:doc,revision,reason:'manual'});
      revision=Number(result.flow.revision||revision+1);
      doc=structuredClone(result.flow.document||doc);
      markSaved(`Versão salva · rev ${revision} · qualidade ${result.analysis?.quality_score ?? '—'}%`);
      render();
    }catch(err){
      if(err.response?.status===409){
        const current=err.data?.current_revision;
        notify(`Conflito de revisão. O servidor está na rev ${current}. Recarregue antes de sobrescrever.`,'error');
        alert(`Conflito de edição: outro usuário salvou o fluxo. Revisão atual no servidor: ${current}. Suas alterações continuam abertas nesta tela para exportação ou comparação.`);
      }else{
        const validation=err.data?.errors?Object.values(err.data.errors).flat().join('\n'):'';
        notify('Falha ao salvar: '+(validation||err.message),'error');
        if(validation)alert(validation);
      }
    }
  });

  document.querySelectorAll('[data-transition]').forEach(btn=>btn.addEventListener('click',async()=>{
    if(dirty){alert('Salve ou descarte suas alterações do editor antes de alterar a governança.');return;}
    try{
      const result=await api(boot.urls.transition,'POST',{action:btn.dataset.transition,comment:$('governanceComment').value});
      alert(`Status alterado: ${result.transition.from} → ${result.transition.to}`);
      location.reload();
    }catch(err){alert('Não foi possível alterar o status: '+err.message)}
  }));

  $('addCommentBtn').addEventListener('click',async()=>{
    const content=$('newComment').value.trim();if(!content)return;
    try{
      const targetKind=selectedNodeId?'node':(selectedEdgeId?'edge':'flow');
      const targetId=selectedNodeId||selectedEdgeId||boot.flowId;
      const result=await api(boot.urls.comment,'POST',{content,target_kind:targetKind,target_id:targetId});
      $('newComment').value='';
      const c=result.comment;
      const el=document.createElement('div');
      el.innerHTML=`<strong>@${escapeHtml(c.author)}</strong><br><span>${escapeHtml(c.content)}</span><br><span class="muted">agora · ${escapeHtml(c.target_kind)} ${escapeHtml(c.target_id||'')}</span>`;
      $('commentsList').prepend(el);
    }catch(err){alert('Falha ao adicionar comentário: '+err.message)}
  });

  window.addEventListener('beforeunload',e=>{
    if(!dirty)return;
    e.preventDefault();
    e.returnValue='';
  });

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
  }
  function attr(value) {return escapeHtml(value).replace(/`/g,'&#096;')}

  render();
})();