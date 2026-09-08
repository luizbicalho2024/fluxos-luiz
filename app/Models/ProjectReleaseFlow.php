<?php

namespace App\Models;

class ProjectReleaseFlow extends MongoDocument
{
    protected $table = 'produto_tools_project_release_flows';
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
