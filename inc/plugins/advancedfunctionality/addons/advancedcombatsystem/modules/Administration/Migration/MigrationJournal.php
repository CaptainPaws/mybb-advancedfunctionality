<?php
if(!defined('IN_MYBB'))die('No direct access');
/** Auxiliary ACP preparation journal, not EngineMigration's authoritative DDL journal. */
final class AF_ACS_MigrationJournal
{
    private const HEADER="<?php http_response_code(404); exit; ?>\n";
    public function __construct(private string $directory){}
    public function read():array
    {
        $path=$this->directory.'/journal.php';
        if(!file_exists($path))return ['revision'=>0,'selected'=>['forums','users','threads','posts'],'report'=>null,'receipts'=>[],'events'=>[],'job'=>null];
        if(is_link($path)||filesize($path)>1048576||(fileperms($path)&0077)!==0)throw new RuntimeException('journal_unavailable');
        $s=file_get_contents($path);if(!str_starts_with($s,self::HEADER))throw new RuntimeException('journal_unavailable');
        $v=json_decode(substr($s,strlen(self::HEADER)),true,64,JSON_THROW_ON_ERROR);if(!is_array($v)||!is_int($v['revision']??null)||$v['revision']<0||!is_array($v['selected']??null)||array_filter($v['selected'],fn($t)=>!is_string($t))||!is_array($v['receipts']??null)||!is_array($v['events']??null)||!array_key_exists('report',$v)||($v['report']!==null&&!is_array($v['report']))||!array_key_exists('job',$v)||($v['job']!==null&&!is_array($v['job'])))throw new RuntimeException('journal_unavailable');return $v;
    }
    public function command(string $key,string $hash,int $version,callable $change):array
    {
        if(!is_dir($this->directory)&&!mkdir($this->directory,0700,true))throw new RuntimeException('journal_unavailable');
        if(is_link($this->directory))throw new RuntimeException('journal_unavailable');
        $f=fopen($this->directory.'/lock.php','c+');if(!$f||!flock($f,LOCK_EX))throw new RuntimeException('journal_unavailable');
        try{
            // Protect lock even if requested over HTTP. No secrets or executable state in either file.
            if(fstat($f)['size']===0){fwrite($f,self::HEADER);fflush($f);}
            $s=$this->read();
            if(isset($s['receipts'][$key])){if($s['receipts'][$key]!==$hash)throw new RuntimeException('request_conflict');return $s;}
            if($s['revision']!==$version)throw new RuntimeException('version_conflict');
            $s=$change($s);$s['revision']++;$s['receipts'][$key]=$hash;
            if(count($s['receipts'])>200)throw new RuntimeException('journal_review_required');
            $s['events']=array_slice($s['events'],-100);
            $tmp=$this->directory.'/journal-new.php';$h=fopen($tmp,'wb');if(!$h)throw new RuntimeException('journal_unavailable');
            try{$data=self::HEADER.json_encode($s,JSON_THROW_ON_ERROR);if(fwrite($h,$data)!==strlen($data)||!fflush($h)||!fsync($h))throw new RuntimeException('journal_unavailable');}finally{fclose($h);}
            chmod($tmp,0600);if(!rename($tmp,$this->directory.'/journal.php'))throw new RuntimeException('journal_unavailable');return $s;
        }finally{flock($f,LOCK_UN);fclose($f);}
    }
}
