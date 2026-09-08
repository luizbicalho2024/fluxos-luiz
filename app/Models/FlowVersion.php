<?php

namespace App\Models;

class FlowVersion extends MongoDocument
{
    protected $table = 'produto_tools_flowchart_versions';
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
