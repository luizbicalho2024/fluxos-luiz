<?php

namespace App\Http\Controllers;

use App\Models\FlowApproval;
use App\Models\FlowComment;
use App\Models\FlowDraft;
use App\Models\FlowTemplate;
use App\Models\FlowVersion;
use App\Models\Project;
use App\Models\User;
use App\Services\FlowDocumentService;
use App\Services\FlowExportService;
use App\Services\FlowRepository;
use App\Services\RevisionConflictException;
use App\Services\TemplateLibrary;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FlowController extends Controller
{
    public function __construct(
        private readonly FlowRepository $flows,
        private readonly FlowDocumentService $documents,
        private readonly FlowExportService $exports,
        private readonly TemplateLibrary $templateLibrary,
    ) {}

    public function index(Request $request)
    {
        $u=$request->user();$flows=$this->flows->visibleTo($u->username,$u->isAdmin());
        $projects=Project::orderBy('name')->get()->keyBy(fn($p)=>(string)$p->_id);
        $rows=$flows->map(function($flow) use($projects){$analysis=$this->documents->analyze((array)$flow->document);$openComments=FlowComment::where('flowchart_id',(string)$flow->_id)->where('resolved',false)->count();return [
            'flow'=>$flow,'analysis'=>$analysis,'open_comments'=>$openComments,'project_name'=>$projects[(string)($flow->project_id??'')]->name??'Fluxo avulso',
        ];});
        return view('flows.index',compact('flows','rows','projects'));
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
        $versions=$this->flows->versions($f,100);
        $comments=FlowComment::where('flowchart_id',$id)->orderBy('created_at','desc')->limit(200)->get();
        $approvals=FlowApproval::where('flowchart_id',$id)->orderBy('created_at','desc')->limit(200)->get();
        $projects=Project::orderBy('name')->get();
        $project=$f->project_id?Project::find((string)$f->project_id):null;
        $flowCatalog=$f->project_id?$this->flows->visibleTo($u->username,$u->isAdmin(),(string)$f->project_id):$this->flows->visibleTo($u->username,$u->isAdmin())->filter(fn($x)=>(string)($x->project_id??'')==='')->values();
        $users=User::where('active',true)->orderBy('name')->get();
        $customTemplates=$this->flows->listTemplates($u->username,$u->isAdmin());
        $builtInTemplates=$this->templateLibrary->builtIn($u->username);
        $presence=$this->flows->touchPresence($f,$u->username,(string)$u->name);
        $analysis=$this->documents->analyze((array)$f->document);
        return view('flows.editor',compact('f','permission','draft','versions','comments','approvals','projects','project','flowCatalog','users','customTemplates','builtInTemplates','presence','analysis'));
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

    public function validateDocument(Request $request,string $id)
    {
        $u=$request->user();$f=$this->flows->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($f,404);
        $data=$request->validate(['document'=>['required','array']]);$doc=$this->documents->normalize($data['document'],$u->username);$doc['flow']['id']=$id;
        return response()->json(['ok'=>true,'errors'=>$this->documents->validate($doc),'analysis'=>$this->documents->analyze($doc)]);
    }

    public function analyzeDocument(Request $request,string $id)
    {
        $u=$request->user();$f=$this->flows->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($f,404);
        $data=$request->validate(['document'=>['required','array']]);$doc=$this->documents->normalize($data['document'],$u->username);$doc['flow']['id']=$id;
        return response()->json(['ok'=>true,'analysis'=>$this->documents->analyze($doc),'raci'=>$this->documents->buildRaciRows($doc)]);
    }

    public function resolveConflict(Request $request,string $id)
    {
        $u=$request->user();
        $f=$this->flows->findAuthorized($id,$u->username,$u->isAdmin());
        abort_unless($f,404);
        $data=$request->validate([
            'action'=>['required','in:copy,overwrite'],
            'document'=>['required','array'],
            'current_revision'=>['required','integer','min:1'],
        ]);

        $doc=$this->documents->normalize($data['document'],$u->username);

        if($data['action']==='copy'){
            $newId='flow_'.Str::lower(Str::random(12));
            $doc['flow']['id']=$newId;
            $doc['flow']['name']='Cópia conflitante de '.trim((string)($doc['flow']['name']??$f->name??'Processo'));
            $doc['flow']['status']='draft';
            $doc['flow']['createdBy']=$u->username;
            $doc['flow']['createdAt']=now()->toIso8601String();
            $doc['flow']['updatedAt']=now()->toIso8601String();
            $saved=$this->flows->save($doc,$u->username,$u->email,null,$u->username,true,'conflict_copy',$u->isAdmin());
            return response()->json(['ok'=>true,'action'=>'copy','flow'=>$saved,'url'=>route('flows.editor',$saved->_id)]);
        }

        $permission=$this->flows->permissionFor($f,$u->username,$u->isAdmin());
        abort_unless($permission==='owner',403,'Somente o proprietário ou administrador pode sobrescrever a versão atual.');
        $latest=$this->flows->findAuthorized($id,$u->username,$u->isAdmin());
        abort_unless($latest,404);
        if((int)$latest->revision!==(int)$data['current_revision']){
            return response()->json([
                'ok'=>false,'conflict'=>true,
                'message'=>'O fluxo foi alterado novamente antes da resolução do conflito.',
                'current_revision'=>(int)$latest->revision,
                'current_record'=>$latest->toArray(),
            ],409);
        }
        $doc['flow']['id']=$id;
        try{
            $saved=$this->flows->save($doc,$latest->owner_username,$latest->owner_email,(int)$latest->revision,$u->username,true,'force_conflict',$u->isAdmin());
            return response()->json(['ok'=>true,'action'=>'overwrite','flow'=>$saved,'analysis'=>$this->documents->analyze((array)$saved->document)]);
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
        $this->flows->discardDraft($f,$u->username);return response()->json(['ok'=>true]);
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
        $data=$request->validate(['content'=>['required','string','max:4000'],'target_kind'=>['nullable','string','max:40'],'target_id'=>['nullable','string','max:160'],'mentions'=>['nullable','array'],'mentions.*'=>['string','max:100']]);
        $comment=$this->flows->addComment($f,$u->username,$data['content'],$data['target_kind']??'flow',$data['target_id']??'',(array)($data['mentions']??[]));
        return response()->json(['ok'=>true,'comment'=>$comment]);
    }

    public function resolveComment(Request $request,string $commentId)
    {
        $comment=FlowComment::findOrFail($commentId);$u=$request->user();$f=$this->flows->findAuthorized((string)$comment->flowchart_id,$u->username,$u->isAdmin());abort_unless($f,404);
        $comment->resolved=$request->boolean('resolved',true);$comment->resolved_by=$u->username;$comment->resolved_at=now();$comment->updated_at=now();$comment->save();
        return response()->json(['ok'=>true,'comment'=>$comment]);
    }

    public function import(Request $request,string $id)
    {
        $u=$request->user();$f=$this->flows->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($f,404);
        $data=$request->validate(['document'=>['required','array']]);[$doc,$warnings]=$this->documents->repairImport($data['document'],$u->username);$doc['flow']['id']=$id;
        return response()->json(['ok'=>true,'document'=>$doc,'warnings'=>$warnings,'errors'=>$this->documents->validate($doc),'analysis'=>$this->documents->analyze($doc)]);
    }

    public function version(Request $request,string $id,int $version)
    {
        $u=$request->user();$f=$this->flows->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($f,404);$doc=$this->flows->versionDocument($f,$version);abort_unless($doc,404);
        return response()->json(['ok'=>true,'version'=>$version,'document'=>$doc]);
    }

    public function compareVersions(Request $request,string $id)
    {
        $u=$request->user();$f=$this->flows->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($f,404);$data=$request->validate(['left'=>['required','integer','min:1'],'right'=>['required','integer','min:1']]);
        return response()->json(['ok'=>true,'diff'=>$this->flows->compareVersions($f,(int)$data['left'],(int)$data['right'])]);
    }

    public function restoreVersion(Request $request,string $id,int $version)
    {
        $u=$request->user();$f=$this->flows->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($f,404);$saved=$this->flows->restoreVersion($f,$version,$u->username,$u->isAdmin());
        return response()->json(['ok'=>true,'flow'=>$saved,'analysis'=>$this->documents->analyze((array)$saved->document)]);
    }

    public function sharing(Request $request,string $id)
    {
        $u=$request->user();$f=$this->flows->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($f,404);
        $data=$request->validate(['visibility'=>['required','in:private,organization'],'collaborators'=>['nullable','array'],'collaborators.*.username'=>['required_with:collaborators','string'],'collaborators.*.level'=>['required_with:collaborators','in:viewer,editor,reviewer,approver']]);
        $saved=$this->flows->setCollaborators($f,$u->username,(array)($data['collaborators']??[]),$data['visibility'],$u->isAdmin());return response()->json(['ok'=>true,'flow'=>$saved]);
    }

    public function presence(Request $request,string $id)
    {
        $u=$request->user();$f=$this->flows->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($f,404);return response()->json(['ok'=>true,'presence'=>$this->flows->touchPresence($f,$u->username,(string)$u->name)]);
    }

    public function createTemplate(Request $request,string $id)
    {
        $u=$request->user();$f=$this->flows->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($f,404);$data=$request->validate(['name'=>['required','string','max:180'],'description'=>['nullable','string','max:1000'],'category'=>['nullable','string','max:80'],'document'=>['required','array'],'organization'=>['nullable','boolean']]);
        $template=$this->flows->createTemplate($data['name'],$data['description']??'',$data['category']??'Geral',$data['document'],$u->username,$u->isAdmin()&&$request->boolean('organization'));
        return response()->json(['ok'=>true,'template'=>$template]);
    }

    public function deleteTemplate(Request $request,string $templateId)
    {
        $u=$request->user();$this->flows->deleteTemplate($templateId,$u->username,$u->isAdmin());return response()->json(['ok'=>true]);
    }

    public function createFromTemplate(Request $request)
    {
        $u=$request->user();$data=$request->validate(['template_id'=>['required','string'],'name'=>['nullable','string','max:180'],'project_id'=>['nullable','string']]);
        $template=null;if(str_starts_with($data['template_id'],'builtin_')){$template=collect($this->templateLibrary->builtIn($u->username))->firstWhere('id',$data['template_id']);}else{$model=FlowTemplate::find($data['template_id']);if($model)$template=$model->toArray();}
        abort_unless($template,404);$doc=$this->templateLibrary->clone($template,$u->username,$data['name']??null);
        if(!empty($data['project_id'])){$doc['flow']['projectId']=$data['project_id'];$doc['flow']['projectRole']='subprocess';$doc['flow']['projectGroup']='Geral';}
        $saved=$this->flows->save($doc,$u->username,$u->email,null,$u->username,true,'template',$u->isAdmin());return response()->json(['ok'=>true,'flow'=>$saved,'url'=>route('flows.editor',$saved->_id)]);
    }

    public function export(Request $request,string $id,string $format)
    {
        $u=$request->user();$f=$this->flows->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($f,404);$data=$request->validate(['document'=>['required','array']]);$doc=$this->documents->normalize($data['document'],$u->username);$doc['flow']['id']=$id;$meta=['version'=>(int)$f->current_version,'revision'=>(int)$f->revision];$base=$this->exports->baseName($doc,$meta);
        return match($format){
            'svg'=>response($this->exports->diagramSvg($doc),200,['Content-Type'=>'image/svg+xml','Content-Disposition'=>'attachment; filename="'.$base.'.svg"']),
            'pdf'=>response($this->exports->diagramPdf($doc,$meta),200,['Content-Type'=>'application/pdf','Content-Disposition'=>'attachment; filename="'.$base.'_diagrama.pdf"']),
            'documentation-pdf'=>response($this->exports->documentationPdf($doc,$meta),200,['Content-Type'=>'application/pdf','Content-Disposition'=>'attachment; filename="'.$base.'_documentacao.pdf"']),
            'html'=>response($this->exports->htmlReport($doc,$meta),200,['Content-Type'=>'text/html; charset=UTF-8','Content-Disposition'=>'attachment; filename="'.$base.'.html"']),
            'nodes-csv'=>response($this->exports->nodesCsv($doc),200,['Content-Type'=>'text/csv; charset=UTF-8','Content-Disposition'=>'attachment; filename="'.$base.'_cards.csv"']),
            'raci-csv'=>response($this->exports->raciCsv($doc),200,['Content-Type'=>'text/csv; charset=UTF-8','Content-Disposition'=>'attachment; filename="'.$base.'_raci.csv"']),
            'bundle'=>response($this->exports->bundle($doc,$meta),200,['Content-Type'=>'application/zip','Content-Disposition'=>'attachment; filename="'.$base.'_completo.zip"']),
            default=>abort(404),
        };
    }

    public function duplicate(Request $request,string $id)
    {
        $u=$request->user();$f=$this->flows->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($f,404);$copy=$this->flows->duplicate($f,$u->username,$u->email);return redirect()->route('flows.editor',$copy->_id)->with('success','Fluxo duplicado.');
    }

    public function destroy(Request $request,string $id)
    {
        $u=$request->user();$f=$this->flows->findAuthorized($id,$u->username,$u->isAdmin());abort_unless($f,404);$this->flows->delete($f,$u->username,$u->isAdmin());return redirect()->route('flows.index')->with('success','Fluxo excluído.');
    }
}
