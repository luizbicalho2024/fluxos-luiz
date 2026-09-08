<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

abstract class MongoDocument extends Model
{
    protected $connection = 'mongodb';
    public $timestamps = false;
    protected $guarded = [];
    protected $primaryKey = '_id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'updated_at' => 'datetime'];
    }
}
