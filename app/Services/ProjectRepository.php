<?php

namespace App\Services;

use App\Models\Flowchart;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectRelease;
use App\Models\ProjectReleaseFlow;
use Illuminate\Support\Str;

class ProjectRepository
{
    public function __construct(
        private readonly FlowRepository $flows,
        private readonly FlowDocumentService $documents
    ) {}

    public function permissionFor(Project $project,string $username,bool $isAdmin=false): ?string
    {
        $username=strtolower(trim($username));
        if ($isAdmin || strtolower((string)$project->owner_username)===$username) return 'owner';
        foreach ((array)($project->members ?? []) as $member) {
            if (strtolower((string)($member['username']??''))===$username) return (string)($member['level']??'viewer');
        }
        return $project->visibility==='organization'?'viewer':null;
    }

    public function visibleTo(string $username,bool $isAdmin=false)
    {
        $q=Project::query();
        if (!$isAdmin) {
            $q->where(function($x) use($username){
                $x->where('owner_username',$username)
                  ->orWhere('members.username',$username)
                  ->orWhere('visibility','organization');
            });
        }
        return $q->orderBy('updated_at','desc')->get()->filter(fn($p)=>$this->permissionFor($p,$username,$isAdmin)!==null)->values();
    }

    public function findAuthorized(string $id,string $username,bool $isAdmin=false): ?Project
    {
        $project=Project::find($id);
        return $project && $this->permissionFor($project,$username,$isAdmin) ? $project : null;
    }

    public function create(string $name,string $description,string $owner,string $email=''): Project
    {
        if (trim($name)==='') throw new \InvalidArgumentException('O nome do projeto é obrigatório.');
        $id='project_'.Str::lower(Str::random(12));
        $project=Project::create([
            '_id'=>$id,'id'=>$id,'name'=>trim($name),
            'code'=>strtoupper(Str::slug($name,'_')),
            'description'=>trim($description),'status'=>'draft',
            'owner_username'=>strtolower($owner),'owner_email'=>strtolower(trim($email)),
            'visibility'=>'private','members'=>[],'default_flow_id'=>'','tags'=>[],
            'settings'=>[
                'openLinkedFlowInTab'=>true,'projectPlayback'=>true,'globalSearch'=>true,'requireReleaseForPublish'=>true
            ],
            'current_release'=>0,'created_at'=>now(),'updated_at'=>now(),'last_saved_by'=>strtolower($owner),
        ]);
        ActivityLogger::add($owner,'Criou projeto',['project_id'=>$id,'name'=>$name]);
        return $project;
    }

    public function setMembers(Project $project,string $actor,array $members,bool $isAdmin=false): array
    {
        $permission=$this->permissionFor($project,$actor,$isAdmin);
        if (!in_array($permission,['owner','approver'],true)) throw new \Symfony\Component\HttpKernel\Exception\HttpException(403,'Somente proprietário ou aprovador pode alterar participantes.');
        $clean=[];$seen=[];$owner=strtolower((string)$project->owner_username);
        foreach($members as $m){
            $u=strtolower(trim((string)($m['username']??'')));
            $level=(string)($m['level']??'viewer');
            if($u && $u!==$owner && !isset($seen[$u]) && in_array($level,FlowRepository::ACCESS_LEVELS,true)){
                $clean[]=['username'=>$u,'level'=>$level];$seen[$u]=true;
            }
        }
        $project->members=$clean;$project->updated_at=now();$project->last_saved_by=$actor;$project->save();
        ProjectMember::where('project_id',(string)$project->_id)->delete();
        foreach($clean as $m){
            ProjectMember::create(['_id'=>(string)$project->_id.':'.$m['username'],'project_id'=>(string)$project->_id,'username'=>$m['username'],'level'=>$m['level'],'updated_at'=>now()]);
        }
        Flowchart::where('project_id',(string)$project->_id)->update(['collaborators'=>$clean,'visibility'=>$project->visibility]);
        return $clean;
    }

    public function projectFlows(Project $project,string $username,bool $isAdmin=false)
    {
        return $this->flows->visibleTo($username,$isAdmin,(string)$project->_id)
            ->sortBy(fn($f)=>sprintf('%08d-%s',(int)($f->project_order??0),$f->name))->values();
    }

    public function assignFlow(Project $project,Flowchart $flow,string $actor,string $role='subprocess',string $group='',int $order=0,bool $isAdmin=false): Flowchart
    {
        $permission=$this->permissionFor($project,$actor,$isAdmin);
        if(!in_array($permission,['owner','editor','reviewer','approver'],true)) throw new \Symfony\Component\HttpKernel\Exception\HttpException(403,'Sem permissão para alterar o projeto.');
        $doc=(array)$flow->document;
        $doc['flow']['projectId']=(string)$project->_id;
        $doc['flow']['projectRole']=in_array($role,['executive','operational','subprocess','support'],true)?$role:'subprocess';
        $doc['flow']['projectGroup']=$group;
        $doc['flow']['projectOrder']=$order;
        $saved=$this->flows->save($doc,(string)$flow->owner_username,(string)$flow->owner_email,(int)$flow->revision,$actor,true,'project_assignment',$isAdmin);
        $saved->collaborators=(array)$project->members;$saved->visibility=$project->visibility;$saved->save();
        if(!$project->default_flow_id || $role==='executive'){
            $project->default_flow_id=(string)$flow->_id;$project->updated_at=now();$project->save();
        }
        return $saved;
    }

    public function relations(Project $project,string $username,bool $isAdmin=false): array
    {
        $flows=$this->projectFlows($project,$username,$isAdmin);
        $byId=[];$links=[];$broken=[];
        foreach($flows as $flow)$byId[(string)$flow->_id]=$flow;
        foreach($flows as $flow){
            foreach((array)($flow->document['nodes']??[]) as $node){
                $data=(array)($node['data']??[]);
                $target=trim((string)($data['linkedFlowId']??''));
                if(!$target)continue;
                $link=[
                    'source_flow_id'=>(string)$flow->_id,'source_flow_name'=>$flow->name,
                    'source_node_id'=>(string)($node['id']??''),'source_node_label'=>(string)($data['label']??'Subprocesso'),
                    'target_flow_id'=>$target,'entry_node_id'=>(string)($data['linkedFlowEntryNodeId']??''),
                    'exit_node_id'=>(string)($data['linkedFlowExitNodeId']??''),
                ];
                $reasons=[];
                if(!isset($byId[$target]))$reasons[]='fluxo vinculado ausente no projeto';
                else{
                    $ids=collect($byId[$target]->document['nodes']??[])->pluck('id')->all();
                    if($link['entry_node_id'] && !in_array($link['entry_node_id'],$ids,true))$reasons[]='nó de entrada inexistente';
                    if($link['exit_node_id'] && !in_array($link['exit_node_id'],$ids,true))$reasons[]='nó de saída inexistente';
                }
                if($target===(string)$flow->_id)$reasons[]='referência ao próprio fluxo';
                if($reasons)$broken[]=$link+['reasons'=>$reasons];
                $links[]=$link;
            }
        }
        return ['flows'=>$flows,'links'=>$links,'broken'=>$broken];
    }

    private function detectCycles(array $flowIds, array $links): array
    {
        $adjacency = array_fill_keys($flowIds, []);
        foreach ($links as $link) {
            $source = (string)($link['source_flow_id'] ?? '');
            $target = (string)($link['target_flow_id'] ?? '');
            if (isset($adjacency[$source]) && array_key_exists($target, $adjacency)) {
                $adjacency[$source][] = $target;
            }
        }

        $cycles = [];
        $visiting = [];
        $visited = [];
        $stack = [];
        $visit = function (string $node) use (&$visit, &$cycles, &$visiting, &$visited, &$stack, $adjacency): void {
            if (isset($visiting[$node])) {
                $index = array_search($node, $stack, true);
                if ($index !== false) {
                    $cycle = array_slice($stack, $index);
                    $cycle[] = $node;
                    $key = implode('>', $cycle);
                    $cycles[$key] = $cycle;
                }
                return;
            }
            if (isset($visited[$node])) return;
            $visiting[$node] = true;
            $stack[] = $node;
            foreach ($adjacency[$node] ?? [] as $neighbor) $visit($neighbor);
            array_pop($stack);
            unset($visiting[$node]);
            $visited[$node] = true;
        };

        foreach ($flowIds as $flowId) $visit((string)$flowId);
        return array_values($cycles);
    }

    public function analyze(Project $project,string $username,bool $isAdmin=false): array
    {
        $graph=$this->relations($project,$username,$isAdmin);
        $flowIds=$graph['flows']->map(fn($f)=>(string)$f->_id)->all();
        $incoming=array_fill_keys($flowIds,0);$outgoing=array_fill_keys($flowIds,0);
        foreach($graph['links'] as $link){
            if(isset($outgoing[$link['source_flow_id']]))$outgoing[$link['source_flow_id']]++;
            if(isset($incoming[$link['target_flow_id']]))$incoming[$link['target_flow_id']]++;
        }
        $orphans=array_values(array_filter($flowIds,fn($id)=>$incoming[$id]===0 && $outgoing[$id]===0));
        $roots=array_values(array_filter($flowIds,fn($id)=>$incoming[$id]===0));
        $cycles=$this->detectCycles($flowIds,$graph['links']);
        $quality=[];$nodes=0;$edges=0;$issues=0;
        foreach($graph['flows'] as $flow){
            $a=$this->documents->analyze((array)$flow->document);
            $quality[]=['flow_id'=>(string)$flow->_id,'name'=>$flow->name]+$a;
            $nodes+=$a['node_count'];$edges+=$a['edge_count'];$issues+=$a['issue_count'];
        }
        $avg=$quality?round(array_sum(array_column($quality,'quality_score'))/count($quality)):0;
        return [
            'flow_count'=>count($flowIds),'node_count'=>$nodes,'edge_count'=>$edges,'link_count'=>count($graph['links']),
            'broken_count'=>count($graph['broken']),'broken_links'=>$graph['broken'],'orphans'=>$orphans,'roots'=>$roots,
            'cycles'=>$cycles,'cycle_count'=>count($cycles),
            'quality_rows'=>$quality,'average_quality'=>$avg,'issue_count'=>$issues,
            'quality_score'=>max(0,min(100,$avg-count($graph['broken'])*8-count($cycles)*10-count($orphans)*2)),
        ];
    }

    public function createRelease(Project $project,string $actor,bool $isAdmin=false,string $name='',string $notes=''): ProjectRelease
    {
        $permission=$this->permissionFor($project,$actor,$isAdmin);
        if(!in_array($permission,['owner','approver'],true))throw new \Symfony\Component\HttpKernel\Exception\HttpException(403,'Somente proprietário ou aprovador pode criar release.');
        $flows=$this->projectFlows($project,$actor,$isAdmin);
        if($flows->isEmpty())throw new \InvalidArgumentException('O projeto não possui fluxos.');
        $analysis=$this->analyze($project,$actor,$isAdmin);
        if($analysis['broken_count']>0)throw new \InvalidArgumentException('Corrija os vínculos quebrados antes de criar a release.');
        $latest=ProjectRelease::where('project_id',(string)$project->_id)->orderBy('version','desc')->first();
        $version=(int)($latest?->version??0)+1;
        $release=ProjectRelease::create([
            '_id'=>(string)$project->_id.':'.$version,'project_id'=>(string)$project->_id,'version'=>$version,
            'name'=>trim($name)?:'Release '.$version,'notes'=>trim($notes),'created_by'=>$actor,'created_at'=>now(),
            'quality_score'=>$analysis['quality_score'],
        ]);
        foreach($flows as $flow){
            ProjectReleaseFlow::create([
                '_id'=>(string)$project->_id.':'.$version.':'.(string)$flow->_id,'project_id'=>(string)$project->_id,
                'release_version'=>$version,'flow_id'=>(string)$flow->_id,'flow_name'=>$flow->name,
                'revision'=>(int)$flow->revision,'version'=>(int)$flow->current_version,'document_hash'=>(string)$flow->document_hash,
                'document'=>$flow->document,
            ]);
        }
        $project->current_release=$version;$project->status='published';$project->updated_at=now();$project->last_saved_by=$actor;$project->save();
        ActivityLogger::add($actor,'Criou release do projeto',['project_id'=>(string)$project->_id,'version'=>$version]);
        return $release;
    }

    public function delete(Project $project,string $actor,bool $deleteFlows=false,bool $isAdmin=false): void
    {
        if($this->permissionFor($project,$actor,$isAdmin)!=='owner')throw new \Symfony\Component\HttpKernel\Exception\HttpException(403,'Somente o proprietário pode excluir o projeto.');
        $flows=Flowchart::where('project_id',(string)$project->_id)->get();
        foreach($flows as $flow){
            if($deleteFlows)$this->flows->delete($flow,$actor,$isAdmin);
            else{
                $doc=(array)$flow->document;
                foreach(['projectId','projectRole','projectGroup','projectOrder'] as $k)unset($doc['flow'][$k]);
                $this->flows->save($doc,(string)$flow->owner_username,(string)$flow->owner_email,(int)$flow->revision,$actor,true,'project_detach',$isAdmin);
            }
        }
        foreach([ProjectMember::class,ProjectRelease::class,ProjectReleaseFlow::class] as $model)$model::where('project_id',(string)$project->_id)->delete();
        $project->delete();
        ActivityLogger::add($actor,'Excluiu projeto',['project_id'=>(string)$project->_id,'delete_flows'=>$deleteFlows]);
    }
}
