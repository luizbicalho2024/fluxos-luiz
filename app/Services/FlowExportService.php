<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use ZipArchive;

class FlowExportService
{
    private const TYPES = [
        'start'=>['label'=>'Início','color'=>'#059669'],
        'end'=>['label'=>'Fim','color'=>'#dc2626'],
        'task'=>['label'=>'Atividade','color'=>'#2563eb'],
        'decision'=>['label'=>'Decisão','color'=>'#d97706'],
        'subprocess'=>['label'=>'Subprocesso','color'=>'#7c3aed'],
        'event'=>['label'=>'Evento','color'=>'#0891b2'],
        'wait'=>['label'=>'Espera','color'=>'#475569'],
        'document'=>['label'=>'Documento','color'=>'#0f766e'],
        'api'=>['label'=>'Integração','color'=>'#9333ea'],
        'note'=>['label'=>'Observação','color'=>'#ca8a04'],
    ];

    public function __construct(private readonly FlowDocumentService $documents) {}

    public function baseName(array $document,array $meta=[]): string
    {
        $name=(string)($document['flow']['name']??'fluxo');
        $slug=preg_replace('/[^A-Za-z0-9_-]+/','_',iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$name)?:$name) ?: 'fluxo';
        $version=(int)($meta['version']??1);$revision=(int)($meta['revision']??1);
        return trim($slug,'_')."_v{$version}_rev{$revision}";
    }

    public function diagramSvg(array $document): string
    {
        $doc=$this->documents->normalize($document);
        $lanes=$doc['lanes'];usort($lanes,fn($a,$b)=>((int)$a['order'])<=>((int)$b['order']));
        $laneTop=[];$y=20;$worldW=1200;$worldH=800;
        foreach($lanes as $lane){$h=max(110,min(2400,(int)$lane['height']));$laneTop[$lane['id']]=['y'=>$y,'h'=>$h];$y+=$h+18;}
        foreach($doc['nodes'] as $n){$worldW=max($worldW,(float)$n['position']['x']+270);$worldH=max($worldH,(float)$n['position']['y']+180);}
        $worldH=max($worldH,$y+40);
        $nodeMap=[];foreach($doc['nodes'] as $n)$nodeMap[$n['id']]=$n;
        $defs='<defs><marker id="arr" markerWidth="10" markerHeight="10" refX="9" refY="5" orient="auto"><path d="M0,0 L10,5 L0,10 z" fill="context-stroke"/></marker></defs>';
        $svg='<svg xmlns="http://www.w3.org/2000/svg" width="'.(int)$worldW.'" height="'.(int)$worldH.'" viewBox="0 0 '.(int)$worldW.' '.(int)$worldH.'">'.$defs;
        $svg.='<rect width="100%" height="100%" fill="#ffffff"/>';
        foreach($lanes as $lane){$g=$laneTop[$lane['id']];$svg.='<rect x="20" y="'.$g['y'].'" width="'.($worldW-40).'" height="'.$g['h'].'" rx="12" fill="'.$this->softColor((string)$lane['color']).'" stroke="#cbd5e1"/>';
            $svg.='<text x="38" y="'.($g['y']+25).'" font-family="Arial" font-size="13" font-weight="700" fill="#102a43">'.$this->x($lane['name']).'</text>';}
        foreach($doc['edges'] as $edge){if(($edge['enabled']??true)===false)continue;$s=$nodeMap[$edge['source']]??null;$t=$nodeMap[$edge['target']]??null;if(!$s||!$t)continue;
            $x1=(float)$s['position']['x']+190;$y1=(float)$s['position']['y']+40;$x2=(float)$t['position']['x'];$y2=(float)$t['position']['y']+40;
            $color=$this->edgeColor($edge,$s,$t);$routing=(string)($doc['settings']['edgeRouting']??'smooth');$d=$this->edgePath($x1,$y1,$x2,$y2,$routing);
            $svg.='<path d="'.$d.'" fill="none" stroke="'.$color.'" stroke-width="2" marker-end="url(#arr)"/>';
            $label=trim((string)($edge['label']??$edge['condition']??''));if($label!=='')$svg.='<text x="'.(($x1+$x2)/2).'" y="'.(($y1+$y2)/2-6).'" font-family="Arial" font-size="11" text-anchor="middle" fill="'.$color.'">'.$this->x($label).'</text>';
        }
        foreach($doc['nodes'] as $node){if(($node['data']['enabled']??true)===false)continue;$x=(float)$node['position']['x'];$y=(float)$node['position']['y'];$meta=self::TYPES[$node['type']]??self::TYPES['task'];$label=(string)($node['data']['label']??$node['id']);$desc=(string)($node['data']['description']??'');$owner=(string)($node['data']['owner']??'');
            $svg.='<rect x="'.$x.'" y="'.$y.'" width="184" height="78" rx="10" fill="#fff" stroke="'.$meta['color'].'" stroke-width="2"/>';
            $svg.='<rect x="'.$x.'" y="'.$y.'" width="6" height="78" rx="3" fill="'.$meta['color'].'"/>';
            $svg.='<text x="'.($x+15).'" y="'.($y+19).'" font-family="Arial" font-size="9" font-weight="700" fill="'.$meta['color'].'">'.$this->x(mb_strtoupper($meta['label'])).'</text>';
            $svg.='<text x="'.($x+15).'" y="'.($y+39).'" font-family="Arial" font-size="12" font-weight="700" fill="#102a43">'.$this->x(mb_strimwidth($label,0,27,'…')).'</text>';
            if($desc!=='')$svg.='<text x="'.($x+15).'" y="'.($y+55).'" font-family="Arial" font-size="9" fill="#486581">'.$this->x(mb_strimwidth($desc,0,38,'…')).'</text>';
            if($owner!=='')$svg.='<text x="'.($x+15).'" y="'.($y+70).'" font-family="Arial" font-size="8" fill="#829ab1">'.$this->x(mb_strimwidth($owner,0,30,'…')).'</text>';
        }
        return $svg.'</svg>';
    }

    public function diagramPdf(array $document,array $meta=[]): string
    {
        $svg=$this->diagramSvg($document);
        $name=$this->x($document['flow']['name']??'Fluxo');
        $html='<!doctype html><html><head><meta charset="utf-8"><style>@page{size:A3 landscape;margin:12mm}body{font-family:DejaVu Sans,sans-serif;color:#102a43}h1{font-size:18px;margin:0 0 10px}.meta{font-size:10px;color:#627d98;margin-bottom:10px}.diagram{width:100%}.diagram img{width:100%;height:auto}</style></head><body><h1>'.$name.'</h1><div class="meta">Versão '.(int)($meta['version']??1).' · revisão '.(int)($meta['revision']??1).' · gerado em '.now()->format('d/m/Y H:i').'</div><div class="diagram"><img src="data:image/svg+xml;base64,'.base64_encode($svg).'"></div></body></html>';
        return $this->pdf($html,'A3','landscape');
    }

    public function documentationPdf(array $document,array $meta=[]): string
    {
        return $this->pdf($this->documentationHtml($document,$meta),'A4','portrait');
    }

    public function htmlReport(array $document,array $meta=[]): string
    {
        return $this->documentationHtml($document,$meta,true);
    }

    public function nodesCsv(array $document): string
    {
        $lane=[];foreach((array)($document['lanes']??[]) as $l)$lane[(string)$l['id']]=(string)$l['name'];
        $rows=[];foreach((array)($document['nodes']??[]) as $node){$d=(array)($node['data']??[]);$rows[]=[
            'ID'=>$node['id']??'','Tipo'=>$node['type']??'','Título'=>$d['label']??'','Descrição'=>$d['description']??'','Responsável'=>$d['owner']??'',
            'Raia'=>$lane[(string)($node['laneId']??'')]??'Sem raia','Nível'=>$d['level']??'','Categoria'=>$d['category']??'','Criticidade'=>$d['criticality']??'',
            'SLA (min)'=>$d['slaMinutes']??'','Tags'=>implode(', ',(array)($d['tags']??[])),'Fluxo vinculado'=>$d['linkedFlowId']??'','Documentação'=>$d['documentationUrl']??''
        ];}
        return $this->csv($rows);
    }

    public function raciCsv(array $document): string
    {
        return $this->csv($this->documents->buildRaciRows($document));
    }

    public function bundle(array $document,array $meta=[]): string
    {
        $tmp=tempnam(sys_get_temp_dir(),'fluxos_zip_');$zip=new ZipArchive();
        if($zip->open($tmp,ZipArchive::OVERWRITE)!==true)throw new \RuntimeException('Não foi possível criar o pacote ZIP.');
        $base=$this->baseName($document,$meta);
        $zip->addFromString($base.'.json',json_encode($document,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
        $zip->addFromString($base.'.svg',$this->diagramSvg($document));
        $zip->addFromString($base.'_diagrama.pdf',$this->diagramPdf($document,$meta));
        $zip->addFromString($base.'_documentacao.pdf',$this->documentationPdf($document,$meta));
        $zip->addFromString($base.'.html',$this->htmlReport($document,$meta));
        $zip->addFromString($base.'_cards.csv',$this->nodesCsv($document));
        $zip->addFromString($base.'_raci.csv',$this->raciCsv($document));
        $zip->close();$bytes=file_get_contents($tmp);@unlink($tmp);return $bytes?:'';
    }

    private function documentationHtml(array $document,array $meta=[],bool $standalone=false): string
    {
        $analysis=$this->documents->analyze($document);$svg=$this->diagramSvg($document);$issues=$analysis['issue_details'];$raci=$this->documents->buildRaciRows($document);
        $name=$this->x($document['flow']['name']??'Fluxo');$desc=$this->x($document['flow']['description']??'');
        $css='body{font-family:DejaVu Sans,Arial,sans-serif;color:#102a43;font-size:11px;line-height:1.4}h1{margin:0 0 4px;font-size:24px}h2{font-size:16px;border-bottom:1px solid #d9e2ec;padding-bottom:5px;margin-top:22px}.meta{color:#627d98;margin-bottom:12px}.metrics{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}.metric{display:inline-block;border:1px solid #d9e2ec;border-radius:8px;padding:7px 10px;margin:0 5px 5px 0}.metric b{font-size:16px}table{border-collapse:collapse;width:100%;font-size:9px}th,td{border:1px solid #d9e2ec;padding:5px;vertical-align:top}th{background:#f0f4f8;text-align:left}.diagram img{width:100%;height:auto}.issue-Erro{color:#b42318}.issue-Atenção{color:#b54708}.issue-Recomendação{color:#175cd3}';
        $html='<!doctype html><html><head><meta charset="utf-8"><title>'.$name.'</title><style>'.$css.'</style></head><body><h1>'.$name.'</h1><div class="meta">'.$desc.'<br>Versão '.(int)($meta['version']??1).' · revisão '.(int)($meta['revision']??1).' · qualidade '.$analysis['quality_score'].'/100 · gerado em '.now()->format('d/m/Y H:i').'</div>';
        $html.='<div class="metrics"><span class="metric">Cards <b>'.$analysis['counts']['nodes'].'</b></span><span class="metric">Conexões <b>'.$analysis['counts']['edges'].'</b></span><span class="metric">Raias <b>'.$analysis['counts']['lanes'].'</b></span><span class="metric">Decisões <b>'.$analysis['counts']['decisions'].'</b></span><span class="metric">SLA total <b>'.$analysis['total_sla_minutes'].' min</b></span></div>';
        $html.='<h2>Diagrama completo</h2><div class="diagram"><img src="data:image/svg+xml;base64,'.base64_encode($svg).'"></div>';
        $html.='<h2>Qualidade</h2>'.$this->table($issues,['severity'=>'Gravidade','card'=>'Card','lane'=>'Raia','problem'=>'Problema','why'=>'Por que importa','fix'=>'Como corrigir']);
        $nodeRows=[];foreach((array)($document['nodes']??[]) as $node){$d=(array)($node['data']??[]);$nodeRows[]=['id'=>$node['id'],'tipo'=>$node['type'],'titulo'=>$d['label']??'','descricao'=>$d['description']??'','responsavel'=>$d['owner']??'','criticidade'=>$d['criticality']??'','sla'=>$d['slaMinutes']??'','tags'=>implode(', ',(array)($d['tags']??[]))];}
        $html.='<h2>Cards</h2>'.$this->table($nodeRows,['id'=>'ID','tipo'=>'Tipo','titulo'=>'Título','descricao'=>'Descrição','responsavel'=>'Responsável','criticidade'=>'Criticidade','sla'=>'SLA','tags'=>'Tags']);
        $html.='<h2>Matriz RACI</h2>'.$this->table($raci,['Etapa'=>'Etapa','Raia'=>'Raia','Responsável'=>'Responsável','Aprovador'=>'Aprovador','Consultados'=>'Consultados','Informados'=>'Informados','Criticidade'=>'Criticidade','SLA (min)'=>'SLA (min)']);
        $html.='<h2>Configurações</h2><pre>'.htmlspecialchars(json_encode($document['settings']??[],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),ENT_QUOTES,'UTF-8').'</pre></body></html>';
        return $html;
    }

    private function table(array $rows,array $columns): string
    {
        if(!$rows)return '<p>Nenhum registro.</p>';$out='<table><thead><tr>';foreach($columns as $label)$out.='<th>'.$this->x($label).'</th>';$out.='</tr></thead><tbody>';
        foreach($rows as $row){$out.='<tr>';foreach($columns as $key=>$label)$out.='<td>'.$this->x(is_array($row[$key]??null)?implode(', ',$row[$key]):($row[$key]??'')).'</td>';$out.='</tr>';}
        return $out.'</tbody></table>';
    }

    private function csv(array $rows): string
    {
        $fp=fopen('php://temp','w+');fprintf($fp,"\xEF\xBB\xBF");if(!$rows){rewind($fp);return stream_get_contents($fp);}
        fputcsv($fp,array_keys($rows[0]),';');foreach($rows as $row)fputcsv($fp,array_values($row),';');rewind($fp);$csv=stream_get_contents($fp);fclose($fp);return $csv?:'';
    }

    private function pdf(string $html,string $paper='A4',string $orientation='portrait'): string
    {
        $options=new Options();$options->set('isRemoteEnabled',false);$options->set('isHtml5ParserEnabled',true);$options->set('defaultFont','DejaVu Sans');
        $dompdf=new Dompdf($options);$dompdf->loadHtml($html,'UTF-8');$dompdf->setPaper($paper,$orientation);$dompdf->render();return $dompdf->output();
    }

    private function edgePath(float $x1,float $y1,float $x2,float $y2,string $routing): string
    {
        if($routing==='straight')return "M {$x1} {$y1} L {$x2} {$y2}";
        if($routing==='smooth'){$delta=max(35,abs($x2-$x1)*.42);$direction=$x2>=$x1?1:-1;return "M {$x1} {$y1} C ".($x1+$direction*$delta)." {$y1}, ".($x2-$direction*$delta)." {$y2}, {$x2} {$y2}";}
        $mid=($x1+$x2)/2;return "M {$x1} {$y1} L {$mid} {$y1} L {$mid} {$y2} L {$x2} {$y2}";
    }

    private function edgeColor(array $edge,array $source,array $target): string
    {
        if(($source['type']??'')!=='decision')return '#536b7c';
        $text=mb_strtolower((string)($edge['label']??'').' '.(string)($edge['condition']??'').' '.(string)($target['data']['label']??'').' '.implode(' ',(array)($target['data']['tags']??[])));
        foreach(['não','nao','negativo','recus','rejeit','falha','erro','cancel','inválid','invalid','expir','indispon','reprov','bloque'] as $token)if(str_contains($text,$token))return '#dc2626';
        foreach(['sim','positivo','aprov','aceit','válid','valid','conclu','dispon','pago','sucesso','ativo','permit','ok'] as $token)if(str_contains($text,$token))return '#16a34a';
        return '#536b7c';
    }

    private function softColor(string $hex): string
    {
        if(!preg_match('/^#([0-9a-f]{6})$/i',$hex,$m))return '#f4f7fb';$n=hexdec($m[1]);$r=($n>>16)&255;$g=($n>>8)&255;$b=$n&255;$mix=.84;$r=(int)round($r*(1-$mix)+255*$mix);$g=(int)round($g*(1-$mix)+255*$mix);$b=(int)round($b*(1-$mix)+255*$mix);return sprintf('#%02x%02x%02x',$r,$g,$b);
    }

    private function x(mixed $value): string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
