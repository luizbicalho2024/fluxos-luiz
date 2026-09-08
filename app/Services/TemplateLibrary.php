<?php

namespace App\Services;

use Illuminate\Support\Str;

class TemplateLibrary
{
    public function __construct(private readonly FlowDocumentService $documents) {}

    public function builtIn(string $owner=''): array
    {
        $demo=$this->documents->demoDocument($owner);
        $approval=$this->documents->newDocument('Aprovação corporativa',$owner);
        $approval['lanes']=[
            ['id'=>'lane_requester','name'=>'Solicitante','owner'=>'Solicitante','orientation'=>'horizontal','order'=>1,'color'=>'#E8F5F0','collapsed'=>false,'enabled'=>true,'height'=>240],
            ['id'=>'lane_approver','name'=>'Aprovador','owner'=>'Aprovador','orientation'=>'horizontal','order'=>2,'color'=>'#EAF4FF','collapsed'=>false,'enabled'=>true,'height'=>240],
        ];
        $approval['nodes']=[
            $this->node('n1','start','lane_requester',80,72,'Criar solicitação','Registrar dados e anexos.','Solicitante',30,['solicitação'],'executive','approval','medium'),
            $this->node('n2','task','lane_approver',370,315,'Analisar solicitação','Validar critérios e documentação.','Aprovador',240,['análise'],'operational','approval','high'),
            $this->node('n3','decision','lane_approver',660,310,'Aprovar?','Decidir aprovação ou devolução.','Aprovador',60,['decisão'],'executive','approval','high'),
            $this->node('n4','end','lane_requester',970,65,'Solicitação devolvida','Corrigir e reenviar.','Solicitante',null,['exceção'],'operational','exception','medium'),
            $this->node('n5','end','lane_approver',970,315,'Solicitação aprovada','Encerrar aprovação.','Aprovador',null,['sucesso'],'executive','approval','medium'),
        ];
        $approval['edges']=[
            $this->edge('e1','n1','n2','Enviar'),$this->edge('e2','n2','n3','Analisado'),
            $this->edge('e3','n3','n5','Sim','Aprovado','branch-0'),$this->edge('e4','n3','n4','Não','Correção necessária','branch-1'),
        ];
        $approval=$this->documents->normalize($approval,$owner);

        $webhook=$this->documents->newDocument('Integração com webhook',$owner);
        $webhook['lanes']=[
            ['id'=>'lane_external','name'=>'Sistema externo','owner'=>'Fornecedor','orientation'=>'horizontal','order'=>1,'color'=>'#F5F7FA','collapsed'=>false,'enabled'=>true,'height'=>230],
            ['id'=>'lane_core','name'=>'Aplicação','owner'=>'Produto','orientation'=>'horizontal','order'=>2,'color'=>'#E8F5F0','collapsed'=>false,'enabled'=>true,'height'=>300],
        ];
        $webhook['nodes']=[
            $this->node('w1','start','lane_external',80,70,'Evento gerado','Fornecedor gera evento.','Fornecedor',null,['webhook'],'technical','integration','high'),
            $this->node('w2','api','lane_core',360,300,'Receber webhook','Autenticar e persistir payload bruto.','API',1,['api','idempotência'],'technical','integration','critical'),
            $this->node('w3','decision','lane_core',650,295,'Payload válido?','Validar assinatura e schema.','API',1,['validação'],'technical','integration','critical'),
            $this->node('w4','task','lane_core',940,260,'Processar evento','Executar regra idempotente.','Worker',5,['fila'],'technical','integration','high'),
            $this->node('w5','end','lane_core',1230,260,'Evento processado','Registrar sucesso.','Worker',null,['sucesso'],'technical','integration','medium'),
            $this->node('w6','end','lane_core',940,390,'Evento rejeitado','Registrar motivo sem alterar o estado.','API',null,['erro'],'technical','exception','high'),
        ];
        $webhook['edges']=[
            $this->edge('we1','w1','w2','HTTP'),$this->edge('we2','w2','w3','Validar'),
            $this->edge('we3','w3','w4','Sim','Assinatura e schema válidos','branch-0'),$this->edge('we4','w3','w6','Não','Payload inválido','branch-1'),
            $this->edge('we5','w4','w5','Concluído'),
        ];
        $webhook=$this->documents->normalize($webhook,$owner);

        return [
            ['id'=>'builtin_demo','name'=>'Fluxo demonstrativo','description'=>'Exemplo básico com decisão e raias.','category'=>'Geral','document'=>$demo,'builtin'=>true],
            ['id'=>'builtin_approval','name'=>'Aprovação corporativa','description'=>'Solicitação, análise, decisão e retorno.','category'=>'Governança','document'=>$approval,'builtin'=>true],
            ['id'=>'builtin_webhook','name'=>'Integração com webhook','description'=>'Recepção, validação, processamento e rejeição.','category'=>'Tecnologia','document'=>$webhook,'builtin'=>true],
        ];
    }

    public function clone(array $template,string $owner,?string $name=null): array
    {
        $doc=(array)($template['document']??[]);
        $fresh=$this->documents->newDocument($name?:((string)($template['name']??'Novo processo')),$owner);
        $fresh['flow']['description']=(string)($doc['flow']['description']??$template['description']??'');
        $fresh['flow']['tags']=array_values((array)($doc['flow']['tags']??[]));
        $doc['flow']=$fresh['flow'];
        return $this->documents->normalize($doc,$owner);
    }

    private function node(string $id,string $type,string $lane,float $x,float $y,string $label,string $description,string $owner,?int $sla,array $tags,string $level,string $category,string $criticality): array
    {
        return ['id'=>$id,'type'=>$type,'laneId'=>$lane,'position'=>['x'=>$x,'y'=>$y],'data'=>[
            'label'=>$label,'description'=>$description,'owner'=>$owner,'enabled'=>true,'locked'=>false,'slaMinutes'=>$sla,'tags'=>$tags,
            'level'=>$level,'category'=>$category,'criticality'=>$criticality,'linkedFlowId'=>null,'linkedFlowEntryNodeId'=>null,'linkedFlowExitNodeId'=>null,
            'preferredEdgeId'=>null,'documentationUrl'=>'','raci'=>['responsible'=>'','accountable'=>'','consulted'=>[],'informed'=>[]],
        ]];
    }

    private function edge(string $id,string $source,string $target,string $label,string $condition='',string $handle='output'): array
    {
        return ['id'=>$id,'source'=>$source,'target'=>$target,'sourceHandle'=>$handle,'targetHandle'=>'input','type'=>'step','label'=>$label,'enabled'=>true,'condition'=>$condition];
    }
}
