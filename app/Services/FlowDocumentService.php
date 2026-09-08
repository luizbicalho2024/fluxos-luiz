<?php

namespace App\Services;

use Illuminate\Support\Str;

class FlowDocumentService
{
    public const SCHEMA_VERSION = '2.0.0';
    public const NODE_TYPES = ['start','end','task','decision','subprocess','event','wait','document','api','note'];
    public const STATUSES = ['draft','in_review','approved','published','archived'];

    public function newDocument(string $name, string $owner): array
    {
        $id = 'flow_'.Str::lower(Str::random(12));
        $now = now()->toIso8601String();
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'flow' => [
                'id'=>$id,'name'=>trim($name) ?: 'Novo processo','description'=>'','status'=>'draft',
                'orientation'=>'LR','createdAt'=>$now,'updatedAt'=>$now,'createdBy'=>$owner,'tags'=>[],
            ],
            'settings' => [
                'snapToGrid'=>true,'gridSize'=>20,'autoLayout'=>false,'showMiniMap'=>true,'showGrid'=>true,
                'layoutPreset'=>'readable','edgeRouting'=>'smooth','interactivePlayback'=>true,
            ],
            'viewport'=>['x'=>0,'y'=>0,'zoom'=>1],
            'lanes'=>[[
                'id'=>'lane_process','name'=>'Processo','owner'=>'','orientation'=>'horizontal','order'=>1,
                'color'=>'#E8F5F0','collapsed'=>false,'enabled'=>true,'height'=>260,
            ]],
            'nodes'=>[],'edges'=>[],
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
        $flow['orientation']=in_array(($flow['orientation']??'LR'),['LR','RL'],true)?$flow['orientation']:'LR';
        $flow['createdAt']=$flow['createdAt']??now()->toIso8601String();
        $flow['updatedAt']=now()->toIso8601String();
        $flow['createdBy']=$flow['createdBy']??$owner;
        $flow['tags']=array_values(array_slice(array_filter(array_map(fn($v)=>trim((string)$v),(array)($flow['tags']??[]))),0,30));
        $document['flow']=$flow;

        $settings=is_array($document['settings']??null)?$document['settings']:[];
        $settings=array_merge([
            'snapToGrid'=>true,'gridSize'=>20,'autoLayout'=>false,'showMiniMap'=>true,'showGrid'=>true,
            'layoutPreset'=>'readable','edgeRouting'=>'smooth','interactivePlayback'=>true,
        ],$settings);
        unset($settings['autosaveSeconds']);
        if(!in_array($settings['layoutPreset'],['compact','readable','preserve'],true))$settings['layoutPreset']='readable';
        if(!in_array($settings['edgeRouting'],['corridor','corridor-v2','orthogonal','smooth','straight'],true))$settings['edgeRouting']='smooth';
        $document['settings']=$settings;
        $document['viewport']=is_array($document['viewport']??null)?$document['viewport']:['x'=>0,'y'=>0,'zoom'=>1];

        $lanes=[];
        foreach((array)($document['lanes']??[]) as $i=>$lane){
            if(!is_array($lane))continue;
            $lane['id']=trim((string)($lane['id']??'')) ?: 'lane_'.Str::lower(Str::random(8));
            $lane['name']=trim((string)($lane['name']??'')) ?: 'Raia '.($i+1);
            $lane['owner']=(string)($lane['owner']??'');
            $lane['orientation']='horizontal';
            $lane['order']=(int)($lane['order']??$i+1);
            $lane['color']=(string)($lane['color']??'#E8F5F0');
            $lane['collapsed']=(bool)($lane['collapsed']??false);
            $lane['enabled']=($lane['enabled']??true)!==false;
            $lane['height']=max(110,min(1600,(int)($lane['height']??240)));
            $lanes[]=$lane;
        }
        if(!$lanes)$lanes[]=['id'=>'lane_process','name'=>'Processo','owner'=>'','orientation'=>'horizontal','order'=>1,'color'=>'#E8F5F0','collapsed'=>false,'enabled'=>true,'height'=>260];
        $document['lanes']=$lanes;

        $nodes=[];
        foreach((array)($document['nodes']??[]) as $node){
            if(!is_array($node))continue;
            $node['id']=trim((string)($node['id']??'')) ?: 'node_'.Str::lower(Str::random(10));
            $node['type']=in_array(($node['type']??'task'),self::NODE_TYPES,true)?$node['type']:'task';
            $node['laneId']=$node['laneId']??null;
            $pos=is_array($node['position']??null)?$node['position']:[];
            $node['position']=['x'=>(float)($pos['x']??0),'y'=>(float)($pos['y']??0)];
            $data=is_array($node['data']??null)?$node['data']:[];
            $data=array_merge([
                'label'=>ucfirst($node['type']),'description'=>'','owner'=>'','enabled'=>true,'locked'=>false,
                'slaMinutes'=>null,'tags'=>[],'level'=>'operational','category'=>'process','criticality'=>'medium',
                'linkedFlowId'=>null,'linkedFlowEntryNodeId'=>null,'linkedFlowExitNodeId'=>null,'preferredEdgeId'=>null,
                'documentationUrl'=>'','raci'=>['responsible'=>'','accountable'=>'','consulted'=>[],'informed'=>[]],
            ],$data);
            $data['label']=trim((string)$data['label']) ?: ucfirst($node['type']);
            $data['enabled']=$data['enabled']!==false;
            $data['locked']=$data['locked']===true;
            $data['tags']=array_values(array_slice(array_filter(array_map(fn($v)=>trim((string)$v),(array)$data['tags'])),0,30));
            if(!in_array($data['level'],['executive','operational','technical'],true))$data['level']=$node['type']==='api'?'technical':'operational';
            if(!in_array($data['criticality'],['low','medium','high','critical'],true))$data['criticality']='medium';
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
        $byId=[];foreach($nodes as $node)$byId[$node['id']]=$node;
        foreach($outgoing as $source=>$indexes){
            $decision=($byId[$source]['type']??'')==='decision';
            foreach($indexes as $branch=>$idx)$edges[$idx]['sourceHandle']=$edges[$idx]['sourceHandle']??($decision?'branch-'.$branch:'output');
        }
        $document['edges']=$edges;
        return $document;
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

        $nodeIds=[];$outgoing=[];
        foreach((array)($document['nodes']??[]) as $node){
            $id=(string)($node['id']??'');
            if($id==='')$errors[]='Existe card sem ID.';
            if(isset($nodeIds[$id]))$errors[]="ID de card duplicado: {$id}";
            $nodeIds[$id]=true;
        }
        foreach((array)($document['edges']??[]) as $edge){
            $source=(string)($edge['source']??'');$target=(string)($edge['target']??'');
            if(!isset($nodeIds[$source]))$errors[]="Conexão aponta para origem inexistente: {$source}";
            if(!isset($nodeIds[$target]))$errors[]="Conexão aponta para destino inexistente: {$target}";
            if(($edge['enabled']??true)!==false)$outgoing[$source]=($outgoing[$source]??0)+1;
        }
        foreach((array)($document['nodes']??[]) as $node){
            if(($node['type']??'')==='decision'&&($node['data']['enabled']??true)!==false&&($outgoing[$node['id']]??0)<2){
                $errors[]="Decisão {$node['id']} precisa de ao menos duas saídas.";
            }
        }
        return array_values(array_unique($errors));
    }

    public function repairImport(array $document,string $owner=''): array
    {
        $document=$this->normalize($document,$owner);
        $warnings=[];$counts=[];
        foreach($document['edges'] as $edge)if(($edge['enabled']??true)!==false)$counts[$edge['source']]=($counts[$edge['source']]??0)+1;
        foreach($document['nodes'] as &$node){
            if($node['type']==='decision'&&($counts[$node['id']]??0)<2){
                $node['type']='task';$node['data']['tags'][]='Importação corrigida';
                $warnings[]="Decisão {$node['id']} convertida em atividade por não possuir ramificações reais.";
            }
        } unset($node);
        $types=[];foreach($document['nodes'] as $node)$types[$node['id']]=$node['type'];
        foreach($document['edges'] as &$edge)if(($types[$edge['source']]??'')!=='decision')$edge['sourceHandle']='output';
        unset($edge);
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
        $canonical = $this->canonicalize($document);
        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));
    }

    public function analyze(array $document): array
    {
        $nodeIds=array_values(array_filter(array_map(fn($n)=>(string)($n['id']??''),(array)($document['nodes']??[]))));
        $incoming=array_fill_keys($nodeIds,0);$outgoing=array_fill_keys($nodeIds,0);$broken=[];
        foreach((array)($document['edges']??[]) as $edge){
            $s=(string)($edge['source']??'');$t=(string)($edge['target']??'');
            if(!in_array($s,$nodeIds,true)||!in_array($t,$nodeIds,true)){$broken[]=(string)($edge['id']??'');continue;}
            $outgoing[$s]++;$incoming[$t]++;
        }
        $starts=array_values(array_filter((array)($document['nodes']??[]),fn($n)=>($n['type']??'')==='start'));
        $ends=array_values(array_filter((array)($document['nodes']??[]),fn($n)=>($n['type']??'')==='end'));
        $orphans=[];
        foreach((array)($document['nodes']??[]) as $node){
            $id=(string)($node['id']??'');
            if(($incoming[$id]??0)===0&&($outgoing[$id]??0)===0&&!in_array(($node['type']??''),['start','end','note'],true))$orphans[]=$id;
        }
        $issues=count($broken)+count($orphans)+max(0,1-count($starts))+max(0,1-count($ends));
        return [
            'quality_score'=>max(0,100-$issues*8),'node_count'=>count($nodeIds),'edge_count'=>count($document['edges']??[]),
            'broken_edges'=>$broken,'orphans'=>$orphans,'start_count'=>count($starts),'end_count'=>count($ends),'issue_count'=>$issues,
        ];
    }
}
