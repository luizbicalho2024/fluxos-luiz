<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use MongoDB\Client;

class EnsureMongoIndexes extends Command
{
    protected $signature = 'app:ensure-mongo-indexes';
    protected $description = 'Cria os índices necessários no MongoDB';

    public function handle(): int
    {
        $dsn=(string)config('database.connections.mongodb.dsn');
        $database=(string)config('database.connections.mongodb.database');
        $db=(new Client($dsn))->selectDatabase($database);

        $indexes=[
            'users'=>[
                [['username'=>1],['unique'=>true,'name'=>'uq_users_username']],
                [['email'=>1],['sparse'=>true,'name'=>'ix_users_email']],
            ],
            'produto_tools_flowcharts'=>[
                [['owner_username'=>1,'updated_at'=>-1],['name'=>'ix_pt_flows_owner_updated']],
                [['status'=>1,'updated_at'=>-1],['name'=>'ix_pt_flows_status_updated']],
                [['project_id'=>1,'project_order'=>1],['name'=>'ix_pt_flows_project_order']],
            ],
            'produto_tools_flowchart_versions'=>[
                [['flowchart_id'=>1,'version'=>-1],['unique'=>true,'name'=>'uq_pt_flow_versions']],
            ],
            'produto_tools_flowchart_drafts'=>[
                [['flowchart_id'=>1,'username'=>1],['unique'=>true,'name'=>'uq_pt_flow_drafts']],
                [['updated_at'=>-1],['name'=>'ix_pt_drafts_updated']],
            ],
            'produto_tools_flowchart_comments'=>[
                [['flowchart_id'=>1,'resolved'=>1,'created_at'=>-1],['name'=>'ix_pt_comments_flow_status']],
            ],
            'produto_tools_flowchart_approvals'=>[
                [['flowchart_id'=>1,'created_at'=>-1],['name'=>'ix_pt_approvals_flow']],
            ],
            'produto_tools_flowchart_templates'=>[
                [['owner_username'=>1,'name'=>1],['name'=>'ix_pt_templates_owner_name']],
            ],
            'produto_tools_flowchart_presence'=>[
                [['flowchart_id'=>1,'last_seen'=>-1],['name'=>'ix_pt_presence_flow']],
                [['expires_at'=>1],['expireAfterSeconds'=>0,'name'=>'ttl_pt_presence']],
            ],
            'produto_tools_projects'=>[
                [['owner_username'=>1,'updated_at'=>-1],['name'=>'ix_pt_projects_owner_updated']],
                [['status'=>1,'updated_at'=>-1],['name'=>'ix_pt_projects_status_updated']],
            ],
            'produto_tools_project_members'=>[
                [['project_id'=>1,'username'=>1],['unique'=>true,'name'=>'uq_pt_project_members']],
            ],
            'produto_tools_project_releases'=>[
                [['project_id'=>1,'version'=>-1],['unique'=>true,'name'=>'uq_pt_project_releases']],
            ],
            'produto_tools_project_release_flows'=>[
                [['project_id'=>1,'release_version'=>-1,'flow_id'=>1],['unique'=>true,'name'=>'uq_pt_project_release_flows']],
            ],
            'activity_logs'=>[
                [['timestamp'=>-1],['name'=>'ix_logs_timestamp']],
            ],
        ];

        foreach($indexes as $collection=>$defs){
            foreach($defs as [$keys,$options])$db->selectCollection($collection)->createIndex($keys,$options);
        }
        $this->info('Índices MongoDB validados.');
        return self::SUCCESS;
    }
}
