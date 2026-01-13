<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ability extends Model
{
    protected $table = 'abilities';

    protected $fillable = [
        'name',
    ];

    public function pokemon()
    {
        return $this->hasMany(Ability::class, 'pokemon_abilities')->withPivot('pokemon_id', 'ability_id');
    }
}
