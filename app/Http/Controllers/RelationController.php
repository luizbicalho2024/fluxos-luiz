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

    public function data(Request $request,string $projectId)
    {
        $u=$request->user();$p=$this->projects->findAuthorized($projectId,$u->username,$u->isAdmin());abort_unless($p,404);
        $graph=$this->projects->relations($p,$u->username,$u->isAdmin());
        return response()->json([
            'nodes'=>$graph['flows']->map(fn($f)=>['id'=>(string)$f->_id,'name'=>$f->name,'status'=>$f->workflow_status?:$f->status,'role'=>$f->project_role])->values(),
            'links'=>collect($graph['links'])->map(fn($l)=>['source'=>$l['source_flow_id'],'target'=>$l['target_flow_id'],'label'=>$l['source_node_label']])->values(),
            'broken'=>$graph['broken'],
        ]);
    }
}
