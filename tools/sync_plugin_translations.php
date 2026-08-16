<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$dir = $root . '/Flight Radar Sim Projekt/resources/languages';
$targets = ['ar'=>'ar','bn'=>'bn','zh'=>'zh-CN','nl'=>'nl','fr'=>'fr','hi'=>'hi','id'=>'id','it'=>'it','ja'=>'ja','ko'=>'ko','pl'=>'pl','pt'=>'pt','ru'=>'ru','es'=>'es','tr'=>'tr'];
$only = array_slice($argv, 1); if ($only) $targets = array_intersect_key($targets, array_flip($only));
$source = [];
foreach (file($dir . '/en.txt', FILE_IGNORE_NEW_LINES) as $line) {
    $at = strpos($line, '='); if ($at !== false) $source[substr($line,0,$at)] = substr($line,$at+1);
}
function ptokens(string $s): array { preg_match_all('/\{[A-Za-z_][A-Za-z0-9_]*\}|%[A-Za-z_][A-Za-z0-9_]*%/',$s,$m); $r=$m[0]??[]; sort($r); return $r; }
function translateOne(string $text,string $target): string {
    if ($text==='' || !preg_match('/[A-Za-z]/',$text)) return $text;
    $temporary=tempnam(sys_get_temp_dir(),'vfn_translate_');file_put_contents($temporary,$text);
    $base='https://translate.googleapis.com/translate_a/single?client=gtx&sl=en&tl='.rawurlencode($target).'&dt=t';
    $command='curl.exe -sS -G '.escapeshellarg($base).' --data-urlencode '.escapeshellarg('q@'.$temporary);
    $json=shell_exec($command);@unlink($temporary);
    $data=json_decode((string)$json,true); if(!is_array($data)||!isset($data[0])) throw new RuntimeException('invalid response');
    $out=''; foreach($data[0] as $part) if(is_array($part)&&isset($part[0])) $out.=(string)$part[0]; return $out;
}
function translateBatch(array $texts,string $target): array {
    $payload='';foreach(array_values($texts) as $index=>$text)$payload.=sprintf('[[VFN%04d]]',$index).(string)$text."\n";
    $url='https://translate.googleapis.com/translate_a/single?client=gtx&sl=en&tl='.rawurlencode($target).'&dt=t&q='.rawurlencode(rtrim($payload));
    $temporary=tempnam(sys_get_temp_dir(),'vfn_translate_');file_put_contents($temporary,$payload);
    $base='https://translate.googleapis.com/translate_a/single?client=gtx&sl=en&tl='.rawurlencode($target).'&dt=t';
    $command='curl.exe -sS -G '.escapeshellarg($base).' --data-urlencode '.escapeshellarg('q@'.$temporary);
    $json=shell_exec($command);@unlink($temporary);
    $data=json_decode((string)$json,true);if(!is_array($data)||!isset($data[0]))throw new RuntimeException('invalid response');
    $joined='';foreach($data[0] as $part)if(is_array($part)&&isset($part[0]))$joined.=(string)$part[0];
    preg_match_all('/\[\[VFN\s*(\d{4})\]\](.*?)(?=\[\[VFN\s*\d{4}\]\]|$)/su',$joined,$parts,PREG_SET_ORDER);
    $out=[];foreach($parts as $part)$out[(int)$part[1]]=trim($part[2]);ksort($out);$out=array_values($out);
    if(count($out)!==count($texts))throw new RuntimeException('batch result mismatch');return $out;
}
foreach($targets as $code=>$target){
    $existing=[];
    $targetFile=$dir.'/'.$code.'.txt';
    if(is_file($targetFile)){
        foreach(file($targetFile, FILE_IGNORE_NEW_LINES) as $line){
            $at=strpos($line,'=');
            if($at!==false)$existing[substr($line,0,$at)]=substr($line,$at+1);
        }
    }
    $translated=[];$fallbacks=0;$pending=[];
    foreach($source as $key=>$english){
        if(isset($existing[$key])&&trim((string)$existing[$key])!=='')$translated[$key]=(string)$existing[$key];
        else $pending[$key]=$english;
    }
    foreach(array_chunk($pending,20,true) as $chunk){
        try{$values=translateBatch(array_values($chunk),$target);}catch(Throwable $e){$values=[];}
        foreach(array_keys($chunk) as $index=>$key){$english=$chunk[$key];$value=trim($values[$index]??'');
            if($value===''||ptokens($value)!==ptokens($english)){
                try{$single=trim(translateOne($english,$target));}catch(Throwable $e){$single='';}
                if($single!==''&&ptokens($single)===ptokens($english)){$value=$single;}else{$value=$english;$fallbacks++;}
            }$translated[$key]=$value;}
    }
    $rows=[];foreach($source as $key=>$english){$value=$translated[$key]??$english;$rows[]=$key.'='.str_replace(["\r","\n"],['','\\n'],$value);}
    file_put_contents($targetFile,implode("\n",$rows)."\n"); echo "$code: ".count($rows)." entries, ".count($pending)." added, $fallbacks fallback(s)\n";
}
