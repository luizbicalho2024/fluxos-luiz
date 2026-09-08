<?php

namespace App\Http\Controllers;

use App\Services\ProjectRepository;
use Illuminate\Http\Request;

class RelationController extends Controller
{
    public function __construct(private readonly ProjectRepository $projects){}

    public function show(Request $request,string $projectId)
    {
        $u=$request->user();$p=$this->projects->findAuthorized($projectId,$u->username,$u->isAdmin());abort_unless($p,404);
        $graph=$this->projects->relations($p,$u->username,$u->isAdmin());
        return view('relations.show',compact('p','graph'));
    }

    private function decisionSemantic(array $edge,array $source,array $target): string
    {
        if(($source['type']??'')!=='decision')return 'neutral';
        $text=mb_strtolower(trim((string)($edge['label']??'').' '.(string)($edge['condition']??'').' '.(string)($target['data']['label']??'').' '.implode(' ',(array)($target['data']['tags']??[]))));
        foreach(['não','nao','recus','rejeit','falha','erro','cancel','invalid','inválid','reprov','bloque'] as $token)if(str_contains($text,$token))return 'negative';
        foreach(['sim','aprov','aceit','valid','válid','conclu','sucesso','ativo','permit','ok'] as $token)if(str_contains($text,$token))return 'positive';
        return 'neutral';
    }

    public function data(Request $request,string $projectId)
    {
        $u=$request->user();$p=$this->projects->findAuthorized($projectId,$u->username,$u->isAdmin());abort_unless($p,404);
        $graph=$this->projects->relations($p,$u->username,$u->isAdmin());
        $mode=in_array($request->query('mode'),['flows','cards','combined'],true)?$request->query('mode'):'flows';
        $flowFilter=(string)$request->query('flow_id','');
        $nodes=[];$links=[];
        $flowIds=[];
        foreach($graph['flows'] as $flow){
            $fid=(string)$flow->_id;if($flowFilter&&$fid!==$flowFilter)continue;$flowIds[$fid]=true;
            if(in_array($mode,['flows','combined'],true))$nodes[]=['id'=>$fid,'name'=>(string)$flow->name,'label'=>(string)$flow->name,'kind'=>'flow','flow_id'=>$fid,'status'=>(string)($flow->workflow_status?:$flow->status),'role'=>(string)($flow->project_role?:'flow'),'type'=>'flow','url'=>route('flows.editor',$fid)];
            if(in_array($mode,['cards','combined'],true)){
                $doc=(array)$flow->document;$nodeMap=[];
                foreach((array)($doc['nodes']??[]) as $node){if(($node['data']['enabled']??true)===false)continue;$nid=(string)($node['id']??'');$nodeMap[$nid]=$node;$id=$fid.'::'.$nid;$nodes[]=['id'=>$id,'name'=>(string)($node['data']['label']??$nid),'label'=>(string)($node['data']['label']??$nid),'kind'=>'card','flow_id'=>$fid,'flow_name'=>(string)$flow->name,'node_id'=>$nid,'status'=>(string)($flow->workflow_status?:$flow->status),'role'=>(string)($node['data']['level']??'operational'),'type'=>(string)($node['type']??'task'),'criticality'=>(string)($node['data']['criticality']??'medium'),'owner'=>(string)($node['data']['owner']??''),'url'=>route('flows.editor',$fid).'?focus_node='.urlencode($nid)];}
                foreach((array)($doc['edges']??[]) as $edge){if(($edge['enabled']??true)===false)continue;$source=$nodeMap[(string)($edge['source']??'')]??null;$target=$nodeMap[(string)($edge['target']??'')]??null;if(!$source||!$target)continue;$links[]=['source'=>$fid.'::'.(string)$edge['source'],'target'=>$fid.'::'.(string)$edge['target'],'label'=>(string)($edge['label']??$edge['condition']??''),'kind'=>'edge','semantic'=>$this->decisionSemantic((array)$edge,(array)$source,(array)$target)];}
                if($mode==='combined')foreach($nodeMap as $nid=>$node)$links[]=['source'=>$fid,'target'=>$fid.'::'.$nid,'label'=>'','kind'=>'contains','semantic'=>'neutral'];
            }
        }
        if(in_array($mode,['flows','combined'],true)&&!$flowFilter){
            foreach($graph['links'] as $l){if(!isset($flowIds[$l['source_flow_id']])||!isset($flowIds[$l['target_flow_id']]))continue;$links[]=['source'=>$l['source_flow_id'],'target'=>$l['target_flow_id'],'label'=>$l['source_node_label'],'kind'=>'subprocess','semantic'=>'neutral'];}
        }
        return response()->json(['mode'=>$mode,'nodes'=>$nodes,'links'=>$links,'broken'=>$graph['broken'],'flows'=>$graph['flows']->map(fn($f)=>['id'=>(string)$f->_id,'name'=>(string)$f->name])->values()]);
    }
}
