<?php

namespace App\Http\Controllers;

use App\Models\FlowApproval;
use App\Models\FlowComment;
use App\Models\FlowDraft;
use App\Models\FlowVersion;
use App\Models\Project;
use App\Services\FlowDocumentService;
use App\Services\FlowRepository;
use App\Services\RevisionConflictException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FlowController extends Controller
{
    public function __construct(private readonly FlowRepository $flows,private readonly FlowDocumentService $documents){}

    public function index(Request $request)
    {
        $u=$request->user();$flows=$this->flows->visibleTo($u->username,$u->isAdmin());
        return view('flows.index',compact('flows'));
    }

    public function store(Request $request)
    {
        $data=$request->validate(['name'=>['required','string','max:180'],'project_id'=>['nullable','string']]);
        $u=$request->user();$f=$this->flows->create($data['name'],$u->username,$u->email,$data['project_id']??null);
        return redirect()->route('flows.editor',$f->_id)->with('success','Fluxo criado.');
    }

    public function editor(Request $request,string $id)
    {
        $u=$request->user();$f=$this->flows->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($f,404);
        $permission=$this->flows->permissionFor($f,$u->username,$u->isAdmin());
        $draft=FlowDraft::where('flowchart_id',$id)->where('username',$u->username)->first();
        $versions=FlowVersion::where('flowchart_id',$id)->orderBy('version','desc')->limit(30)->get();
        $comments=FlowComment::where('flowchart_id',$id)->orderBy('created_at','desc')->limit(100)->get();
        $approvals=FlowApproval::where('flowchart_id',$id)->orderBy('created_at','desc')->limit(100)->get();
        $projects=Project::orderBy('name')->get();
        return view('flows.editor',compact('f','permission','draft','versions','comments','approvals','projects'));
    }

    public function save(Request $request,string $id)
    {
        $u=$request->user();$f=$this->flows->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($f,404);
        $data=$request->validate(['document'=>['required','array'],'revision'=>['required','integer','min:1'],'reason'=>['nullable','string','max:80']]);
        $doc=$data['document'];$doc['flow']['id']=$id;
        try{
            $saved=$this->flows->save($doc,$f->owner_username,$f->owner_email,(int)$data['revision'],$u->username,true,$data['reason']??'manual',$u->isAdmin());
            return response()->json(['ok'=>true,'flow'=>$saved,'analysis'=>$this->documents->analyze((array)$saved->document)]);
        }catch(RevisionConflictException $e){
            return response()->json(['ok'=>false,'conflict'=>true,'message'=>$e->getMessage(),'current_revision'=>$e->currentRevision,'current_record'=>$e->currentRecord],409);
        }
    }

    public function saveDraft(Request $request,string $id)
    {
        $u=$request->user();$f=$this->flows->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($f,404);
        $data=$request->validate(['document'=>['required','array'],'base_revision'=>['required','integer','min:1']]);
        $draft=$this->flows->saveDraft($f,$u->username,$data['document'],(int)$data['base_revision']);
        return response()->json(['ok'=>true,'draft'=>$draft]);
    }

    public function discardDraft(Request $request,string $id)
    {
        $u=$request->user();$f=$this->flows->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($f,404);
        $this->flows->discardDraft($f,$u->username);
        return response()->json(['ok'=>true]);
    }

    public function transition(Request $request,string $id)
    {
        $u=$request->user();$f=$this->flows->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($f,404);
        $data=$request->validate(['action'=>['required','in:submit_review,request_changes,approve,publish,archive,reopen'],'comment'=>['nullable','string','max:2000']]);
        return response()->json(['ok'=>true,'transition'=>$this->flows->transition($f,$u->username,$data['action'],$data['comment']??'',$u->isAdmin())]);
    }

    public function comment(Request $request,string $id)
    {
        $u=$request->user();$f=$this->flows->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($f,404);
        $data=$request->validate(['content'=>['required','string','max:4000'],'target_kind'=>['nullable','string','max:40'],'target_id'=>['nullable','string','max:160']]);
        $comment=$this->flows->addComment($f,$u->username,$data['content'],$data['target_kind']??'flow',$data['target_id']??'');
        return response()->json(['ok'=>true,'comment'=>$comment]);
    }

    public function resolveComment(Request $request,string $commentId)
    {
        $comment=FlowComment::findOrFail($commentId);
        $u=$request->user();$f=$this->flows->findAuthorized($comment->flowchart_id,$u->username,$u->isAdmin());abort_unless($f,404);
        $comment->resolved=$request->boolean('resolved',true);$comment->resolved_by=$u->username;$comment->resolved_at=now();$comment->updated_at=now();$comment->save();
        return response()->json(['ok'=>true]);
    }

    public function import(Request $request,string $id)
    {
        $u=$request->user();$f=$this->flows->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($f,404);
        $data=$request->validate(['document'=>['required','array']]);
        [$doc,$warnings]=$this->documents->repairImport($data['document'],$u->username);
        $doc['flow']['id']=$id;
        return response()->json(['ok'=>true,'document'=>$doc,'warnings'=>$warnings,'errors'=>$this->documents->validate($doc)]);
    }

    public function duplicate(Request $request,string $id)
    {
        $u=$request->user();$f=$this->flows->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($f,404);
        $copy=$this->flows->duplicate($f,$u->username,$u->email);
        return redirect()->route('flows.editor',$copy->_id)->with('success','Fluxo duplicado.');
    }

    public function destroy(Request $request,string $id)
    {
        $u=$request->user();$f=$this->flows->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($f,404);
        $this->flows->delete($f,$u->username,$u->isAdmin());
        return redirect()->route('flows.index')->with('success','Fluxo excluído.');
    }
}
