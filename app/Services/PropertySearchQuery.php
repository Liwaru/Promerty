<?php

namespace App\Services;

use App\Models\PropertyCategory;

/** Bounded, deterministic interpretation; unrecognised words remain required text. */
class PropertySearchQuery
{
    public function parse(string $input): array
    {
        $text=mb_strtolower(trim(preg_replace('/\s+/u', ' ', $input)));
        $text=str_replace('batam centre','batam center',$text);
        $filters=[];
        if($text==='')return ['filters'=>[],'terms'=>[]];
        $rent = '\b(?:sewa|disewa|disewakan)\b|^kontrak(?= (?:rumah|apartemen|apartment|ruko|gudang)\b)'
            .'|(?:(?<=\brumah )|(?<=\bapartemen )|(?<=\bapartment )|(?<=\bruko )|(?<=\bgudang ))kontrak$';
        $sale = '\b(?:jual|dijual|penjualan)\b';
        $rentMatch = preg_match("~$rent~u", $text);
        $saleMatch = preg_match("~$sale~u", $text);
        if ($rentMatch xor $saleMatch) {
            $filters['transaction'] = $rentMatch ? 'rent' : 'sale';
            $text = preg_replace('~'.($rentMatch ? $rent : $sale).'~u', ' ', $text);
        }
        $categories=PropertyCategory::get(['id','name','slug']);$found=[];
        foreach($categories as $category){
            $aliases=array_unique([mb_strtolower($category->name),str_replace('-',' ',mb_strtolower($category->slug))]);
            if (array_intersect($aliases, ['apartemen', 'apartment'])) $aliases = array_unique([...$aliases, 'apartemen', 'apartment']);
            foreach($aliases as $alias)if($alias!==''&&preg_match('/(?<!\w)'.preg_quote($alias,'/').'(?!\w)/u',$text))$found[$category->id][]=$alias;
        }
        if(count($found)===1){$filters['category']=array_key_first($found);foreach(reset($found) as $alias)$text=preg_replace('/(?<!\w)'.preg_quote($alias,'/').'(?!\w)/u',' ',$text);}
        $numbers=['satu'=>1,'dua'=>2,'tiga'=>3,'empat'=>4,'lima'=>5,'enam'=>6,'tujuh'=>7,'delapan'=>8,'sembilan'=>9,'sepuluh'=>10];
        $num='(\d{1,3}|'.implode('|',array_keys($numbers)).')';
        foreach(['floors'=>'(?:lantai|lt)','bedrooms'=>'(?:kamar(?: tidur)?|kt)','bathrooms'=>'(?:kamar mandi|km)','balconies'=>'balkon'] as $key=>$unit){
            // Bathrooms must not be consumed by the generic bedroom phrase.
            $suffix=$key==='bedrooms'?'(?!\s+mandi)':'';
            $pattern='/\b'.$num.'\s*'.$unit.'\b'.$suffix.'/u';
            if(preg_match_all($pattern,$text,$matches,PREG_SET_ORDER)===1){$m=$matches[0];$filters[$key]=$numbers[$m[1]]??(int)$m[1];$text=str_replace($m[0],' ',$text);}
        }
        foreach(['land_area'=>'(?:luas tanah|ltanah)','building_area'=>'(?:luas bangunan|lb)'] as $key=>$unit){
            if(preg_match('/\b'.$unit.'\s*(\d+(?:[.,]\d+)?)\s*(?:m2|m²|meter(?: persegi)?)(?!\w)/u',$text,$m)){$filters[$key]=(float)str_replace(',','.',$m[1]);$text=str_replace($m[0],' ',$text);}
        }
        $amount='(\d+(?:[.,]\d+)?)\s*(miliar|milyar|juta|ribu|jt|rb)';
        $money=fn($n,$u)=>(float)str_replace(',','.',$n)*match($u){'juta','jt'=>1000000,'ribu','rb'=>1000,default=>1000000000};
        if(preg_match('/\b(?:harga\s*)?'.$amount.'\s*(?:sampai|hingga|-)\s*'.$amount.'\b/u',$text,$m)){
            $a=$money($m[1],$m[2]);$b=$money($m[3],$m[4]);
            if($a<=$b){$filters['min_price']=$a;$filters['max_price']=$b;$text=str_replace($m[0],' ',$text);}
        }elseif(preg_match('/\b(?:(harga|maksimal|maksimum|di bawah|minimal|minimum|mulai)\s*)?'.$amount.'\b/u',$text,$m)){
            $value=$money($m[2],$m[3]);$prefix=$m[1]??'';
            if(in_array($prefix,['maksimal','maksimum','di bawah']))$filters['max_price']=$value;
            elseif(in_array($prefix,['minimal','minimum','mulai']))$filters['min_price']=$value;
            else $filters['min_price']=$filters['max_price']=$value;
            $text=str_replace($m[0],' ',$text);
        }
        // A second explicit bound, e.g. "minimal 40 jt maksimal 60 jt".
        if(preg_match('/\b(maksimal|maksimum|di bawah|minimal|minimum|mulai)\s*'.$amount.'\b/u',$text,$m)){
            $key=in_array($m[1],['maksimal','maksimum','di bawah'])?'max_price':'min_price';
            if(!isset($filters[$key])){$filters[$key]=$money($m[2],$m[3]);$text=str_replace($m[0],' ',$text);}
        }
        if(preg_match('/(?<!\w)(?:per|\/)\s*(bulan|tahun)\b/u',$text,$m)){$filters['price_period']=$m[1]==='bulan'?'month':'year';$text=str_replace($m[0],' ',$text);}
        if(!isset($filters['min_price'],$filters['max_price'])&&preg_match('/\b(?:harga\s*)?rp\s*(\d{1,3}(?:\.\d{3})+|\d+)\b/u',$text,$m)){$filters['min_price']=$filters['max_price']=(float)str_replace('.','',$m[1]);$text=str_replace($m[0],' ',$text);}
        // Only grammatical connectors are discarded; unknown place/project/spec words remain.
        $before=$text;$text=preg_replace('/\b(?:di|dengan|dan)\b/u',' ',$text);
        if(trim($text)===''&&!$filters)$text=$before;
        return ['filters'=>$filters,'terms'=>preg_split('/\s+/u',trim($text),-1,PREG_SPLIT_NO_EMPTY),'ambiguous_transaction'=>($rentMatch && $saleMatch)];
    }
}
