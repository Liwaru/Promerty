<?php

namespace App\Services;

use App\Models\{Listing, Location, User};
use Illuminate\Database\Eloquent\Builder;

class SearchService
{
    public function __construct(private FeedService $feed) {}

    private function like(string $term): string
    {
        return '%'.str_replace(['!','%','_'],['!!','!%','!_'],mb_strtolower($term)).'%';
    }

    private function text(Builder $q, array $columns, string $term): void
    {
        $pattern=$this->like($term);
        $q->where(function ($q) use ($columns,$pattern) {
            foreach ($columns as $column) $q->orWhereRaw("LOWER($column) LIKE ? ESCAPE '!'",[$pattern]);
        });
    }

    private function eligible(array $f, array $smart=[]): Builder
    {
        $query=$this->feed->visible()->whereHas('media', function ($media) {
            $media->whereRaw("TRIM(file_url) <> ''")
                ->whereRaw("media_type = CASE WHEN posts.content_type = 'video' THEN 'video' ELSE 'image' END");
        });
        if ($smart['ambiguous_transaction'] ?? false) return $query->whereRaw('1 = 0');
        $query->whereHas('listing',function ($l) use ($f,$smart) {
            foreach (['type'=>'listing_type','transaction'=>'transaction_type','category'=>'category_id','price_period'=>'price_period'] as $key=>$column) {
                if (isset($f[$key]) && $f[$key]!=='') $l->where($column,$f[$key]);
            }
            foreach (['min_price'=>['price','>='],'max_price'=>['price','<='],'bedrooms'=>['bedrooms','>='],'bathrooms'=>['bathrooms','>='],
                'floors'=>['floors','>='],'min_area'=>['land_area','>='],'min_building_area'=>['building_area','>=']] as $key=>[$column,$operator]) {
                if (isset($f[$key]) && $f[$key]!=='' && ($operator !== '>=' || (float) $f[$key] > 0)) $l->where($column,$operator,$f[$key]);
            }
            if (($f['balconies'] ?? 0) > 0) $this->filterBalconies($l, '>=', $f['balconies']);
            foreach (($smart['filters']??[]) as $key=>$value) {
                if ($key === 'balconies') {
                    $this->filterBalconies($l, '=', $value);
                } elseif (in_array($key,['category','transaction','price_period'])) {
                    $l->where(['category'=>'category_id','transaction'=>'transaction_type','price_period'=>'price_period'][$key],$value);
                } else {
                    $column=['min_price'=>'price','max_price'=>'price'][$key]??$key;
                    $l->where($column,$key==='min_price'?'>=':($key==='max_price'?'<=':'='),$value);
                }
            }
            foreach (array_filter([$f['location'] ?? null, $smart['location'] ?? null], fn ($term) => $term !== null && $term !== '') as $place) {
                $l->where(fn ($match) => $this->inventoryText($match, $place));
            }
            if (isset($smart['inventory_name'])) {
                $name=$smart['inventory_name'];
                $l->where(function ($match) use ($name) {
                    $this->text($match, ['title'], $name);
                    $this->inventoryText($match, $name);
                });
            }
        });
        foreach (($smart['terms']??[]) as $term) $query->where(function ($q) use ($term) {
            $this->text($q,['caption'],$term);
            $q->orWhereHas('listing',function ($l) use ($term) {
                $this->text($l,['title','description','unit_type','certificate','furnishing','electricity'],$term);
                $l->orWhere(function ($match) use ($term) {
                    $match->whereIn('orientation', Listing::PUBLIC_ORIENTATIONS);
                    $this->text($match, ['orientation'], $term);
                });
                $l->orWhereHas('category',fn($c)=>$this->text($c,['name','slug'],$term));
                $this->inventoryText($l, $term, includeFacilities: true);
                foreach (['tower', 'concept'] as $key) {
                    [$value, $type, $textType] = $this->publicSpec($l, $key);
                    $length = $l->getConnection()->getDriverName() === 'sqlite' ? 'LENGTH' : 'CHAR_LENGTH';
                    $l->orWhereRaw("($type = '$textType' AND $length($value) <= 100 AND LOWER($value) LIKE ? ESCAPE '!')", [$this->like($term)]);
                }
            });
        });
        return $query;
    }

    private function locationText(Builder $location,string $term): void
    {
        $pattern=$this->like(str_replace('batam centre','batam center',mb_strtolower($term)));
        $location->where(function($match)use($pattern){foreach(['label','district','city','province'] as $column)$match->orWhereRaw("REPLACE(LOWER($column), 'batam centre', 'batam center') LIKE ? ESCAPE '!'",[$pattern]);});
    }

    private function knownPlace(array $terms): ?string
    {
        if (!$terms) return null;
        // Only infer a place from a complete remaining phrase and visible inventory.
        // An ambiguous name can still match the project/property relation.
        $name = implode(' ', $terms);
        $publicListings = $this->feed->visible()->select('listing_id');
        $locations = Location::query()->where(function ($match) use ($name) {
            foreach (['label', 'district', 'city', 'province'] as $column) {
                $match->orWhereRaw("REPLACE(LOWER(TRIM($column)), 'batam centre', 'batam center') = ?", [$name]);
            }
        })->where(function ($inventory) use ($publicListings) {
            foreach (['primary' => 'projects.listings', 'secondary' => 'secondaryProperties.listings'] as $type => $relation) {
                $inventory->orWhereHas($relation, fn ($listings) => $listings->where('listing_type', $type)->whereIn('id', clone $publicListings));
            }
        });
        return $locations->exists() ? $name : null;
    }

    private function knownInventory(string $name): bool
    {
        $prefix=substr($this->like($name.' '),1);
        return Listing::query()->whereIn('id', $this->feed->visible()->select('listing_id'))
            ->where(function ($match) use ($name, $prefix) {
                $match->where(fn ($primary) => $primary->where('listing_type', 'primary')->whereHas('project',
                    fn ($project) => $project->whereRaw("(LOWER(TRIM(name)) = ? OR LOWER(TRIM(name)) LIKE ? ESCAPE '!')", [$name,$prefix])));
                $match->orWhere(fn ($secondary) => $secondary->where('listing_type', 'secondary')->whereHas('secondaryProperty',
                    fn ($property) => $property->whereRaw('LOWER(TRIM(title)) = ?', [$name])));
            })->exists();
    }

    private function inventoryText(Builder $listing, string $term, bool $includeFacilities = false): void
    {
        foreach (['primary' => ['project', 'name'], 'secondary' => ['secondaryProperty', 'title']] as $kind => [$relation, $column]) {
            $listing->orWhere(fn ($branch) => $branch->where('listing_type', $kind)
                ->whereHas($relation, function ($inventory) use ($term, $column, $kind, $includeFacilities) {
                    $this->text($inventory, [$column], $term);
                    $inventory->orWhereHas('location', fn ($loc) => $this->locationText($loc, $term));
                    if ($kind === 'primary' && $includeFacilities) $this->facilitiesText($inventory, $term);
                }));
        }
    }

    private function facilitiesText(Builder $project, string $term): void
    {
        $json = "CASE WHEN JSON_VALID(facilities) THEN facilities ELSE '[]' END";
        if ($project->getConnection()->getDriverName() === 'sqlite') {
            $project->orWhereRaw("JSON_TYPE($json) = 'array' AND EXISTS
                (SELECT 1 FROM json_each($json) AS facility WHERE facility.type = 'text' AND LOWER(facility.value) LIKE ? ESCAPE '!')", [$this->like($term)]);
        } else {
            // JSON_SEARCH may descend into nested objects. Only search flat facility lists.
            $project->orWhereRaw("JSON_TYPE($json) = 'ARRAY' AND JSON_DEPTH($json) = 2
                AND JSON_SEARCH(LOWER($json), 'one', ?, '!', '$[*]') IS NOT NULL", [$this->like($term)]);
        }
    }

    private function publicSpec(Builder $listing, string $key): array
    {
        $json = "CASE WHEN JSON_VALID(additional_specs) THEN additional_specs ELSE '{}' END";
        $value = "JSON_EXTRACT($json, '$.$key')";
        if ($listing->getConnection()->getDriverName() === 'sqlite') {
            return [$value, "JSON_TYPE($json, '$.$key')", 'text'];
        }
        return ["JSON_UNQUOTE($value)", "JSON_TYPE($value)", 'STRING'];
    }

    private function filterBalconies(Builder $listing, string $operator, int $count): void
    {
        [$value, $type, $textType] = $this->publicSpec($listing, 'balconies');
        $digits = $listing->getConnection()->getDriverName() === 'sqlite'
            ? "$value NOT GLOB '*[^0-9]*'" : "$value REGEXP '^[0-9]{1,3}$'";
        $number = "CAST($value AS DECIMAL(3,0))";
        $listing->whereRaw("(LOWER($type) = 'integer' OR ($type = '$textType' AND LENGTH($value) BETWEEN 1 AND 3 AND $digits))
            AND $number BETWEEN 0 AND 50 AND $number $operator ?", [$count]);
    }

    private function relevance(Builder $query, string $input, array $terms): void
    {
        $title = mb_strtolower($input);
        $name = $terms ? implode(' ', $terms) : $title;
        $rank = Listing::query()
            ->leftJoin('projects', fn ($join) => $join->on('projects.id', '=', 'listings.project_id')
                ->where('listings.listing_type', 'primary')->whereNull('projects.deleted_at'))
            ->leftJoin('secondary_properties', fn ($join) => $join->on('secondary_properties.id', '=', 'listings.secondary_property_id')
                ->where('listings.listing_type', 'secondary')->whereNull('secondary_properties.deleted_at'))
            ->whereColumn('listings.id', 'posts.listing_id')
            ->selectRaw("CASE WHEN LOWER(COALESCE(projects.name, secondary_properties.title)) = ? THEN 4
                WHEN LOWER(COALESCE(projects.name, secondary_properties.title)) LIKE ? ESCAPE '!' THEN 3
                WHEN LOWER(listings.title) = ? THEN 2
                WHEN LOWER(listings.title) LIKE ? ESCAPE '!' THEN 1 ELSE 0 END",
                [$name, $this->like($name), $title, $this->like($name)]);
        $query->addSelect(['search_rank' => $rank])->orderByDesc('search_rank');
    }

    public function search(array $f, ?User $viewer): array
    {
        $kind=$f['kind']??'property';$sort=$f['sort']??'relevance';$page=(int)($f['page']??1);
        $smart=app(PropertySearchQuery::class)->parse($f['q']??'');
        $constraints = $smart;
        if (!($smart['ambiguous_transaction'] ?? false) && $smart['terms']) {
            $name=implode(' ', $smart['terms']);
            if ($place = $this->knownPlace($smart['terms'])) {
                $constraints['location'] = $smart['location'] = $place;
                $constraints['terms'] = [];
            } elseif ($this->knownInventory($name)) {
                $constraints['inventory_name'] = $name;
                $constraints['terms'] = [];
            }
        }
        $eligible=$this->eligible($f,$constraints);
        if ($kind==='agent') {
            $query=User::where('status','active')->whereHas('role',fn($q)=>$q->where('name','agent'))
                ->with(['role','agentProfile','branch.agency']);
            // Agents may match their public identity OR own a property matching the complete query.
            $base=$this->eligible($f,[...$constraints,'terms'=>[]]);
            $query->whereIn('id',Listing::select('agent_id')->whereIn('id',(clone $base)->select('listing_id')));
            if (isset($f['q']) && $f['q']!=='') $query->where(function($match)use($f,$eligible){
                $this->text($match,['name','username','bio'],$f['q']);
                $match->orWhereIn('id',Listing::select('agent_id')->whereIn('id',(clone $eligible)->select('listing_id')));
            });
            if ($sort==='relevance' && isset($f['q']) && $f['q']!=='') {
                $query->orderByRaw("CASE WHEN LOWER(name) = ? THEN 0 WHEN LOWER(name) LIKE ? ESCAPE '!' THEN 1 ELSE 2 END",[mb_strtolower($f['q']),substr($this->like($f['q']),1,-1).'%']);
            }
            $query->orderBy('name')->orderBy('id');
        } else {
            if ($kind==='video') $eligible->where('content_type','video');
            $ids=$eligible->selectRaw("COALESCE(MAX(CASE WHEN posts.content_type = 'video' THEN posts.id END), MAX(posts.id))")->groupBy('listing_id');
            $query=$this->feed->query($viewer)->whereIn('posts.id',$ids);
            if (in_array($sort,['price_asc','price_desc'])) {
                $query->orderBy(Listing::select('price')->whereColumn('listings.id','posts.listing_id'),$sort==='price_asc'?'asc':'desc');
            } elseif ($sort==='relevance' && isset($f['q']) && $f['q']!=='') {
                $this->relevance($query, $f['q'], $smart['terms']);
            }
            $query->orderByDesc('published_at')->orderByDesc('posts.id');
        }
        // Bounded offset keeps stable price/relevance sorting without an unbounded count query.
        $paginator=$query->simplePaginate(12,['*'],'page',$page);
        return ['kind'=>$kind,'items'=>$paginator->getCollection(),'interpreted'=>$smart,'next_page'=>$paginator->hasMorePages() && $page<100?$page+1:null];
    }
}
