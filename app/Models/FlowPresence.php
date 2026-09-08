<?php

namespace App\Models;

class FlowPresence extends MongoDocument
{
    protected $table = 'produto_tools_flowchart_presence';
    protected function casts(): array
    {
        return ['last_seen'=>'datetime','expires_at'=>'datetime'];
    }
}
