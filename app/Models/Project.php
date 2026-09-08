<?php

namespace App\Models;

class Project extends MongoDocument
{
    protected $table = 'produto_tools_projects';
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
