<?php
namespace App\Services;
use Illuminate\Http\UploadedFile; use RuntimeException; use ZipArchive;
class SpreadsheetReader
{
    public function rows(UploadedFile $file): array
    {
        if(strtolower($file->getClientOriginalExtension())==='csv') return $this->csv($file->getRealPath());
        if(strtolower($file->getClientOriginalExtension())!=='xlsx') throw new RuntimeException('仅支持 CSV 或 XLSX 文件。');
        return $this->xlsx($file->getRealPath());
    }
    private function csv(string $path): array { $h=fopen($path,'rb');$rows=[];while(($r=fgetcsv($h))!==false){$rows[]=array_map(fn($v)=>mb_convert_encoding((string)$v,'UTF-8','UTF-8,GB18030'),$r);}fclose($h);return $rows; }
    private function xlsx(string $path): array
    {
        $zip=new ZipArchive(); if($zip->open($path)!==true)throw new RuntimeException('无法读取 XLSX 文件。');
        $shared=[];$raw=$zip->getFromName('xl/sharedStrings.xml');if($raw){$xml=simplexml_load_string($raw);foreach($xml->si as $si)$shared[]=trim((string)$si->t ?: implode('',array_map('strval',iterator_to_array($si->r->t??[]))));}
        $sheet=$zip->getFromName('xl/worksheets/sheet1.xml');$zip->close();if(!$sheet)throw new RuntimeException('XLSX 缺少首个工作表。');
        $xml=simplexml_load_string($sheet);$rows=[];foreach($xml->sheetData->row as $row){$values=[];$last=0;foreach($row->c as $cell){preg_match('/([A-Z]+)/',(string)$cell['r'],$m);$index=$this->columnIndex($m[1]??'A');while($last<$index)$values[]='';$v=(string)$cell->v;$values[]=(string)$cell['t']==='s'?($shared[(int)$v]??''):$v;$last=$index+1;}$rows[]=$values;}return $rows;
    }
    private function columnIndex(string $letters): int{$n=0;foreach(str_split($letters)as$c)$n=$n*26+ord($c)-64;return $n-1;}
}
