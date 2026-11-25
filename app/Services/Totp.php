<?php

namespace App\Services;

/** RFC 4226/6238 SHA-1, 30s step; caller supplies replay/rate-limit controls. */
final class Totp
{
    public function secret(): string
    {
        $bits='';foreach (str_split(random_bytes(20)) as $c) $bits.=str_pad(decbin(ord($c)),8,'0',STR_PAD_LEFT);
        $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$out='';
        foreach(str_split($bits,5) as $chunk)$out.=$alphabet[bindec(str_pad($chunk,5,'0'))];
        return $out;
    }
    public function code(string $secret, int $step, int $digits=6): string
    {
        if($step<0||!in_array($digits,[6,8],true)||!preg_match('/^[A-Z2-7]{16,128}$/D',$secret))throw new \InvalidArgumentException('Invalid TOTP parameters.');
        $bits='';foreach(str_split($secret) as$c)$bits.=str_pad(decbin(strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567',$c)),5,'0',STR_PAD_LEFT);
        $key='';foreach(str_split($bits,8) as$b)if(strlen($b)===8)$key.=chr(bindec($b));
        $hash=hash_hmac('sha1',pack('N2',intdiv($step,4294967296),$step%4294967296),$key,true);
        $offset=ord($hash[19])&15;$value=unpack('N',substr($hash,$offset,4))[1]&0x7fffffff;
        return str_pad((string)($value%(10**$digits)),$digits,'0',STR_PAD_LEFT);
    }
    public function match(string $secret,string $code,int $timestamp,?int $lastStep): ?int
    {
        if(!preg_match('/^[0-9]{6}$/D',$code))return null;
        $step=intdiv($timestamp,30);$match=null;
        foreach([$step-1,$step,$step+1]as$candidate)if($candidate>=0&&($lastStep===null||$candidate>$lastStep)
            &&hash_equals($this->code($secret,$candidate),$code))$match=$candidate;
        return $match;
    }
}
