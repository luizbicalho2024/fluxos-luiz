<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Flowchart;
use ZipArchive;
use Illuminate\Support\Str;

class ProjectBundleService
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly FlowRepository $flows,
        private readonly FlowDocumentService $documents,
    ) {}

    public function export(Project $project,string $username,bool $isAdmin=false): string
    {
        $flows=$this->projects->projectFlows($project,$username,$isAdmin);
        $manifest=[
            'format'=>'fluxos-luiz-project','version'=>'1.0',
            'project'=>[
                'id'=>(string)$project->_id,'name'=>(string)$project->name,'code'=>(string)$project->code,'description'=>(string)$project->description,
                'status'=>(string)$project->status,'visibility'=>(string)$project->visibility,'tags'=>(array)($project->tags??[]),'settings'=>(array)($project->settings??[]),
                'default_flow_id'=>(string)($project->default_flow_id??''),'current_release'=>(int)($project->current_release??0),
            ],
            'flows'=>$flows->map(fn($f)=>[
                'id'=>(string)$f->_id,'name'=>(string)$f->name,'role'=>(string)($f->project_role??''),'group'=>(string)($f->project_group??''),'order'=>(int)($f->project_order??0),
                'version'=>(int)($f->current_version??1),'revision'=>(int)($f->revision??1),'hash'=>(string)($f->document_hash??''),
            ])->values()->all(),
            'generated_at'=>now()->toIso8601String(),
        ];
        $tmp=tempnam(sys_get_temp_dir(),'project_zip_');$zip=new ZipArchive();if($zip->open($tmp,ZipArchive::OVERWRITE)!==true)throw new \RuntimeException('Não foi possível criar o pacote do projeto.');
        $zip->addFromString('project.json',json_encode($manifest,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
        $zip->addFromString('README.txt',"Fluxos Luiz - pacote de projeto\nProjeto: {$project->name}\nGerado em: ".now()->format('d/m/Y H:i')."\n");
        foreach($flows as $flow)$zip->addFromString('flows/'.(string)$flow->_id.'.json',json_encode($flow->document,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
        $zip->close();$bytes=file_get_contents($tmp);@unlink($tmp);return $bytes?:'';
    }

    public function importZip(string $bytes,string $owner,string $email='',bool $preserveIds=false): array
    {
        $tmp=tempnam(sys_get_temp_dir(),'project_import_');file_put_contents($tmp,$bytes);$zip=new ZipArchive();if($zip->open($tmp)!==true){@unlink($tmp);throw new \InvalidArgumentException('O arquivo não é um project.zip válido.');}
        $manifestRaw=$zip->getFromName('project.json');if($manifestRaw===false){$zip->close();@unlink($tmp);throw new \InvalidArgumentException('O pacote não contém project.json.');}
        $manifest=json_decode($manifestRaw,true);if(!is_array($manifest)){throw new \InvalidArgumentException('project.json inválido.');}
        $projectMeta=(array)($manifest['project']??[]);$flowDefs=(array)($manifest['flows']??[]);$documents=[];
        foreach($flowDefs as $def){$oldId=(string)($def['id']??'');if($oldId==='')continue;$raw=$zip->getFromName('flows/'.$oldId.'.json');if($raw===false)continue;$doc=json_decode($raw,true);if(is_array($doc))$documents[$oldId]=$doc;}
        $zip->close();@unlink($tmp);if(!$documents)throw new \InvalidArgumentException('O pacote não contém fluxos JSON válidos.');
        return $this->importDocuments($documents,trim((string)($projectMeta['name']??'Projeto importado')),trim((string)($projectMeta['description']??'')),$owner,$email,$preserveIds,$projectMeta);
    }

    public function importDocuments(array $documents,string $projectName,string $description,string $owner,string $email='',bool $preserveIds=false,array $projectMeta=[]): array
    {
        $clean=[];$warnings=[];$idMap=[];
        foreach($documents as $key=>$raw){if(!is_array($raw))continue;[$doc,$warn]=$this->documents->repairImport($raw,$owner);$old=(string)($doc['flow']['id']??$key);$new=$preserveIds?$old:'flow_'.Str::lower(Str::random(12));$idMap[$old]=$new;$clean[$old]=$doc;foreach($warn as $w)$warnings[]=$old.': '.$w;}
        if(!$clean)throw new \InvalidArgumentException('Nenhum documento de fluxo válido foi fornecido.');
        $projectId=$preserveIds&&!empty($projectMeta['id'])?(string)$projectMeta['id']:null;
        if($projectId&&Project::find($projectId))$projectId=null;
        $project=$this->projects->create($projectName?:'Projeto importado',$description,$owner,$email,(string)($projectMeta['code']??''),$projectId,(array)($projectMeta['tags']??[]),(array)($projectMeta['settings']??[]));
        $saved=[];$order=0;
        foreach($clean as $old=>$doc){$order++;$doc['flow']['id']=$idMap[$old];$doc['flow']['projectId']=(string)$project->_id;$doc['flow']['projectOrder']=(int)($doc['flow']['projectOrder']??$order);$doc['flow']['projectRole']=(string)($doc['flow']['projectRole']??'subprocess');$doc['flow']['projectGroup']=(string)($doc['flow']['projectGroup']??'Geral');
            foreach($doc['nodes']??[] as &$node){$linked=(string)($node['data']['linkedFlowId']??'');if($linked!==''&&isset($idMap[$linked]))$node['data']['linkedFlowId']=$idMap[$linked];}unset($node);
            $flow=$this->flows->save($doc,$owner,$email,null,$owner,true,'project_import');$flow->collaborators=[];$flow->visibility='private';$flow->save();$saved[]=$flow;
        }
        $oldDefault=(string)($projectMeta['default_flow_id']??'');$project->default_flow_id=$idMap[$oldDefault]??((string)($saved[0]->_id??''));$project->status='draft';$project->updated_at=now();$project->save();
        ActivityLogger::add($owner,'Importou pacote de projeto',['project_id'=>(string)$project->_id,'flow_count'=>count($saved),'warnings'=>count($warnings)]);
        return ['project'=>$project,'flows'=>$saved,'warnings'=>$warnings,'id_map'=>$idMap];
    }
}
