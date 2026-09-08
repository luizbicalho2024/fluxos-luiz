<?php

namespace Tests\Unit;

use App\Services\ProjectBundleService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ZipArchive;

class ProjectBundleCompatibilityTest extends TestCase
{
    private function serviceWithoutConstructor(): ProjectBundleService
    {
        return (new ReflectionClass(ProjectBundleService::class))->newInstanceWithoutConstructor();
    }

    private function makeZip(array $files): string
    {
        $tmp=tempnam(sys_get_temp_dir(),'bundle_test_');
        $zip=new ZipArchive();
        self::assertTrue($zip->open($tmp,ZipArchive::OVERWRITE)===true);
        foreach($files as $name=>$content){
            $zip->addFromString($name,$content);
        }
        $zip->close();
        $bytes=file_get_contents($tmp);
        @unlink($tmp);
        self::assertIsString($bytes);
        return $bytes;
    }

    private function flow(string $id,string $name='Fluxo legado'): array
    {
        return [
            'schemaVersion'=>'2.0.0',
            'flow'=>['id'=>$id,'name'=>$name,'status'=>'draft'],
            'settings'=>[],
            'viewport'=>['x'=>0,'y'=>0,'zoom'=>1],
            'lanes'=>[],
            'nodes'=>[],
            'edges'=>[],
        ];
    }

    public function test_import_parser_accepts_legacy_produto_tools_manifest(): void
    {
        $manifest=[
            'project'=>[
                'id'=>'project_sigyo',
                'name'=>'SIGYO Modular',
                'description'=>'Pacote legado',
                'defaultFlowId'=>'flow_exec',
            ],
            'flows'=>[[
                'flowId'=>'flow_exec',
                'name'=>'Visão executiva',
                'role'=>'executive',
                'group'=>'Principal',
                'order'=>1,
                'file'=>'flows/flow_exec.json',
            ]],
        ];
        $bytes=$this->makeZip([
            'project.json'=>json_encode($manifest,JSON_UNESCAPED_UNICODE),
            'flows/flow_exec.json'=>json_encode($this->flow('flow_exec'),JSON_UNESCAPED_UNICODE),
        ]);

        $parsed=$this->serviceWithoutConstructor()->parseZip($bytes);

        self::assertSame('flow_exec',$parsed['project']['default_flow_id']);
        self::assertArrayHasKey('flow_exec',$parsed['documents']);
        self::assertSame('executive',$parsed['documents']['flow_exec']['flow']['projectRole']);
        self::assertSame('Principal',$parsed['documents']['flow_exec']['flow']['projectGroup']);
        self::assertSame(1,$parsed['documents']['flow_exec']['flow']['projectOrder']);
    }

    public function test_import_parser_accepts_nested_folder_bom_and_manifest_fallback(): void
    {
        $manifest=[
            'project'=>['name'=>'Projeto compactado por pasta'],
            'flows'=>[],
        ];
        $flow="\xEF\xBB\xBF".json_encode($this->flow('flow_nested'),JSON_UNESCAPED_UNICODE);
        $bytes=$this->makeZip([
            'meu-projeto/project.json'=>"\xEF\xBB\xBF".json_encode($manifest,JSON_UNESCAPED_UNICODE),
            'meu-projeto/flows/flow_nested.json'=>$flow,
        ]);

        $parsed=$this->serviceWithoutConstructor()->parseZip($bytes);

        self::assertSame('meu-projeto/project.json',$parsed['project_entry']);
        self::assertArrayHasKey('flow_nested',$parsed['documents']);
    }

    public function test_import_parser_accepts_current_fluxos_luiz_manifest(): void
    {
        $manifest=[
            'format'=>'fluxos-luiz-project',
            'version'=>'1.0',
            'project'=>['name'=>'Atual','default_flow_id'=>'flow_current'],
            'flows'=>[['id'=>'flow_current','role'=>'operational','group'=>'Geral','order'=>2]],
        ];
        $bytes=$this->makeZip([
            'project.json'=>json_encode($manifest,JSON_UNESCAPED_UNICODE),
            'flows/flow_current.json'=>json_encode($this->flow('flow_current'),JSON_UNESCAPED_UNICODE),
        ]);

        $parsed=$this->serviceWithoutConstructor()->parseZip($bytes);

        self::assertArrayHasKey('flow_current',$parsed['documents']);
        self::assertSame('operational',$parsed['documents']['flow_current']['flow']['projectRole']);
    }
}
