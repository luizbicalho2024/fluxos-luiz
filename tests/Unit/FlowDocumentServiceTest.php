<?php

namespace Tests\Unit;

use App\Services\FlowDocumentService;
use Tests\TestCase;

class FlowDocumentServiceTest extends TestCase
{
    public function test_demo_document_is_structurally_valid(): void
    {
        $service=app(FlowDocumentService::class);
        $document=$service->demoDocument('tester');
        self::assertSame([], $service->validate($document));
        self::assertGreaterThanOrEqual(1, $service->analyze($document)['counts']['decisions']);
    }

    public function test_resilient_import_converts_invalid_decision_without_inventing_business_rules(): void
    {
        $service=app(FlowDocumentService::class);
        $document=$service->demoDocument('tester');
        $decisionIndex=null;
        foreach($document['nodes'] as $i=>$node)if($node['type']==='decision'){$decisionIndex=$i;break;}
        self::assertNotNull($decisionIndex);
        $decisionId=$document['nodes'][$decisionIndex]['id'];
        $document['edges']=array_values(array_filter($document['edges'],fn($edge)=>$edge['source']!==$decisionId));
        $document['edges'][]=['id'=>'single','source'=>$decisionId,'target'=>$document['nodes'][0]['id'],'sourceHandle'=>'branch-0','targetHandle'=>'input','type'=>'step','label'=>'','condition'=>'','enabled'=>true];
        [$fixed,$warnings]=$service->repairImport($document,'tester');
        $fixedDecision=collect($fixed['nodes'])->firstWhere('id',$decisionId);
        self::assertSame('task',$fixedDecision['type']);
        self::assertContains('Importação corrigida',$fixedDecision['data']['tags']);
        self::assertNotEmpty($warnings);
    }

    public function test_quality_analysis_returns_actionable_issue_rows(): void
    {
        $service=app(FlowDocumentService::class);
        $document=$service->newDocument('Qualidade','tester');
        $document['nodes'][]=['id'=>'n1','type'=>'start','laneId'=>$document['lanes'][0]['id'],'position'=>['x'=>80,'y'=>90],'data'=>['label'=>'Início','description'=>'','owner'=>'','enabled'=>true,'locked'=>false,'slaMinutes'=>null,'tags'=>[],'level'=>'operational','category'=>'process','criticality'=>'high']];
        $analysis=$service->analyze($service->normalize($document,'tester'));
        self::assertGreaterThan(0,$analysis['issue_count']);
        self::assertArrayHasKey('Problema',$analysis['issue_details'][0]);
        self::assertArrayHasKey('Como corrigir',$analysis['issue_details'][0]);
    }
}
