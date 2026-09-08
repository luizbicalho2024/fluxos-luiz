<?php

namespace App\Models;

class Flowchart extends MongoDocument
{
    protected $table = 'produto_tools_flowcharts';
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
