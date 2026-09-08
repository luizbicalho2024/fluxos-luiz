<?php

namespace App\Models;

class ProjectRelease extends MongoDocument
{
    protected $table = 'produto_tools_project_releases';
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
