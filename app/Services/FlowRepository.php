<?php

namespace App\Services;

use App\Models\FlowApproval;
use App\Models\FlowComment;
use App\Models\FlowDraft;
use App\Models\FlowVersion;
use App\Models\Flowchart;
use Illuminate\Support\Str;

class FlowRepository
{
    public const ACCESS_LEVELS = ['viewer','editor','reviewer','approver'];

    public function __construct(private readonly FlowDocumentService $documents) {}

    public function permissionFor(Flowchart $flow, string $username, bool $isAdmin = false): ?string
    {
        $username = strtolower(trim($username));
        if ($isAdmin || strtolower((string)$flow->owner_username) === $username) return 'owner';
        foreach ((array)($flow->collaborators ?? []) as $item) {
            if (strtolower((string)($item['username'] ?? '')) === $username) {
                $level = (string)($item['level'] ?? 'viewer');
                return in_array($level, self::ACCESS_LEVELS, true) ? $level : 'viewer';
            }
        }
        return $flow->visibility === 'organization' ? 'viewer' : null;
    }

    public function canEdit(?string $permission): bool
    {
        return in_array($permission, ['owner','editor','reviewer','approver'], true);
    }

    public function canReview(?string $permission): bool
    {
        return in_array($permission, ['owner','reviewer','approver'], true);
    }

    public function canApprove(?string $permission): bool
    {
        return in_array($permission, ['owner','approver'], true);
    }

    public function visibleTo(string $username, bool $isAdmin = false, ?string $projectId = null)
    {
        $query = Flowchart::query();
        if (!$isAdmin) {
            $query->where(function ($q) use ($username) {
                $q->where('owner_username', $username)
                  ->orWhere('collaborators.username', $username)
                  ->orWhere('visibility', 'organization');
            });
        }
        if ($projectId) $query->where('project_id', $projectId);
        return $query->orderBy('updated_at', 'desc')->get();
    }

    public function findAuthorized(string $id, string $username, bool $isAdmin = false): ?Flowchart
    {
        $flow = Flowchart::find($id);
        if (!$flow) return null;
        return $this->permissionFor($flow, $username, $isAdmin) ? $flow : null;
    }

    public function create(string $name, string $username, string $email = '', ?string $projectId = null): Flowchart
    {
        $doc = $this->documents->newDocument($name, $email ?: $username);
        if ($projectId) $doc['flow']['projectId'] = $projectId;
        return $this->save($doc, $username, $email, null, $username, true, 'create');
    }

    public function save(
        array $document,
        string $ownerUsername,
        string $ownerEmail = '',
        ?int $expectedRevision = null,
        ?string $actorUsername = null,
        bool $createVersion = true,
        string $reason = 'manual',
        bool $isAdmin = false,
        bool $force = false,
    ): Flowchart {
        $ownerUsername = strtolower(trim($ownerUsername));
        $actor = strtolower(trim($actorUsername ?: $ownerUsername));
        $doc = $this->documents->normalize($document, $ownerEmail ?: $ownerUsername);
        $errors = $this->documents->validate($doc);
        if ($errors) throw new \InvalidArgumentException('Documento inválido: '.implode(' | ', array_slice($errors, 0, 12)));

        $id = (string)$doc['flow']['id'];
        $existing = Flowchart::find($id);
        $now = now();

        if ($existing) {
            $permission = $this->permissionFor($existing, $actor, $isAdmin);
            if (!$this->canEdit($permission)) throw new \Symfony\Component\HttpKernel\Exception\HttpException(403, 'Seu perfil não possui permissão para editar este fluxo.');

            $currentRevision = (int)($existing->revision ?: 1);
            if (!$force && $expectedRevision !== null && $currentRevision !== $expectedRevision) {
                throw new RevisionConflictException($currentRevision, $existing->toArray());
            }

            $nextRevision = $currentRevision + 1;
            $nextVersion = (int)($existing->current_version ?: 1) + ($createVersion ? 1 : 0);
            $status = (string)($existing->workflow_status ?: $existing->status ?: 'draft');
            if ($status === 'active') $status = 'published';
            if ($status === 'published' && !in_array($reason, ['publish','archive'], true)) $status = 'draft';

            $payload = [
                'name' => trim((string)$doc['flow']['name']),
                'description' => (string)($doc['flow']['description'] ?? ''),
                'status' => $status,
                'workflow_status' => $status,
                'tags' => array_values((array)($doc['flow']['tags'] ?? [])),
                'current_version' => $nextVersion,
                'revision' => $nextRevision,
                'document' => $doc,
                'document_hash' => $this->documents->hash($doc),
                'updated_at' => $now,
                'last_saved_by' => $actor,
                'project_id' => (string)($doc['flow']['projectId'] ?? $existing->project_id ?? ''),
                'project_role' => (string)($doc['flow']['projectRole'] ?? $existing->project_role ?? ''),
                'project_group' => (string)($doc['flow']['projectGroup'] ?? $existing->project_group ?? ''),
                'project_order' => (int)($doc['flow']['projectOrder'] ?? $existing->project_order ?? 0),
            ];

            $q = Flowchart::where('_id', $id);
            if (!$force) $q->where('revision', $currentRevision);
            if ($q->update($payload) < 1) {
                $latest = Flowchart::find($id);
                throw new RevisionConflictException((int)($latest?->revision ?: $currentRevision), $latest?->toArray() ?? []);
            }
            $flow = Flowchart::findOrFail($id);
            $before = (array)$existing->document;
        } else {
            $status = (string)($doc['flow']['status'] ?? 'draft');
            $nextRevision = 1;
            $nextVersion = 1;
            $flow = Flowchart::create([
                '_id'=>$id,'id'=>$id,
                'name'=>trim((string)$doc['flow']['name']),
                'description'=>(string)($doc['flow']['description'] ?? ''),
                'status'=>$status,'workflow_status'=>$status,
                'tags'=>array_values((array)($doc['flow']['tags'] ?? [])),
                'owner_username'=>$ownerUsername,'owner_email'=>strtolower(trim($ownerEmail)),
                'current_version'=>1,'published_version'=>null,'revision'=>1,
                'visibility'=>'private','collaborators'=>[],
                'document'=>$doc,'document_hash'=>$this->documents->hash($doc),
                'created_at'=>$now,'updated_at'=>$now,'last_saved_by'=>$actor,
                'project_id'=>(string)($doc['flow']['projectId'] ?? ''),
                'project_role'=>(string)($doc['flow']['projectRole'] ?? ''),
                'project_group'=>(string)($doc['flow']['projectGroup'] ?? ''),
                'project_order'=>(int)($doc['flow']['projectOrder'] ?? 0),
            ]);
            $before = [];
        }

        if ($createVersion) {
            $version = FlowVersion::where('flowchart_id', $id)->where('version', $nextVersion)->first();
            if (!$version) {
                $version = new FlowVersion();
                $version->_id = "{$id}:{$nextVersion}";
                $version->flowchart_id = $id;
                $version->version = $nextVersion;
            }
            $version->document = $doc;
            $version->document_hash = $this->documents->hash($doc);
            $version->created_by = $actor;
            $version->created_at = $now;
            $version->reason = $reason;
            $version->diff_summary = $this->diffSummary($before, $doc);
            $version->save();
        }

        FlowDraft::where('flowchart_id', $id)->where('username', $actor)->delete();
        ActivityLogger::add($actor, 'Salvou fluxo no Fluxos Luiz', [
            'flowchart_id'=>$id,'version'=>$nextVersion,'revision'=>$nextRevision,'reason'=>$reason
        ]);
        return $flow->fresh();
    }

    private function diffSummary(array $before, array $after): array
    {
        if (!$before) return ['created'=>true];
        $beforeNodes = collect($before['nodes'] ?? [])->keyBy('id');
        $afterNodes = collect($after['nodes'] ?? [])->keyBy('id');
        return [
            'nodes_added'=>$afterNodes->keys()->diff($beforeNodes->keys())->values()->all(),
            'nodes_removed'=>$beforeNodes->keys()->diff($afterNodes->keys())->values()->all(),
            'node_count_before'=>$beforeNodes->count(),
            'node_count_after'=>$afterNodes->count(),
            'edge_count_before'=>count($before['edges'] ?? []),
            'edge_count_after'=>count($after['edges'] ?? []),
        ];
    }

    public function saveDraft(Flowchart $flow, string $username, array $document, int $baseRevision): FlowDraft
    {
        $username = strtolower(trim($username));
        $doc = $this->documents->normalize($document, $username);
        $draft = FlowDraft::where('flowchart_id', (string)$flow->_id)->where('username', $username)->first();
        if (!$draft) {
            $draft = new FlowDraft();
            $draft->_id = (string)$flow->_id.':'.$username;
            $draft->flowchart_id = (string)$flow->_id;
            $draft->username = $username;
        }
        $draft->project_id = (string)($doc['flow']['projectId'] ?? '');
        $draft->base_revision = $baseRevision;
        $draft->document = $doc;
        $draft->updated_at = now();
        $draft->save();
        return $draft;
    }

    public function discardDraft(Flowchart $flow, string $username): void
    {
        FlowDraft::where('flowchart_id',(string)$flow->_id)->where('username',strtolower(trim($username)))->delete();
    }

    public function transition(Flowchart $flow, string $actor, string $action, string $comment = '', bool $isAdmin = false): array
    {
        $permission = $this->permissionFor($flow, $actor, $isAdmin);
        $current = (string)($flow->workflow_status ?: $flow->status ?: 'draft');
        if ($current === 'active') $current = 'published';

        $transitions = [
            'submit_review'=>[['draft'],'in_review','edit'],
            'request_changes'=>[['in_review','approved'],'draft','review'],
            'approve'=>[['in_review'],'approved','approve'],
            'publish'=>[['approved'],'published','approve'],
            'archive'=>[['published','approved','draft'],'archived','approve'],
            'reopen'=>[['archived'],'draft','approve'],
        ];
        if (!isset($transitions[$action])) throw new \InvalidArgumentException('Ação de governança inválida.');
        [$from,$target,$ability] = $transitions[$action];
        if (!in_array($current,$from,true)) throw new \InvalidArgumentException("A transição {$action} não é permitida a partir de {$current}.");
        $allowed = $ability==='edit' ? $this->canEdit($permission) : ($ability==='review' ? $this->canReview($permission) : $this->canApprove($permission));
        if (!$allowed) throw new \Symfony\Component\HttpKernel\Exception\HttpException(403,'Seu perfil não possui permissão para esta transição.');

        $update = ['workflow_status'=>$target,'status'=>$target,'updated_at'=>now(),'last_saved_by'=>$actor];
        if ($target==='published') {
            $update['published_version']=(int)($flow->current_version ?: 1);
            $update['published_at']=now();
            $update['published_by']=$actor;
        }
        Flowchart::where('_id',(string)$flow->_id)->update($update);
        FlowApproval::create([
            '_id'=>'approval_'.Str::lower(Str::random(16)),
            'flowchart_id'=>(string)$flow->_id,'from_status'=>$current,'to_status'=>$target,
            'action'=>$action,'comment'=>trim($comment),'created_by'=>$actor,'created_at'=>now(),
        ]);
        ActivityLogger::add($actor,'Alterou status de governança',['flowchart_id'=>(string)$flow->_id,'from'=>$current,'to'=>$target,'action'=>$action]);
        return ['from'=>$current,'to'=>$target];
    }

    public function addComment(Flowchart $flow, string $author, string $content, string $targetKind='flow', string $targetId='', array $mentions=[]): FlowComment
    {
        if (trim($content)==='') throw new \InvalidArgumentException('O comentário não pode ficar vazio.');
        return FlowComment::create([
            '_id'=>'comment_'.Str::lower(Str::random(16)),
            'flowchart_id'=>(string)$flow->_id,'target_kind'=>$targetKind,'target_id'=>$targetId,
            'content'=>trim($content),'author'=>strtolower($author),
            'mentions'=>array_values(array_unique(array_map('strtolower',$mentions))),
            'resolved'=>false,'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    public function duplicate(Flowchart $flow, string $actor, string $email=''): Flowchart
    {
        $doc = (array)$flow->document;
        $doc['flow']['id']='flow_'.Str::lower(Str::random(12));
        $doc['flow']['name']='Cópia de '.($doc['flow']['name'] ?? 'Processo');
        $doc['flow']['status']='draft';
        $doc['flow']['createdAt']=now()->toIso8601String();
        $doc['flow']['updatedAt']=now()->toIso8601String();
        $doc['flow']['createdBy']=$actor;
        return $this->save($doc,$actor,$email,null,$actor,true,'duplicate');
    }

    public function delete(Flowchart $flow, string $actor, bool $isAdmin=false): void
    {
        if ($this->permissionFor($flow,$actor,$isAdmin)!=='owner') {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(403,'Somente o proprietário pode excluir o fluxo.');
        }
        $id=(string)$flow->_id;
        foreach ([FlowVersion::class,FlowDraft::class,FlowComment::class,FlowApproval::class] as $model) {
            $model::where('flowchart_id',$id)->delete();
        }
        $flow->delete();
        ActivityLogger::add($actor,'Excluiu fluxo',['flowchart_id'=>$id]);
    }
}
