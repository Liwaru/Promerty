<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\{Like, Post, PostView, SurveyRequest, User};
use App\Services\ProjectAssignments;
use App\Support\LoginDestination;
use Illuminate\Http\Request;

final class AgentWorkspaceController extends Controller
{
    public static function navigation(User $agent, ProjectAssignments $assignments): array
    {
        if (!LoginDestination::for($agent)['dashboard_available']) {
            return ['workspace' => ['Dashboard Agent', route('agent.workspace'), 'monitor']];
        }
        $tools = [
            'overview' => ['Dashboard Agent', route('agent.dashboard'), 'monitor'],
            'listings' => ['Listing Saya', route('agent.listings'), 'saved'],
        ];
        if ($agent->hasPermission('create_listings')) {
            $tools['create'] = ['Tambah Listing', route('agent.create'), 'upload'];
            if ($assignments->available() && $agent->branch?->status === 'active') {
                $tools['projects'] = ['Proyek Saya', route('agent.projects'), 'home'];
            }
        }
        if ($agent->hasPermission('create_posts')) {
            $tools['media'] = ['Media dan Publikasi', route('agent.media'), 'upload'];
        }

        return $tools;
    }

    public function __invoke(Request $request, ProjectAssignments $assignments)
    {
        $agent = $request->user('web');
        $section = $request->route('section');
        $tools = self::navigation($agent, $assignments);
        abort_unless(isset($tools[$section]), 403);

        $owned = $agent->listings()->where('branch_id', $agent->branch_id);
        $data = compact('agent', 'section', 'tools');
        if ($section === 'overview') {
            $data['metrics'] = ['Total Listing' => (clone $owned)->count(), 'Total Tayangan' => null,
                'Total Suka' => null, 'Permintaan Survei' => null];
            if ($agent->hasPermission('view_own_analytics')) {
                $ids = (clone $owned)->select('id');
                $posts = Post::where('user_id', $agent->id)->whereIn('listing_id', $ids)->select('id');
                $data['metrics']['Total Tayangan'] = PostView::whereIn('post_id', $posts)->count();
                $data['metrics']['Total Suka'] = Like::whereIn('post_id', $posts)->count();
                $data['metrics']['Permintaan Survei'] = SurveyRequest::where('agent_id', $agent->id)->whereIn('listing_id', $ids)->count();
            }
            $data['recent'] = (clone $owned)->latest('updated_at')->orderByDesc('id')->limit(5)
                ->get(['id', 'title', 'status', 'updated_at']);
        }
        if ($section === 'projects') {
            $data['projects'] = $assignments->projects($agent)->with(['location', 'developer'])
                ->withCount(['listings' => fn ($query) => $query->where('agent_id', $agent->id)->where('branch_id', $agent->branch_id)])
                ->orderBy('name')->paginate(12);
        }

        return view('agent.dashboard', $data);
    }
}
