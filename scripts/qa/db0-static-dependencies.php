<?php
/** Static connection-config inspection only; never includes or runs another application. */
declare(strict_types=1);
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,"Static metadata inspection failed; content suppressed.\n");exit(1);});
$root=dirname(__DIR__,2);require $root.'/vendor/autoload.php';
function usefulTokensDb0(string $code):array{return array_values(array_filter(token_get_all($code),static fn($t)=>!is_array($t)||!in_array($t[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT,T_OPEN_TAG,T_CLOSE_TAG],true)));}
function literalDb0(array $tokens,array $variables,array $constants):array {
    if(count($tokens)!==1)return['known'=>false];$t=$tokens[0];if(!is_array($t))return['known'=>false];
    if($t[0]===T_VARIABLE&&array_key_exists($t[1],$variables))return['known'=>true,'value'=>$variables[$t[1]]];
    if($t[0]===T_STRING&&array_key_exists($t[1],$constants))return['known'=>true,'value'=>$constants[$t[1]]];
    if($t[0]===T_CONSTANT_ENCAPSED_STRING){$s=substr($t[1],1,-1);return['known'=>true,'value'=>$t[1][0]==="'"?str_replace(["\\'","\\\\"],["'","\\"],$s):stripcslashes($s)];}
    return['known'=>false];
}
function argumentsDb0(array $t,int $open):array{$out=[];$part=[];$depth=0;for($i=$open+1;$i<count($t);$i++){$v=$t[$i];if($v==='('||$v==='[')$depth++;if($v===')'){if($depth===0){$out[]=$part;return$out;}$depth--;}if($v===']')$depth--;if($v===','&&$depth===0){$out[]=$part;$part=[];}else$part[]=$v;}return[];}
function scanConnectionDb0(string $file):array {
    if(!is_file($file))return['status'=>'UNVERIFIED_CONFIG_NOT_FOUND'];$t=usefulTokensDb0(file_get_contents($file));$vars=[];$constants=[];
    for($i=0;$i<count($t);$i++){
        if(is_array($t[$i])&&$t[$i][0]===T_VARIABLE&&($t[$i+1]??null)==='='&&($t[$i+3]??null)===';'){$r=literalDb0([$t[$i+2]],$vars,$constants);if($r['known'])$vars[$t[$i][1]]=$r['value'];}
        if(is_array($t[$i])&&$t[$i][0]===T_STRING&&strtolower($t[$i][1])==='define'&&($t[$i+1]??null)==='('){$a=argumentsDb0($t,$i+1);if(count($a)>=2){$n=literalDb0($a[0],$vars,$constants);$v=literalDb0($a[1],$vars,$constants);if($n['known']&&$v['known'])$constants[$n['value']]=$v['value'];}}
    }
    $candidates=[];
    for($i=0;$i<count($t);$i++){
        if(!is_array($t[$i])||$t[$i][0]!==T_STRING||!in_array(strtolower($t[$i][1]),['mysqli','mysqli_connect'],true)||($t[$i+1]??null)!=='(')continue;
        $a=argumentsDb0($t,$i+1);$r=[];for($n=0;$n<min(count($a),4);$n++)$r[]=literalDb0($a[$n],$vars,$constants);
        $candidates[]=['api'=>strtolower($t[$i][1]),'host'=>!empty($r[0]['known'])?(in_array(strtolower($r[0]['value']),['localhost','127.0.0.1','::1'],true)?$r[0]['value']:'NON_LOOPBACK_OR_CUSTOM_REDACTED'):'UNVERIFIED','username'=>!empty($r[1]['known'])?$r[1]['value']:'UNVERIFIED','passwordConfigured'=>!empty($r[2]['known'])?strlen($r[2]['value'])>0:null,'databaseClass'=>!empty($r[3]['known'])?(in_array($r[3]['value'],['promerty','promerty_qa','promerty_restore_3b_755e5a4e'],true)?$r[3]['value']:'OTHER_APPLICATION_SCHEMA'):'UNVERIFIED','evidence'=>'static literal/variable candidate; not runtime execution or server-auth verification'];
    }
    return['status'=>$candidates?'STATIC_CANDIDATES':'UNVERIFIED_DYNAMIC_OR_UNRECOGNIZED','candidates'=>$candidates,'executed'=>false];
}
$files=['Exambrowser'=>'Exambrowser/koneksi.php','Kontrakanku'=>'Kontrakanku/koneksi.php','laundryku_api'=>'laundryku_api/config/database.php','ManajemenEskul'=>'ManajemenEskul/Koneksi.php','ManajemenEskulvscode'=>'ManajemenEskulvscode/Koneksi.php','penitipanhewan'=>'penitipanhewan/koneksi.php','Penjualanmobil'=>'Penjualanmobil/Koneksi.php'];
$apps=[];foreach($files as$name=>$relative){$apps[$name]=scanConnectionDb0('C:/xampp/htdocs/'.$relative);if($name==='laundryku_api'&&is_file('C:/xampp/htdocs/laundryku_api/.env')){$e=Dotenv\Dotenv::parse(file_get_contents('C:/xampp/htdocs/laundryku_api/.env'));$user=$e['DB_USERNAME']??$e['DB_USER']??null;$password=$e['DB_PASSWORD']??$e['DB_PASS']??null;$apps[$name]['dotenvCandidate']=['username'=>$user??'UNVERIFIED','passwordConfigured'=>$password!==null?strlen($password)>0:null,'host'=>in_array(($e['DB_HOST']??''),['localhost','127.0.0.1','::1'],true)?$e['DB_HOST']:'UNVERIFIED_OR_CUSTOM_REDACTED','evidence'=>'dotenv only; connection code or process environment may override'];}}
$pmaCode=file_get_contents('C:/xampp/phpMyAdmin/config.inc.php');$pma=[];
foreach(['auth_type','user','password','AllowNoPassword']as$field){$matches=[];preg_match_all('/\[\s*[\'\"]'.preg_quote($field,'/').'[\'\"]\s*\]\s*=\s*([^;]+);/',$pmaCode,$matches);$found=[];foreach($matches[1]as$expr){$tt=usefulTokensDb0('<?php '.$expr.';');array_pop($tt);$r=literalDb0($tt,[],[]);if($field==='password')$found[]=$r['known']?strlen($r['value'])>0:null;elseif($field==='AllowNoPassword')$found[]=strtolower(trim($expr))==='true'?true:(strtolower(trim($expr))==='false'?false:null);else$found[]=$r['known']?$r['value']:'UNVERIFIED';}$pma[$field==='password'?'passwordConfigured':$field]=$found;}
$out=['mode'=>'STATIC_CONFIGURATION_ONLY','otherApplications'=>$apps,'phpMyAdmin'=>$pma,'privateValuesExported'=>false,'otherAppLoginsAttempted'=>false,'databaseConnectionsCreated'=>false];
file_put_contents($root.'/docs/qa/db0-application-dependencies.json',json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));echo json_encode(['applications'=>count($apps),'executed'=>false])."\n";
