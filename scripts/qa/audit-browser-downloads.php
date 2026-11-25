<?php
/** Inspect local QA download metadata via a private snapshot; never write the live Chrome profile. */
declare(strict_types=1);
$root=dirname(__DIR__,2);$profiles=glob('C:/Users/Hendra Huang/AppData/Local/ms-playwright-mcp/*/Default/History');$result=[];
foreach($profiles as $path){
    try{
        // Chrome can hold an exclusive lock. Read an isolated snapshot instead;
        // the live profile is never opened for writing or modified.
        $snapshot=$root.'/storage/app/private/real-video-20261113/download-audit/'.basename(dirname(dirname($path)));
        if(!is_dir($snapshot))mkdir($snapshot,0700,true);
        copy($path,$snapshot.'/History');if(is_file($path.'-journal'))copy($path.'-journal',$snapshot.'/History-journal');
        $db=new PDO('sqlite:'.$snapshot.'/History',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA query_only=ON');$db->exec('PRAGMA busy_timeout=1500');
        $rows=$db->query("SELECT d.id,d.guid,d.target_path,d.current_path,d.received_bytes,d.total_bytes,d.mime_type,d.original_mime_type,d.state,d.start_time,u.url FROM downloads d JOIN downloads_url_chains u ON u.id=d.id WHERE (u.url LIKE 'blob:http://127.0.0.1:%' OR u.url LIKE 'blob:http://localhost:%' OR u.url LIKE 'http://127.0.0.1:%' OR u.url LIKE 'http://localhost:%') ORDER BY d.start_time DESC LIMIT 150")->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$row){$file=$row['target_path'];$row['exists']=is_file($file);$row['filename']=basename(str_replace('\\','/',$file));$row['extension']=pathinfo($file,PATHINFO_EXTENSION);$row['content_disposition']=str_starts_with($row['url'],'blob:')?'Not applicable: browser-created Blob, no HTTP response':null;
            if($row['exists']){$row['disk_bytes']=filesize($file);$row['sha256']=hash_file('sha256',$file);$handle=fopen($file,'rb');$head=fread($handle,64);fclose($handle);$row['mp4_ftyp']=substr($head,4,4)==='ftyp';$row['json_valid']=filesize($file)<2000000&&json_validate_qa($file);}
        }unset($row);
        $result[]=['profile'=>basename(dirname(dirname($path))),'read_only'=>true,'records'=>$rows];
    }catch(Throwable $e){$result[]=['profile'=>basename(dirname(dirname($path))),'read_only'=>true,'error'=>$e->getMessage()];}
}
function json_validate_qa(string $file):bool{json_decode(file_get_contents($file),true);return json_last_error()===JSON_ERROR_NONE;}
file_put_contents($root.'/docs/customer/qa/desktop-download-audit.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
$all=[];foreach($result as $profile)foreach($profile['records']??[] as $record)$all[]=$record;
$mimes=[];foreach($all as $record)$mimes[$record['mime_type']]=($mimes[$record['mime_type']]??0)+1;
echo json_encode(['report'=>'docs/customer/qa/desktop-download-audit.json','records'=>count($all),'mime_counts'=>$mimes,'existing_target_files'=>count(array_filter($all,fn($r)=>$r['exists'])),'no_downloads_triggered'=>true,'no_download_files_deleted'=>true],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
