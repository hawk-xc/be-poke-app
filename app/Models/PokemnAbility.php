<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PokemnAbility extends Model
{
    protected $table = 'pokemon_abilities';

    protected $fillable = [
        'pokemon_id',
        'ability_id'
    ];
}
