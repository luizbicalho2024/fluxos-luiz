<?php

namespace App\Http\Controllers;

use App\Models\Flowchart;
use App\Models\ProjectRelease;
use App\Models\ProjectReleaseFlow;
use App\Models\User;
use App\Services\FlowRepository;
use App\Services\ProjectBundleService;
use App\Services\ProjectRepository;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly FlowRepository $flows,
        private readonly ProjectBundleService $bundles,
    ){}

    public function index(Request $request)
    {
        $u=$request->user();$projects=$this->projects->visibleTo($u->username,$u->isAdmin());return view('projects.index',compact('projects'));
    }

    public function store(Request $request)
    {
        $data=$request->validate(['name'=>['required','string','max:180'],'description'=>['nullable','string','max:2000'],'code'=>['nullable','string','max:80']]);$u=$request->user();
        $p=$this->projects->create($data['name'],$data['description']??'',$u->username,$u->email,$data['code']??'');return redirect()->route('projects.show',$p->_id)->with('success','Projeto criado.');
    }

    public function show(Request $request,string $id)
    {
        $u=$request->user();$p=$this->projects->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($p,404);$permission=$this->projects->permissionFor($p,$u->username,$u->isAdmin());
        $flows=$this->projects->projectFlows($p,$u->username,$u->isAdmin());$available=$this->flows->visibleTo($u->username,$u->isAdmin())->filter(fn($f)=>(string)($f->project_id??'')==='')->values();$analysis=$this->projects->analyze($p,$u->username,$u->isAdmin());
        $releases=ProjectRelease::where('project_id',$id)->orderBy('version','desc')->get();$users=User::where('active',true)->orderBy('name')->get();$graph=$this->projects->relations($p,$u->username,$u->isAdmin());
        return view('projects.show',compact('p','permission','flows','available','analysis','releases','users','graph'));
    }

    public function update(Request $request,string $id)
    {
        $u=$request->user();$p=$this->projects->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($p,404);$permission=$this->projects->permissionFor($p,$u->username,$u->isAdmin());abort_unless(in_array($permission,['owner','editor','reviewer','approver'],true),403);
        $data=$request->validate([
            'name'=>['required','string','max:180'],'description'=>['nullable','string','max:2000'],'code'=>['nullable','string','max:80'],
            'status'=>['required','in:draft,in_review,published,archived'],'visibility'=>['required','in:private,organization'],'default_flow_id'=>['nullable','string'],
            'tags'=>['nullable','string','max:1000'],'openLinkedFlowInTab'=>['nullable','boolean'],'projectPlayback'=>['nullable','boolean'],'globalSearch'=>['nullable','boolean'],'requireReleaseForPublish'=>['nullable','boolean'],
        ]);
        if($data['visibility']!==$p->visibility&&!in_array($permission,['owner','approver'],true))abort(403);
        $p->name=$data['name'];$p->description=$data['description']??'';$p->code=trim((string)($data['code']??''))?:$p->code;$p->status=$data['status'];$p->visibility=$data['visibility'];$p->default_flow_id=$data['default_flow_id']??$p->default_flow_id;
        $p->tags=array_values(array_filter(array_map('trim',explode(',',(string)($data['tags']??implode(',',(array)($p->tags??[])))))));
        $settings=(array)($p->settings??[]);foreach(['openLinkedFlowInTab','projectPlayback','globalSearch','requireReleaseForPublish'] as $key)if($request->has($key))$settings[$key]=$request->boolean($key);$p->settings=$settings;$p->updated_at=now();$p->last_saved_by=$u->username;$p->save();
        Flowchart::where('project_id',$id)->update(['visibility'=>$p->visibility]);return back()->with('success','Projeto atualizado.');
    }

    public function members(Request $request,string $id)
    {
        $u=$request->user();$p=$this->projects->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($p,404);$members=[];foreach((array)$request->input('members',[]) as $username=>$level)if($level)$members[]=['username'=>$username,'level'=>$level];
        $this->projects->setMembers($p,$u->username,$members,$u->isAdmin());return back()->with('success','Participantes atualizados.');
    }

    public function assign(Request $request,string $id)
    {
        $u=$request->user();$p=$this->projects->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($p,404);$data=$request->validate(['flow_id'=>['required','string'],'role'=>['required','in:executive,operational,subprocess,support'],'group'=>['nullable','string','max:120'],'order'=>['nullable','integer','min:0']]);
        $flow=$this->flows->findAuthorized($data['flow_id'],$u->username,$u->isAdmin());abort_unless($flow,404);$this->projects->assignFlow($p,$flow,$u->username,$data['role'],$data['group']??'',(int)($data['order']??0),$u->isAdmin());return back()->with('success','Fluxo vinculado ao projeto.');
    }

    public function detach(Request $request,string $id,string $flowId)
    {
        $u=$request->user();$p=$this->projects->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($p,404);$flow=$this->flows->findAuthorized($flowId,$u->username,$u->isAdmin());abort_unless($flow,404);$this->projects->detachFlow($p,$flow,$u->username,$u->isAdmin());
        return $request->expectsJson()?response()->json(['ok'=>true]):back()->with('success','Fluxo desvinculado e mantido como avulso.');
    }

    public function search(Request $request,string $id)
    {
        $u=$request->user();$p=$this->projects->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($p,404);$q=(string)$request->query('q','');return response()->json(['ok'=>true,'results'=>$this->projects->search($p,$u->username,$q,$u->isAdmin())]);
    }

    public function path(Request $request,string $id)
    {
        $u=$request->user();$p=$this->projects->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($p,404);$data=$request->validate(['source'=>['required','string'],'target'=>['required','string']]);return response()->json(['ok'=>true,'path'=>$this->projects->shortestPath($p,$u->username,$data['source'],$data['target'],$u->isAdmin())]);
    }

    public function impact(Request $request,string $id,string $flowId)
    {
        $u=$request->user();$p=$this->projects->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($p,404);return response()->json(['ok'=>true,'impact'=>$this->projects->impact($p,$u->username,$flowId,$u->isAdmin())]);
    }

    public function release(Request $request,string $id)
    {
        $u=$request->user();$p=$this->projects->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($p,404);$data=$request->validate(['name'=>['nullable','string','max:180'],'notes'=>['nullable','string','max:4000']]);$r=$this->projects->createRelease($p,$u->username,$u->isAdmin(),$data['name']??'',$data['notes']??'');return back()->with('success',"Release {$r->version} criada.");
    }

    public function releaseData(Request $request,string $id,int $version)
    {
        $u=$request->user();$p=$this->projects->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($p,404);$release=ProjectRelease::where('project_id',$id)->where('version',$version)->firstOrFail();$flows=ProjectReleaseFlow::where('project_id',$id)->where('release_version',$version)->orderBy('flow_name')->get();return response()->json(['ok'=>true,'release'=>$release,'flows'=>$flows]);
    }

    public function exportBundle(Request $request,string $id)
    {
        $u=$request->user();$p=$this->projects->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($p,404);$bytes=$this->bundles->export($p,$u->username,$u->isAdmin());$name=preg_replace('/[^A-Za-z0-9_-]+/','_',iconv('UTF-8','ASCII//TRANSLIT//IGNORE',(string)$p->name)?:'projeto');return response($bytes,200,['Content-Type'=>'application/zip','Content-Disposition'=>'attachment; filename="'.$name.'_project.zip"']);
    }

    public function importBundle(Request $request)
    {
        $u=$request->user();$data=$request->validate(['bundle'=>['required','file','max:51200'],'preserve_ids'=>['nullable','boolean']]);$result=$this->bundles->importZip(file_get_contents($data['bundle']->getRealPath()),$u->username,$u->email,$request->boolean('preserve_ids'));
        return redirect()->route('projects.show',$result['project']->_id)->with('success','Projeto importado com '.count($result['flows']).' fluxo(s) e '.count($result['warnings']).' correção(ões) estrutural(is).');
    }

    public function importDocuments(Request $request)
    {
        $u=$request->user();$data=$request->validate(['name'=>['required','string','max:180'],'description'=>['nullable','string','max:2000'],'files'=>['required','array','min:1'],'files.*'=>['file','max:10240']]);$docs=[];foreach($data['files'] as $file){$raw=json_decode(file_get_contents($file->getRealPath()),true);if(is_array($raw))$docs[$file->getClientOriginalName()]=$raw;}
        $result=$this->bundles->importDocuments($docs,$data['name'],$data['description']??'',$u->username,$u->email,false);return redirect()->route('projects.show',$result['project']->_id)->with('success','Projeto criado a partir de múltiplos JSONs.');
    }

    public function destroy(Request $request,string $id)
    {
        $u=$request->user();$p=$this->projects->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($p,404);$this->projects->delete($p,$u->username,$request->boolean('delete_flows'),$u->isAdmin());return redirect()->route('projects.index')->with('success','Projeto excluído.');
    }
}
