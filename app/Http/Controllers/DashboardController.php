<?php

namespace App\Http\Controllers;

use App\Models\Flowchart;
use App\Models\Project;
use App\Models\ActivityLog;
use App\Services\FlowRepository;
use App\Services\ProjectRepository;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly FlowRepository $flows,private readonly ProjectRepository $projects){}

    public function index(Request $request)
    {
        $u=$request->user();$admin=$u->isAdmin();
        $flows=$this->flows->visibleTo($u->username,$admin);
        $projects=$this->projects->visibleTo($u->username,$admin);
        $stats=[
            'projects'=>$projects->count(),
            'flows'=>$flows->count(),
            'drafts'=>$flows->where('workflow_status','draft')->count(),
            'in_review'=>$flows->where('workflow_status','in_review')->count(),
            'published'=>$flows->where('workflow_status','published')->count(),
        ];
        $recent=ActivityLog::orderBy('timestamp','desc')->limit(20)->get();
        return view('dashboard.index',compact('stats','flows','projects','recent'));
    }
}
