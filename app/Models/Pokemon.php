<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Pokemon extends Model
{
    use SoftDeletes;

    protected $table = 'pokemons';

    protected $fillable = [
        'name',
        'best_experience',
        'weight',
        'image_path'
    ];

    public function abilities()
    {
        return $this->belongsToMany(Ability::class, 'pokemon_abilities')->withPivot('pokemon_id', 'ability_id');
    }
}
