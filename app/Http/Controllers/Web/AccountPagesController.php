<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\{GoogleOAuth, LoginDestination};
use Illuminate\Http\Request;

final class AccountPagesController extends Controller
{
    public function agentWorkspace(Request $request)
    {
        $actor = $request->user();
        if (LoginDestination::for($actor)['dashboard_available']) return redirect()->route('agent.dashboard');

        return view('agent.access', ['verification' => $actor->agentProfile?->verification_status]);
    }

    public function settings(Request $request)
    {
        $actor=$request->user('web');
        $home=$actor ? LoginDestination::for($actor)['redirect_to'] : '/';
        return view('account.settings',compact('actor','home'));
    }

    public function profile(Request $request)
    {
        $actor = $request->user();
        $home = LoginDestination::for($actor)['redirect_to'];
        $googleLinked = $actor->authProviders()->where('provider', 'google')->exists();
        $googleConfigured = GoogleOAuth::configured();

        return view('account.profile', compact('actor', 'home', 'googleLinked', 'googleConfigured'));
    }

    public function help(Request $request)
    {
        $actor=$request->user('web');
        $home=$actor ? LoginDestination::for($actor)['redirect_to'] : '/';
        $q=trim(mb_substr(is_string($request->query('q')) ? $request->query('q') : '',0,120));
        $selected=$request->query('category');
        $categories=config('help');
        $categories=array_filter($categories,function (array $section) use ($actor) {
            if (!isset($section['role'])) return true;
            return $actor?->isActive() && $actor->hasRole($section['role']) && $actor->hasPermission($section['permission']);
        });
        $selected=is_string($selected)&&isset($categories[$selected]) ? $selected : '';
        $results=[];
        foreach ($categories as $key=>$section) {
            if ($selected!=='' && $key!==$selected) continue;
            $entries=array_filter($section['entries'],fn ($answer,$question) => $q==='' || mb_stripos($question.' '.$answer,$q)!==false,ARRAY_FILTER_USE_BOTH);
            if ($entries) $results[$section['label']]=$entries;
        }
        return view('account.help',compact('home','q','selected','categories','results'));
    }
}
