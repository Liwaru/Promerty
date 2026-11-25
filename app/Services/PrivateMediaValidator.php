<?php

namespace App\Services;

use App\Models\{Listing,User};
use App\Support\SecurityRollout;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** QA foundation only: validates into a quarantine manifest; does not publish or execute media. */
class PrivateMediaValidator
{
    public function inspect(User $actor,Listing $listing,UploadedFile $file): array
    {
        abort_unless(SecurityRollout::enabled(),403);
        return $this->inspectForListing($actor,$listing,$file);
    }
    public function inspectForListing(User $actor,Listing $listing,UploadedFile $file,bool $resumable=false): array
    {
        abort_unless($actor->isActive()&&$listing->exists,403);
        Gate::forUser($actor)->authorize('update',$listing);
        $fail=static function():never{throw ValidationException::withMessages(['media'=>'File media tidak valid atau melebihi batas.']);};
        $name=$file->getClientOriginalName();$extension=strtolower($file->getClientOriginalExtension());
        $mimes=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','mp4'=>'video/mp4'];
        if(!$file->isValid()||strpbrk($name,"/\\\0")!==false||!isset($mimes[$extension]))$fail();
        $mime=$file->getMimeType();$size=$file->getSize();
        $videoLimit=min(750000000,(int)config('agent.video_max_bytes'));
        $limit=$extension==='mp4'
            ? ($resumable ? $videoLimit : min($videoLimit,1024*(int)config('agent.video_max_kb')))
            : 1024*(int)config('agent.image_max_kb');
        if($mime!==$mimes[$extension]||!is_int($size)||$size<1||$size>$limit)$fail();
        $width=null;$height=null;$duration=null;
        if($extension!=='mp4'){
            $dimensions=@getimagesize($file->getRealPath());
            if(!$dimensions||($dimensions['mime']??null)!==$mime)$fail();
            [$width,$height]=$dimensions;if($width<1||$height<1||$width>8000||$height>8000||$width*$height>40000000)$fail();
            $rawLimit=trim(ini_get('memory_limit'));$memoryLimit=(int)$rawLimit;
            if($memoryLimit>0){$unit=strtolower(substr($rawLimit,-1));$memoryLimit*=match($unit){'g'=>1073741824,'m'=>1048576,'k'=>1024,default=>1};
                if($width*$height*8+$size*2+16*1024*1024>$memoryLimit-memory_get_usage(true))$fail();}
            $image=@imagecreatefromstring(file_get_contents($file->getRealPath()));
            if(!$image)$fail();imagedestroy($image);
        }else{
            try{$metadata=app(Mp4Inspector::class)->inspect($file->getRealPath());}
            catch(\UnexpectedValueException){$fail();}
            $width=$metadata['width'];$height=$metadata['height'];$duration=$metadata['duration_seconds'];
        }
        return ['generated_name'=>bin2hex(random_bytes(24)).'.'.($extension==='jpeg'?'jpg':$extension),
            'mime'=>$mime,'bytes'=>$size,'width'=>$width,'height'=>$height,'duration_seconds'=>$duration,'quarantine_required'=>true];
    }
}
