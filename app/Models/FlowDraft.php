<?php

namespace App\Models;

class FlowDraft extends MongoDocument
{
    protected $table = 'produto_tools_flowchart_drafts';
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
