<?php

namespace App\Http\Controllers;

use App\Models\Flowchart;
use App\Models\Project;
use App\Models\ProjectRelease;
use App\Models\User;
use App\Services\FlowRepository;
use App\Services\ProjectRepository;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function __construct(private readonly ProjectRepository $projects,private readonly FlowRepository $flows){}

    public function index(Request $request)
    {
        $u=$request->user();$projects=$this->projects->visibleTo($u->username,$u->isAdmin());
        return view('projects.index',compact('projects'));
    }

    public function store(Request $request)
    {
        $data=$request->validate(['name'=>['required','string','max:180'],'description'=>['nullable','string','max:2000']]);
        $u=$request->user();
        $p=$this->projects->create($data['name'],$data['description']??'',$u->username,$u->email);
        return redirect()->route('projects.show',$p->_id)->with('success','Projeto criado.');
    }

    public function show(Request $request,string $id)
    {
        $u=$request->user();$p=$this->projects->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($p,404);
        $permission=$this->projects->permissionFor($p,$u->username,$u->isAdmin());
        $flows=$this->projects->projectFlows($p,$u->username,$u->isAdmin());
        $available=$this->flows->visibleTo($u->username,$u->isAdmin())->where(fn($f)=>(string)($f->project_id??'')==='');
        $analysis=$this->projects->analyze($p,$u->username,$u->isAdmin());
        $releases=ProjectRelease::where('project_id',$id)->orderBy('version','desc')->get();
        $users=User::where('active',true)->orderBy('name')->get();
        return view('projects.show',compact('p','permission','flows','available','analysis','releases','users'));
    }

    public function update(Request $request,string $id)
    {
        $u=$request->user();$p=$this->projects->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($p,404);
        $permission=$this->projects->permissionFor($p,$u->username,$u->isAdmin());
        abort_unless(in_array($permission,['owner','editor','reviewer','approver'],true),403);
        $data=$request->validate([
            'name'=>['required','string','max:180'],'description'=>['nullable','string','max:2000'],
            'status'=>['required','in:draft,in_review,published,archived'],'visibility'=>['required','in:private,organization']
        ]);
        if($data['visibility']!==$p->visibility && !in_array($permission,['owner','approver'],true))abort(403);
        $p->fill($data);$p->updated_at=now();$p->last_saved_by=$u->username;$p->save();
        Flowchart::where('project_id',$id)->update(['visibility'=>$p->visibility]);
        return back()->with('success','Projeto atualizado.');
    }

    public function members(Request $request,string $id)
    {
        $u=$request->user();$p=$this->projects->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($p,404);
        $members=[];
        foreach((array)$request->input('members',[]) as $username=>$level){
            if($level)$members[]=['username'=>$username,'level'=>$level];
        }
        $this->projects->setMembers($p,$u->username,$members,$u->isAdmin());
        return back()->with('success','Participantes atualizados.');
    }

    public function assign(Request $request,string $id)
    {
        $u=$request->user();$p=$this->projects->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($p,404);
        $data=$request->validate(['flow_id'=>['required','string'],'role'=>['required','in:executive,operational,subprocess,support'],'group'=>['nullable','string','max:120'],'order'=>['nullable','integer','min:0']]);
        $flow=$this->flows->findAuthorized($data['flow_id'],$u->username,$u->isAdmin());abort_unless($flow,404);
        $this->projects->assignFlow($p,$flow,$u->username,$data['role'],$data['group']??'',(int)($data['order']??0),$u->isAdmin());
        return back()->with('success','Fluxo vinculado ao projeto.');
    }

    public function release(Request $request,string $id)
    {
        $u=$request->user();$p=$this->projects->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($p,404);
        $data=$request->validate(['name'=>['nullable','string','max:180'],'notes'=>['nullable','string','max:4000']]);
        $r=$this->projects->createRelease($p,$u->username,$u->isAdmin(),$data['name']??'',$data['notes']??'');
        return back()->with('success',"Release {$r->version} criada.");
    }

    public function destroy(Request $request,string $id)
    {
        $u=$request->user();$p=$this->projects->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($p,404);
        $this->projects->delete($p,$u->username,$request->boolean('delete_flows'),$u->isAdmin());
        return redirect()->route('projects.index')->with('success','Projeto excluído.');
    }
}
