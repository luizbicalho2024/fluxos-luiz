<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class EditorParityContractTest extends TestCase
{
    public function test_professional_editor_keeps_expected_feature_contracts(): void
    {
        $js=file_get_contents(__DIR__.'/../../public/assets/flow-editor.js');
        $blade=file_get_contents(__DIR__.'/../../resources/views/flows/editor.blade.php');
        $routes=file_get_contents(__DIR__.'/../../routes/web.php');
        $combined=$js."\n".$blade."\n".$routes;

        foreach([
            'undo','redo','startMarquee','duplicateSelection','alignSelection','distributeSelection',
            'route-explorer','focus-path','startPlayback','showMiniMap','edgeRouting','autoFitLanes',
            'compare-versions','save-sharing','create-template','documentation-pdf','raci-csv','bundle',
            'beforeunload','linkedFlowId','linkedFlowEntryNodeId','linkedFlowExitNodeId','preferredEdgeId','raci',
            'resolveConflict','conflictCopy','conflictOverwrite','resolver-conflito',
        ] as $needle) {
            self::assertStringContainsString($needle,$combined,$needle);
        }
    }
}
