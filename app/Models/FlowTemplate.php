<?php

namespace App\Models;

class FlowTemplate extends MongoDocument
{
    protected $table = 'produto_tools_flowchart_templates';
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
