<?php

namespace App\Services;

use Illuminate\Support\Str;
use SplQueue;

class FlowDocumentService
{
    public const SCHEMA_VERSION = '2.0.0';
    public const NODE_TYPES = ['start','end','task','decision','subprocess','event','wait','document','api','note'];
    public const STATUSES = ['draft','in_review','approved','published','archived'];
    public const EXCEPTION_WORDS = [
        'erro','falha','recus','cancel','bloque','expir','indispon','pendente','corrigir','reprocess','exceção','excecao'
    ];

    public function newDocument(string $name = 'Novo processo', string $owner = ''): array
    {
        $id = 'flow_'.Str::lower(Str::random(12));
        $now = now()->toIso8601String();
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'flow' => [
                'id'=>$id,
                'name'=>trim($name) ?: 'Novo processo',
                'description'=>'',
                'status'=>'draft',
                'orientation'=>'LR',
                'createdAt'=>$now,
                'updatedAt'=>$now,
                'createdBy'=>$owner,
                'tags'=>[],
            ],
            'settings' => [
                'snapToGrid'=>true,
                'gridSize'=>20,
                'autoLayout'=>false,
                'showMiniMap'=>true,
                'showGrid'=>true,
                'layoutPreset'=>'readable',
                'edgeRouting'=>'smooth',
                'interactivePlayback'=>true,
                'autoFitLanes'=>true,
            ],
            'viewport'=>['x'=>0,'y'=>0,'zoom'=>1],
            'lanes'=>[[
                'id'=>'lane_process','name'=>'Processo','owner'=>'','orientation'=>'horizontal','order'=>1,
                'color'=>'#E8F5F0','collapsed'=>false,'enabled'=>true,'height'=>260,
            ]],
            'nodes'=>[],
            'edges'=>[],
        ];
    }

    private function cleanNewDocument(array $document): array
    {
        // Mantém newDocument legível sem duplicar uma estrutura grande dentro do return acima.
        $document['lanes'] = [[
            'id'=>'lane_process',
            'name'=>'Processo',
            'owner'=>'',
            'orientation'=>'horizontal',
            'order'=>1,
            'color'=>'#E8F5F0',
            'collapsed'=>false,
            'enabled'=>true,
            'height'=>260,
        ]];
        return $document;
    }

    public function demoDocument(string $owner = ''): array
    {
        $doc = $this->cleanNewDocument($this->newDocument('Fluxo de exemplo', $owner));
        $doc['lanes'] = [
            ['id'=>'lane_comercial','name'=>'Comercial','owner'=>'Comercial','orientation'=>'horizontal','order'=>1,'color'=>'#E8F5F0','collapsed'=>false,'enabled'=>true,'height'=>240],
            ['id'=>'lane_operacao','name'=>'Operação','owner'=>'Operação','orientation'=>'horizontal','order'=>2,'color'=>'#EAF4FF','collapsed'=>false,'enabled'=>true,'height'=>240],
        ];
        $doc['nodes'] = [
            $this->node('node_start','start','lane_comercial',80,78,'Solicitação recebida','Comercial','executive'),
            $this->node('node_analyze','task','lane_comercial',330,70,'Analisar solicitação','Comercial','operational',['slaMinutes'=>60]),
            $this->node('node_decision','decision','lane_comercial',610,65,'Dados completos?','Comercial','operational'),
            $this->node('node_reject','end','lane_comercial',890,145,'Solicitação devolvida','Comercial','operational',['tags'=>['exceção']]),
            $this->node('node_execute','subprocess','lane_operacao',890,315,'Executar processo','Operação','executive'),
            $this->node('node_end','end','lane_operacao',1180,325,'Processo concluído','Operação','executive'),
        ];
        $doc['edges'] = [
            ['id'=>'edge_1','source'=>'node_start','target'=>'node_analyze','sourceHandle'=>'output','targetHandle'=>'input','type'=>'step','label'=>'','enabled'=>true,'condition'=>''],
            ['id'=>'edge_2','source'=>'node_analyze','target'=>'node_decision','sourceHandle'=>'output','targetHandle'=>'input','type'=>'step','label'=>'','enabled'=>true,'condition'=>''],
            ['id'=>'edge_3','source'=>'node_decision','target'=>'node_execute','sourceHandle'=>'branch-0','targetHandle'=>'input','type'=>'step','label'=>'Sim','enabled'=>true,'condition'=>'Dados completos'],
            ['id'=>'edge_4','source'=>'node_decision','target'=>'node_reject','sourceHandle'=>'branch-1','targetHandle'=>'input','type'=>'step','label'=>'Não','enabled'=>true,'condition'=>'Dados incompletos'],
            ['id'=>'edge_5','source'=>'node_execute','target'=>'node_end','sourceHandle'=>'output','targetHandle'=>'input','type'=>'step','label'=>'','enabled'=>true,'condition'=>''],
        ];
        return $this->normalize($doc, $owner);
    }

    private function node(string $id,string $type,string $lane,float $x,float $y,string $label,string $owner,string $level,array $extra=[]): array
    {
        return [
            'id'=>$id,
            'type'=>$type,
            'laneId'=>$lane,
            'position'=>['x'=>$x,'y'=>$y],
            'data'=>array_merge($this->defaultNodeData($label),['owner'=>$owner,'level'=>$level],$extra),
        ];
    }

    private function defaultNodeData(string $label): array
    {
        return [
            'label'=>$label,
            'description'=>'',
            'owner'=>'',
            'enabled'=>true,
            'locked'=>false,
            'slaMinutes'=>null,
            'tags'=>[],
            'level'=>'operational',
            'category'=>'process',
            'criticality'=>'medium',
            'linkedFlowId'=>null,
            'linkedFlowEntryNodeId'=>null,
            'linkedFlowExitNodeId'=>null,
            'preferredEdgeId'=>null,
            'documentationUrl'=>'',
            'raci'=>['responsible'=>'','accountable'=>'','consulted'=>[],'informed'=>[]],
        ];
    }

    public function normalize(array $document, string $owner=''): array
    {
        $document['schemaVersion']=self::SCHEMA_VERSION;
        $flow=is_array($document['flow']??null)?$document['flow']:[];
        $flow['id']=trim((string)($flow['id']??'')) ?: 'flow_'.Str::lower(Str::random(12));
        $flow['name']=trim((string)($flow['name']??'')) ?: 'Processo importado';
        $flow['description']=(string)($flow['description']??'');
        $status=(string)($flow['status']??'draft');
        $flow['status']=$status==='active'?'published':(in_array($status,self::STATUSES,true)?$status:'draft');
        $orientation=(string)($flow['orientation']??'LR');
        $flow['orientation']=in_array($orientation,['LR','RL'],true)?$orientation:'LR';
        $flow['createdAt']=$flow['createdAt']??now()->toIso8601String();
        $flow['updatedAt']=now()->toIso8601String();
        $flow['createdBy']=$flow['createdBy']??$owner;
        $flow['tags']=array_values(array_slice(array_filter(array_map(fn($v)=>trim((string)$v),(array)($flow['tags']??[]))),0,30));
        $document['flow']=$flow;

        $settings=is_array($document['settings']??null)?$document['settings']:[];
        $settings=array_merge([
            'snapToGrid'=>true,
            'gridSize'=>20,
            'autoLayout'=>false,
            'showMiniMap'=>true,
            'showGrid'=>true,
            'layoutPreset'=>'readable',
            'edgeRouting'=>'smooth',
            'interactivePlayback'=>true,
            'autoFitLanes'=>true,
        ],$settings);
        unset($settings['autosaveSeconds']);
        $presetAliases=['compact-readable-v2'=>'compact','compact-readable'=>'compact','balanced'=>'readable','legible'=>'readable'];
        $settings['layoutPreset']=$presetAliases[(string)$settings['layoutPreset']]??(string)$settings['layoutPreset'];
        if(!in_array($settings['layoutPreset'],['compact','readable','preserve'],true))$settings['layoutPreset']='readable';
        $routingAliases=['step'=>'orthogonal','smoothstep'=>'smooth','bezier'=>'smooth','curve'=>'smooth'];
        $routing=strtolower((string)($settings['edgeRouting']??'smooth'));
        $settings['edgeRouting']=$routingAliases[$routing]??$routing;
        if(!in_array($settings['edgeRouting'],['corridor','corridor-v2','orthogonal','smooth','straight'],true))$settings['edgeRouting']='smooth';
        $settings['gridSize']=max(5,min(100,(int)($settings['gridSize']??20)));
        foreach(['snapToGrid','autoLayout','showMiniMap','showGrid','interactivePlayback','autoFitLanes'] as $key)$settings[$key]=($settings[$key]??false)===true;
        $document['settings']=$settings;

        $viewport=is_array($document['viewport']??null)?$document['viewport']:[];
        $document['viewport']=[
            'x'=>(float)($viewport['x']??0),
            'y'=>(float)($viewport['y']??0),
            'zoom'=>max(.04,min(2.2,(float)($viewport['zoom']??1))),
        ];

        $lanes=[];
        foreach((array)($document['lanes']??[]) as $i=>$lane){
            if(!is_array($lane))continue;
            $lane['id']=trim((string)($lane['id']??'')) ?: 'lane_'.Str::lower(Str::random(8));
            $lane['name']=trim((string)($lane['name']??'')) ?: 'Raia '.($i+1);
            $lane['owner']=(string)($lane['owner']??'');
            $lane['orientation']='horizontal';
            $lane['order']=(int)($lane['order']??$i+1);
            $lane['color']=$this->normalizeColor((string)($lane['color']??'#E8F5F0'));
            $lane['collapsed']=($lane['collapsed']??false)===true;
            $lane['enabled']=($lane['enabled']??true)!==false;
            $lane['height']=max(110,min(2400,(int)($lane['height']??240)));
            $lanes[]=$lane;
        }
        if(!$lanes)$lanes=$this->cleanNewDocument($this->newDocument('Novo processo',$owner))['lanes'];
        usort($lanes,fn($a,$b)=>((int)$a['order'])<=>((int)$b['order']));
        foreach($lanes as $i=>&$lane)$lane['order']=$i+1;
        unset($lane);
        $document['lanes']=$lanes;
        $laneIds=array_fill_keys(array_map(fn($l)=>(string)$l['id'],$lanes),true);

        $nodes=[];
        foreach((array)($document['nodes']??[]) as $node){
            if(!is_array($node))continue;
            $node['id']=trim((string)($node['id']??'')) ?: 'node_'.Str::lower(Str::random(10));
            $node['type']=in_array(($node['type']??'task'),self::NODE_TYPES,true)?$node['type']:'task';
            $laneId=(string)($node['laneId']??'');
            $node['laneId']=$laneId!==''&&isset($laneIds[$laneId])?$laneId:($lanes[0]['id']??null);
            $pos=is_array($node['position']??null)?$node['position']:[];
            $node['position']=['x'=>(float)($pos['x']??0),'y'=>(float)($pos['y']??0)];
            $data=is_array($node['data']??null)?$node['data']:[];
            $data=array_merge($this->defaultNodeData(ucfirst($node['type'])),$data);
            $data['label']=trim((string)$data['label']) ?: ucfirst($node['type']);
            $data['description']=(string)($data['description']??'');
            $data['owner']=(string)($data['owner']??'');
            $data['enabled']=$data['enabled']!==false;
            $data['locked']=$data['locked']===true;
            $data['slaMinutes']=is_numeric($data['slaMinutes']??null)?max(0,(float)$data['slaMinutes']):null;
            $data['tags']=array_values(array_slice(array_filter(array_map(fn($v)=>trim((string)$v),(array)($data['tags']??[]))),0,30));
            if(!in_array($data['level'],['executive','operational','technical'],true))$data['level']=$node['type']==='api'?'technical':'operational';
            $data['category']=trim((string)($data['category']??'')) ?: ($node['type']==='api'?'integration':'process');
            if(!in_array($data['criticality'],['low','medium','high','critical'],true))$data['criticality']='medium';
            foreach(['linkedFlowId','linkedFlowEntryNodeId','linkedFlowExitNodeId','preferredEdgeId'] as $key){
                $value=trim((string)($data[$key]??''));
                $data[$key]=$value!==''?$value:null;
            }
            $data['documentationUrl']=trim((string)($data['documentationUrl']??''));
            $raci=is_array($data['raci']??null)?$data['raci']:[];
            $data['raci']=[
                'responsible'=>(string)($raci['responsible']??''),
                'accountable'=>(string)($raci['accountable']??''),
                'consulted'=>array_values(array_filter(array_map(fn($v)=>trim((string)$v),(array)($raci['consulted']??[])))),
                'informed'=>array_values(array_filter(array_map(fn($v)=>trim((string)$v),(array)($raci['informed']??[])))),
            ];
            $node['data']=$data;
            $nodes[]=$node;
        }
        $document['nodes']=$nodes;

        $edges=[];$outgoing=[];
        foreach((array)($document['edges']??[]) as $edge){
            if(!is_array($edge))continue;
            $edge['id']=trim((string)($edge['id']??'')) ?: 'edge_'.Str::lower(Str::random(10));
            $edge['source']=(string)($edge['source']??'');
            $edge['target']=(string)($edge['target']??'');
            $edge['type']=(string)($edge['type']??'step');
            $edge['label']=(string)($edge['label']??'');
            $edge['condition']=(string)($edge['condition']??'');
            $edge['enabled']=($edge['enabled']??true)!==false;
            $edge['targetHandle']=(string)($edge['targetHandle']??'input');
            $outgoing[$edge['source']][]=count($edges);
            $edges[]=$edge;
        }
        $byId=[];foreach($nodes as $node)$byId[(string)$node['id']]=$node;
        foreach($outgoing as $source=>$indexes){
            $decision=($byId[$source]['type']??'')==='decision';
            foreach($indexes as $branch=>$idx){
                $current=(string)($edges[$idx]['sourceHandle']??'');
                if($decision)$edges[$idx]['sourceHandle']=preg_match('/^branch-\d+$/',$current)?$current:'branch-'.$branch;
                else $edges[$idx]['sourceHandle']=$current!==''?$current:'output';
            }
        }
        $document['edges']=$edges;
        return $document;
    }

    private function normalizeColor(string $value): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/',$value)?strtoupper($value):'#E8F5F0';
    }

    public function validate(array $document): array
    {
        $errors=[];
        foreach(['schemaVersion','flow','settings','viewport','lanes','nodes','edges'] as $required){
            if(!array_key_exists($required,$document))$errors[]="Campo obrigatório ausente: {$required}";
        }
        $flow=$document['flow']??[];
        if(!is_array($flow)||trim((string)($flow['id']??''))==='')$errors[]='Fluxo sem identificador.';
        if(!is_array($flow)||trim((string)($flow['name']??''))==='')$errors[]='Fluxo sem nome.';

        $laneIds=[];
        foreach((array)($document['lanes']??[]) as $lane){
            $id=trim((string)($lane['id']??''));
            if($id==='')$errors[]='Existe raia sem ID.';
            elseif(isset($laneIds[$id]))$errors[]="ID de raia duplicado: {$id}";
            else $laneIds[$id]=true;
        }

        $nodeIds=[];$outgoing=[];$edgeIds=[];
        foreach((array)($document['nodes']??[]) as $node){
            $id=(string)($node['id']??'');
            if($id==='')$errors[]='Existe card sem ID.';
            if(isset($nodeIds[$id]))$errors[]="ID de card duplicado: {$id}";
            $nodeIds[$id]=true;
            if(!in_array((string)($node['type']??''),self::NODE_TYPES,true))$errors[]="Tipo de card inválido em {$id}.";
            $lane=(string)($node['laneId']??'');
            if($lane!==''&&!isset($laneIds[$lane]))$errors[]="Card {$id} aponta para raia inexistente: {$lane}";
        }
        foreach((array)($document['edges']??[]) as $edge){
            $id=(string)($edge['id']??'');
            if($id==='')$errors[]='Existe conexão sem ID.';
            if(isset($edgeIds[$id]))$errors[]="ID de conexão duplicado: {$id}";
            $edgeIds[$id]=true;
            $source=(string)($edge['source']??'');$target=(string)($edge['target']??'');
            if(!isset($nodeIds[$source]))$errors[]="Conexão {$id} aponta para origem inexistente: {$source}";
            if(!isset($nodeIds[$target]))$errors[]="Conexão {$id} aponta para destino inexistente: {$target}";
            if($source!==''&&$source===$target)$errors[]="Conexão {$id} aponta o card para ele mesmo.";
            if(($edge['enabled']??true)!==false)$outgoing[$source][]=$edge;
        }
        foreach((array)($document['nodes']??[]) as $node){
            if(($node['type']??'')==='decision'&&($node['data']['enabled']??true)!==false){
                $branches=$outgoing[$node['id']]??[];
                if(count($branches)<2)$errors[]="Decisão {$node['id']} precisa de ao menos duas saídas.";
                elseif(collect($branches)->contains(fn($edge)=>trim((string)($edge['label']??$edge['condition']??''))==='')){
                    $errors[]="Decisão {$node['id']} possui saída sem rótulo/condição.";
                }
            }
        }
        return array_values(array_unique($errors));
    }

    public function repairImport(array $document,string $owner=''): array
    {
        $document=$this->normalize($document,$owner);
        $warnings=[];$counts=[];
        foreach($document['edges'] as $edge){
            if(($edge['enabled']??true)!==false)$counts[$edge['source']]=($counts[$edge['source']]??0)+1;
        }
        foreach($document['nodes'] as &$node){
            if($node['type']==='decision'&&($node['data']['enabled']??true)!==false&&($counts[$node['id']]??0)<2){
                $originalCount=$counts[$node['id']]??0;
                $node['type']='task';
                $node['data']['importRepair']='decision_without_branches_converted_to_task';
                $node['data']['tags']=array_values(array_unique([...(array)($node['data']['tags']??[]),'Importação corrigida']));
                $warnings[]="{$node['id']} ({$node['data']['label']}) foi convertido de decisão para atividade porque possuía {$originalCount} saída(s).";
            }
        } unset($node);
        $types=[];foreach($document['nodes'] as $node)$types[$node['id']]=$node['type'];
        foreach($document['edges'] as &$edge){
            if(($types[$edge['source']]??'')!=='decision')$edge['sourceHandle']='output';
        } unset($edge);
        return [$document,$warnings];
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map(fn($item) => $this->canonicalize($item), $value);
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }

    public function hash(array $document): string
    {
        return hash('sha256', json_encode($this->canonicalize($document), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));
    }

    public function analyze(array $document): array
    {
        [$nodes,$nodeMap,$edges,$outgoing,$incoming]=$this->activeGraph($document);
        $lanes=(array)($document['lanes']??[]);
        $starts=array_values(array_map(fn($n)=>(string)$n['id'],array_filter($nodes,fn($n)=>($n['type']??'')==='start')));
        $ends=array_values(array_map(fn($n)=>(string)$n['id'],array_filter($nodes,fn($n)=>($n['type']??'')==='end')));
        $reachable=$starts?$this->reachable($starts,$outgoing):[];
        $inaccessible=[];
        if($starts){foreach($nodes as $n)if(!isset($reachable[(string)$n['id']]))$inaccessible[]=(string)$n['id'];}
        $cycleNodes=$this->cycleNodes($nodes,$outgoing);
        sort($cycleNodes);
        $decisions=array_values(array_filter($nodes,fn($n)=>($n['type']??'')==='decision'));
        $subprocesses=array_values(array_filter($nodes,fn($n)=>($n['type']??'')==='subprocess'));
        $integrations=array_values(array_filter($nodes,fn($n)=>($n['type']??'')==='api'));
        $exceptions=array_values(array_filter($nodes,fn($n)=>$this->isException($n)));
        $laneTransitions=0;
        foreach($edges as $edge){
            if(($nodeMap[$edge['source']]['laneId']??null)!==($nodeMap[$edge['target']]['laneId']??null))$laneTransitions++;
        }
        $seed=$starts ?: ($nodes?[(string)$nodes[0]['id']]:[]);
        $longest=$this->longestAcyclicPath($seed,$outgoing);
        $totalSla=0.0;
        foreach($nodes as $node)$totalSla+=(float)($node['data']['slaMinutes']??0);

        $missingDescription=[];$missingOwner=[];$missingSlaCritical=[];$decisionsInvalid=[];$subprocessUnlinked=[];
        foreach($nodes as $node){
            $id=(string)$node['id'];$data=(array)($node['data']??[]);
            if(trim((string)($data['description']??''))==='')$missingDescription[]=$id;
            if(trim((string)($data['owner']??''))==='')$missingOwner[]=$id;
            if(in_array(($data['criticality']??''),['high','critical'],true)&&empty($data['slaMinutes']))$missingSlaCritical[]=$id;
        }
        foreach($decisions as $node){
            $branches=$outgoing[(string)$node['id']]??[];
            $invalid=count($branches)<2;
            foreach($branches as $edge){
                if(trim((string)($edge['label']??$edge['condition']??''))===''){$invalid=true;break;}
            }
            if($invalid)$decisionsInvalid[]=(string)$node['id'];
        }
        foreach($subprocesses as $node)if(empty($node['data']['linkedFlowId']))$subprocessUnlinked[]=(string)$node['id'];

        $structureScore=100;
        $structureScore-=min(35,count($inaccessible)*4);
        $structureScore-=min(20,count($decisionsInvalid)*5);
        $structureScore-=(!$starts&&$nodes)?10:0;
        $structureScore-=(!$ends&&$nodes)?10:0;
        $structureScore-=min(15,count($cycleNodes)*2);
        $countNodes=count($nodes);
        $documentationScore=$countNodes?round(100*(1-count($missingDescription)/$countNodes)):100;
        $responsibilityScore=$countNodes?round(100*(1-count($missingOwner)/$countNodes)):100;
        $slaRelevant=array_values(array_filter($nodes,fn($n)=>in_array(($n['data']['criticality']??''),['high','critical'],true)));
        $slaScore=$slaRelevant?round(100*(1-count($missingSlaCritical)/count($slaRelevant))):100;
        $subprocessScore=$subprocesses?round(100*(1-count($subprocessUnlinked)/count($subprocesses))):100;
        $qualityScore=max(0,(int)round(max(0,$structureScore)*.38+$documentationScore*.20+$responsibilityScore*.17+$slaScore*.12+$subprocessScore*.13));

        $laneCounts=[];$typeCounts=[];$levelCounts=[];$criticalityCounts=[];
        foreach($nodes as $node){
            $lane=(string)($node['laneId']??'Sem raia');$laneCounts[$lane]=($laneCounts[$lane]??0)+1;
            $type=(string)($node['type']??'task');$typeCounts[$type]=($typeCounts[$type]??0)+1;
            $level=(string)($node['data']['level']??'operational');$levelCounts[$level]=($levelCounts[$level]??0)+1;
            $critical=(string)($node['data']['criticality']??'medium');$criticalityCounts[$critical]=($criticalityCounts[$critical]??0)+1;
        }

        $result=[
            'quality_score'=>$qualityScore,
            'scores'=>[
                'structure'=>max(0,$structureScore),
                'documentation'=>$documentationScore,
                'responsibility'=>$responsibilityScore,
                'sla'=>$slaScore,
                'subprocesses'=>$subprocessScore,
            ],
            'counts'=>[
                'nodes'=>count($nodes),'edges'=>count($edges),'lanes'=>count($lanes),
                'decisions'=>count($decisions),'subprocesses'=>count($subprocesses),'integrations'=>count($integrations),
                'exceptions'=>count($exceptions),'lane_transitions'=>$laneTransitions,'cycles'=>count($cycleNodes),
            ],
            'node_count'=>count($nodes),
            'edge_count'=>count($edges),
            'longest_path_node_ids'=>$longest,
            'longest_path_length'=>count($longest),
            'total_sla_minutes'=>$totalSla,
            'issues'=>[
                'inaccessible'=>$inaccessible,
                'cycles'=>$cycleNodes,
                'missing_description'=>$missingDescription,
                'missing_owner'=>$missingOwner,
                'missing_sla_critical'=>$missingSlaCritical,
                'decisions_invalid'=>$decisionsInvalid,
                'subprocess_unlinked'=>$subprocessUnlinked,
            ],
            'distribution'=>[
                'lanes'=>$laneCounts,'types'=>$typeCounts,'levels'=>$levelCounts,'criticality'=>$criticalityCounts,
            ],
        ];
        $result['issue_details']=$this->issueDetails($document,$result);
        $result['issue_count']=count($result['issue_details']);
        return $result;
    }

    public function buildRaciRows(array $document): array
    {
        $laneById=[];foreach((array)($document['lanes']??[]) as $lane)$laneById[(string)($lane['id']??'')]=(string)($lane['name']??'');
        $rows=[];
        foreach((array)($document['nodes']??[]) as $node){
            $data=(array)($node['data']??[]);$raci=is_array($data['raci']??null)?$data['raci']:[];
            $rows[]=[
                'Etapa'=>(string)($data['label']??$node['id']??''),
                'Raia'=>$laneById[(string)($node['laneId']??'')]??'Sem raia',
                'Responsável'=>(string)($raci['responsible']??$data['owner']??''),
                'Aprovador'=>(string)($raci['accountable']??''),
                'Consultados'=>implode(', ',array_filter(array_map('strval',(array)($raci['consulted']??[])))),
                'Informados'=>implode(', ',array_filter(array_map('strval',(array)($raci['informed']??[])))),
                'Criticidade'=>(string)($data['criticality']??'medium'),
                'SLA (min)'=>(string)($data['slaMinutes']??''),
            ];
        }
        return $rows;
    }

    public function issueDetails(array $document, ?array $analysis=null): array
    {
        $report=$analysis??$this->analyze($document);
        $nodeMap=[];foreach((array)($document['nodes']??[]) as $node)$nodeMap[(string)($node['id']??'')]=$node;
        $laneMap=[];foreach((array)($document['lanes']??[]) as $lane)$laneMap[(string)($lane['id']??'')]=(string)($lane['name']??'Sem raia');
        $definitions=[
            'inaccessible'=>['Erro','Card não alcançável','Nenhum caminho iniciado no card Início chega a esta etapa.','Conecte uma etapa anterior ao card ou remova-o quando não fizer parte do processo.'],
            'cycles'=>['Atenção','Card participa de um ciclo','O processo pode retornar a uma etapa anterior e repetir indefinidamente.','Confirme se o retorno é intencional e identifique claramente a condição de saída.'],
            'missing_description'=>['Recomendação','Descrição ausente','O card não explica o que deve ser realizado.','Preencha uma descrição curta com objetivo, regra e resultado esperado.'],
            'missing_owner'=>['Recomendação','Responsável ausente','Não está claro quem executa ou acompanha a etapa.','Informe o responsável ou associe o card à raia correta.'],
            'missing_sla_critical'=>['Atenção','Etapa crítica sem SLA','O card é de alta criticidade, mas não possui tempo esperado de atendimento.','Defina o SLA em minutos para permitir acompanhamento e análise de gargalos.'],
            'decisions_invalid'=>['Erro','Decisão incompleta','A decisão possui menos de duas saídas ou alguma saída está sem condição.','Crie pelo menos duas saídas e nomeie cada condição, por exemplo Sim e Não.'],
            'subprocess_unlinked'=>['Recomendação','Subprocesso sem fluxo vinculado','O card indica um subprocesso, mas não abre nenhum fluxo detalhado.','Vincule um fluxo auxiliar ou altere o tipo do card para Atividade.'],
        ];
        $rows=[];
        foreach((array)($report['issues']??[]) as $key=>$ids){
            if(!isset($definitions[$key])||!is_array($ids))continue;
            [$severity,$problem,$why,$fix]=$definitions[$key];
            foreach($ids as $id){
                $node=$nodeMap[(string)$id]??[];$data=(array)($node['data']??[]);
                $card=(string)($data['label']??$id);$lane=$laneMap[(string)($node['laneId']??'')]??'Sem raia';
                $rows[]=[
                    'card_id'=>(string)$id,'card'=>$card,'lane'=>$lane,'severity'=>$severity,'problem'=>$problem,'why'=>$why,'fix'=>$fix,
                    'Card ID'=>(string)$id,'Card'=>$card,'Raia'=>$lane,'Gravidade'=>$severity,'Problema'=>$problem,'Por que importa'=>$why,'Como corrigir'=>$fix,
                ];
            }
        }
        return $rows;
    }

    private function activeGraph(array $document): array
    {
        $nodes=array_values(array_filter((array)($document['nodes']??[]),fn($n)=>(($n['data']['enabled']??true)!==false)));
        $nodeMap=[];foreach($nodes as $node)$nodeMap[(string)$node['id']]=$node;
        $edges=[];$outgoing=[];$incoming=[];
        foreach((array)($document['edges']??[]) as $edge){
            if(($edge['enabled']??true)===false)continue;
            $source=(string)($edge['source']??'');$target=(string)($edge['target']??'');
            if(!isset($nodeMap[$source],$nodeMap[$target]))continue;
            $edges[]=$edge;$outgoing[$source][]=$edge;$incoming[$target][]=$edge;
        }
        return [$nodes,$nodeMap,$edges,$outgoing,$incoming];
    }

    private function reachable(array $starts,array $outgoing): array
    {
        $seen=[];$queue=new SplQueue();foreach($starts as $start)$queue->enqueue($start);
        while(!$queue->isEmpty()){
            $current=(string)$queue->dequeue();if(isset($seen[$current]))continue;$seen[$current]=true;
            foreach($outgoing[$current]??[] as $edge)$queue->enqueue((string)$edge['target']);
        }
        return $seen;
    }

    private function cycleNodes(array $nodes,array $outgoing): array
    {
        $color=[];foreach($nodes as $node)$color[(string)$node['id']]=0;
        $stack=[];$cycles=[];
        $visit=function(string $id) use (&$visit,&$color,&$stack,&$cycles,$outgoing): void {
            $color[$id]=1;$stack[]=$id;
            foreach($outgoing[$id]??[] as $edge){
                $target=(string)$edge['target'];$targetColor=$color[$target]??0;
                if($targetColor===0)$visit($target);
                elseif($targetColor===1){$index=array_search($target,$stack,true);if($index!==false)foreach(array_slice($stack,$index) as $n)$cycles[$n]=true;}
            }
            array_pop($stack);$color[$id]=2;
        };
        foreach(array_keys($color) as $id)if(($color[$id]??0)===0)$visit($id);
        return array_keys($cycles);
    }

    private function longestAcyclicPath(array $starts,array $outgoing,int $limit=5000): array
    {
        $best=[];$explored=0;
        $walk=function(string $id,array $path,array $visited) use (&$walk,&$best,&$explored,$outgoing,$limit): void {
            $explored++;if($explored>$limit)return;if(count($path)>count($best))$best=$path;
            foreach($outgoing[$id]??[] as $edge){$target=(string)$edge['target'];if(isset($visited[$target]))continue;$walk($target,[...$path,$target],$visited+[$target=>true]);}
        };
        foreach($starts as $start)$walk((string)$start,[(string)$start],[(string)$start=>true]);
        return $best;
    }

    private function isException(array $node): bool
    {
        $data=(array)($node['data']??[]);
        $text=mb_strtolower(trim((string)($data['label']??'').' '.(string)($data['description']??'').' '.implode(' ',(array)($data['tags']??[]))));
        foreach(self::EXCEPTION_WORDS as $word)if(str_contains($text,$word))return true;
        return false;
    }
}
