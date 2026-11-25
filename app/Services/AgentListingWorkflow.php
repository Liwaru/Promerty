<?php

namespace App\Services;

use App\Models\{Listing, Location, Post, SecondaryProperty, User};
use Illuminate\Support\Facades\{DB, Gate};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AgentListingWorkflow
{
    public function owned(User $actor, int $id, bool $lock = false): Listing
    {
        $query = Listing::where('agent_id', $actor->id)->whereKey($id);
        $listing = ($lock ? $query->lockForUpdate() : $query)->first();
        if(!$listing){app(SecurityAudit::class)->denied($actor,'ownership');abort(404);}
        Gate::forUser($actor)->authorize('update', $listing);
        abort_unless($actor->isActive() && $actor->agentProfile?->isVerified()
            && $actor->branch?->status === 'active' && $actor->branch_id === $listing->branch_id, 403);
        return $listing;
    }

    public function editable(Listing $listing): void
    {
        abort_unless(in_array($listing->status, ['draft','archived'], true), 409,
            'Arsipkan listing sebelum mengubah informasi atau media.');
        abort_unless(in_array($listing->listing_type,['secondary','primary'],true),409);
        if($listing->listing_type==='primary')app(ProjectAssignments::class)->assigned($listing->agent,$listing->project_id);
    }

    public function post(Listing $listing): Post
    {
        $posts = $listing->posts()->lockForUpdate()->get();
        abort_if($posts->count() > 1 || ($posts->first() && $posts->first()->user_id !== $listing->agent_id), 409,
            'Listing memiliki post existing yang perlu dikelola terpisah.');
        return $posts->first() ?? $listing->posts()->create(['user_id'=>$listing->agent_id,
            'content_type'=>'photo','caption'=>$listing->description,'status'=>'draft','moderation_status'=>'active']);
    }

    public function save(User $actor, array $data, ?int $id = null, ?string $requestKey = null): Listing
    {
        abort_unless($actor->isActive() && $actor->hasRole('agent') && $actor->agentProfile?->isVerified()
            && $actor->hasPermission($id?'manage_own_listings':'create_listings'),403);
        abort_unless($actor->branch?->status === 'active', 403, 'Cabang Agent tidak aktif.');
        if ($id === null && $requestKey !== null) {
            $code = 'LR-'.substr(hash('sha256', $actor->id.':'.$requestKey), 0, 36);
            return DB::transaction(function () use ($actor, $data, $code) {
                // Serialize requests for this actor before checking the globally unique listing code.
                User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $existing = Listing::where('listing_code', $code)->first();
                if ($existing) return $this->owned($actor, $existing->id, true);
                return $this->saveListing($actor, $data, null, $code);
            });
        }

        return $this->saveListing($actor, $data, $id, 'LS-'.Str::uuid());
    }

    private function saveListing(User $actor, array $data, ?int $id, string $code): Listing
    {
        if(($data['listing_type']??'secondary')==='primary')return $this->savePrimary($actor,$data,$id,$code);
        unset($data['listing_type'],$data['project_id']);
        return DB::transaction(function () use ($actor, $data, $id, $code): Listing {
            $listing = $id ? $this->owned($actor, $id, true) : null;
            if($listing)abort_unless($listing->listing_type==='secondary',409,'Jenis inventori listing tidak dapat diubah.');
            if ($listing) $this->editable($listing);
            $data = $this->withDownloadPreference($data, $listing);
            $locationData = ['label'=>$data['location_label'], 'address'=>$data['address'] ?? null];
            unset($data['location_label'], $data['address']);
            if (!$listing) {
                $location = Location::create([...$locationData,'city'=>'Batam','visibility'=>'approximate','created_by'=>$actor->id]);
                $property = SecondaryProperty::create(['property_code'=>'PR-'.Str::uuid(),'category_id'=>$data['category_id'],
                    'location_id'=>$location->id,'title'=>$data['title'],'created_by'=>$actor->id]);
                $created=Listing::create([...$data,'listing_code'=>$code,'listing_type'=>'secondary',
                    'agent_id'=>$actor->id,'branch_id'=>$actor->branch_id,'secondary_property_id'=>$property->id,'status'=>'draft']);
                app(SecurityAudit::class)->record('listing.draft_saved',$actor,['listing_id'=>$created->id]);
                return $created;
            }
            $property = $listing->secondaryProperty;
            // Existing/shared inventory must not be modified through another listing.
            abort_unless($property && $property->created_by === $actor->id && $property->location?->created_by === $actor->id
                && $property->listings()->count() === 1 && $property->location->secondaryProperties()->count() === 1
                && !$property->location->projects()->exists(), 409, 'Properti atau lokasi bersama tidak dapat diubah di sini.');
            $property->location->update($locationData);
            $property->update(['title'=>$data['title'],'category_id'=>$data['category_id']]);
            $listing->fill([...$data,'status'=>'draft'])->save();
            $listing->posts()->where('user_id',$actor->id)->update(['caption'=>$listing->description,'status'=>'draft']);
            app(SecurityAudit::class)->record('listing.draft_saved',$actor,['listing_id'=>$listing->id]);
            return $listing->refresh();
        });
    }

    private function savePrimary(User $actor,array $data,?int $id,string $code):Listing
    {
        return DB::transaction(function()use($actor,$data,$id,$code){
            $listing=$id?$this->owned($actor,$id,true):null;
            $project=app(ProjectAssignments::class)->assigned($actor,(int)$data['project_id']);
            abort_unless($project->location?->city==='Batam'&&$project->location?->label,422,'Proyek memerlukan kawasan Batam.');
            if($listing){$this->editable($listing);abort_unless($listing->listing_type==='primary'&&$listing->project_id===$project->id,409,'Proyek listing tidak dapat diganti.');}
            unset($data['location_label'],$data['address'],$data['listing_type'],$data['project_id']);
            $data = $this->withDownloadPreference($data, $listing);
            if($listing){$listing->fill([...$data,'status'=>'draft'])->save();$listing->posts()->where('user_id',$actor->id)->update(['caption'=>$listing->description,'status'=>'draft']);}
            else $listing=Listing::create([...$data,'listing_code'=>$code,'listing_type'=>'primary',
                'project_id'=>$project->id,'agent_id'=>$actor->id,'branch_id'=>$actor->branch_id,'status'=>'draft']);
            app(SecurityAudit::class)->record('listing.draft_saved',$actor,['listing_id'=>$listing->id]);
            return $listing->refresh();
        });
    }

    private function withDownloadPreference(array $data, ?Listing $listing): array
    {
        $specs = $data['additional_specs'] ?? [];
        if (array_key_exists('allow_video_download', $specs)) {
            $data['additional_specs']['allow_video_download'] = (bool) $specs['allow_video_download'];
        } elseif (!$listing || array_key_exists('allow_video_download', $listing->additional_specs ?? [])) {
            $data['additional_specs'] = $data['additional_specs'] ?? $listing?->additional_specs ?? [];
            $data['additional_specs']['allow_video_download'] = $listing?->permitsVideoDownload() ?? false;
        }
        return $data;
    }

    public function transition(User $actor, int $id, string $action): Listing
    {
        return DB::transaction(function () use ($actor, $id, $action): Listing {
            $listing = $this->owned($actor, $id, true);
            abort_unless(in_array($listing->status,['draft','active','archived'],true),409,'Status listing tidak dapat diubah.');
            if ($action === 'archive') {
                $changed=$listing->status!=='archived';
                $listing->update(['status'=>'archived']);
                $listing->posts()->where('user_id',$actor->id)->update(['status'=>'archived']);
                if($changed)app(SecurityAudit::class)->record('listing.archived',$actor,['listing_id'=>$listing->id]);
                return $listing->refresh();
            }
            abort_unless($action === 'publish', 422);
            abort_unless($actor->hasPermission('create_posts') && in_array($listing->listing_type,['secondary','primary'],true),403);
            if($listing->listing_type==='primary')app(ProjectAssignments::class)->assigned($actor,$listing->project_id);
            $post = $this->post($listing);
            abort_unless($post->moderation_status === 'active', 409, 'Post sedang dibatasi oleh moderasi.');
            $location=$listing->listing_type==='primary'?$listing->project?->location:$listing->secondaryProperty?->location;
            if (!$listing->title || $listing->price <= 0 || !$listing->category
                || !$location?->label || $location->city !== 'Batam') {
                throw ValidationException::withMessages(['listing'=>'Lengkapi judul, kategori, harga, dan lokasi Batam.']);
            }
            $media = $post->media()->get();
            if (!$media->contains(fn ($item) => FeedMedia::canPresent($item, $post->content_type))) {
                throw ValidationException::withMessages(['media'=>'Tambahkan foto atau video yang siap ditampilkan sebelum publikasi.']);
            }
            foreach ($media as $item) {
                if (str_starts_with($item->file_url, 'agent-private/') && !AgentMedia::exists($item)) {
                    throw ValidationException::withMessages(['media'=>'Media tidak tersedia. Unggah kembali sebelum publikasi.']);
                }
            }
            $changed=$listing->status!=='active'||$post->status!=='published';
            $listing->update(['status'=>'active']);
            $post->update(['status'=>'published','published_at'=>$post->published_at ?? now(),'caption'=>$listing->description]);
            if($changed)app(SecurityAudit::class)->record('listing.published',$actor,['listing_id'=>$listing->id]);
            return $listing->refresh();
        });
    }
}
