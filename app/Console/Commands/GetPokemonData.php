<?php

namespace App\Console\Commands;

use Exception;
use App\Models\Ability;
use App\Models\Pokemon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GetPokemonData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:get-pokemon-data {--limit=50}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch Pokemon data from external API';

    private int $pokemonCount = 0;
    private int $abilityCount = 0;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $limit = (int) $this->option('limit');
        $this->info("Starting to fetch Pokemon data with limit: {$limit}");

        $firstResponse = $this->firstFetch($limit);

        if (!$firstResponse) {
            $this->error('Failed to fetch initial Pokemon data');
            return Command::FAILURE;
        }

        $data = json_decode($firstResponse, true);

        if (!isset($data['results'])) {
            $this->error('Invalid response format from Pokemon API');
            return Command::FAILURE;
        }

        $getPokemonUrls = [];
        foreach ($data['results'] as $pokemonsUrl) {
            $getPokemonUrls[] = $pokemonsUrl['url'];
        }

        $processedCount = 0;
        foreach ($getPokemonUrls as $index => $pokemonsUrl) {
            if ($index >= $limit) {
                break;
            }

            $response = $this->secondFetch($pokemonsUrl);
            if (!$response) {
                $this->warn("Failed to fetch details for Pokemon at index {$index}");
                continue;
            }

            $pokemonData = json_decode($response, true);

            if ($this->processPokemonData($pokemonData)) {
                $processedCount++;
                $this->info("Processed Pokemon: {$pokemonData['name']} ({$processedCount}/{$limit})");
            }
        }

        $this->info("Successfully processed {$processedCount} Pokemons");
        $this->info("Total abilities linked: {$this->abilityCount}");

        // Return output untuk ditangkap oleh controller
        return [
            'success' => true,
            'pokemon_count' => $processedCount,
            'ability_count' => $this->abilityCount,
            'message' => "Successfully fetched {$processedCount} Pokemons"
        ];
    }

    protected function firstFetch($limit)
    {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://pokeapi.co/api/v2/pokemon?limit=" . $limit);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $result = curl_exec($ch);
            curl_close($ch);

            return $result;
        } catch (Exception $e) {
            Log::error("First fetch error: " . $e->getMessage());
            return false;
        }
    }

    protected function secondFetch(string $url)
    {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $result = curl_exec($ch);
            curl_close($ch);

            return $result;
        } catch (Exception $e) {
            Log::error("Second fetch error for URL {$url}: " . $e->getMessage());
            return false;
        }
    }

    protected function storeImage($imageUrl)
    {
        try {
            if (!$imageUrl) {
                return null;
            }

            $extension = pathinfo($imageUrl, PATHINFO_EXTENSION);
            if (!$extension) {
                $extension = 'png';
            }

            $imagePath = 'pokemon/' . uniqid() . '.' . $extension;

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $imageUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $imageContent = curl_exec($ch);
            curl_close($ch);

            if ($imageContent) {
                Storage::disk('minio')->put($imagePath, $imageContent);
                return Storage::disk('minio')->url($imagePath);
            }

            return null;
        } catch (Exception $err) {
            Log::error("Image store error: " . $err->getMessage());
            return null;
        }
    }

    protected function processPokemonData(array $pokemonData): bool
    {
        try {
            if (!isset($pokemonData['id'], $pokemonData['name'])) {
                return false;
            }

            $existingPokemon = Pokemon::where('name', $pokemonData['name'])->first();
            if ($existingPokemon) {
                $this->warn("Pokemon {$pokemonData['name']} already exists, skipping...");
                return false;
            }

            $newPokemon = new Pokemon();
            $newPokemon->name = $pokemonData['name'];
            $newPokemon->best_experience = $pokemonData['base_experience'] ?? 0;
            $newPokemon->weight = $pokemonData['weight'] ?? 0;

            $imageUrl = $pokemonData['sprites']['front_default'] ?? null;
            if ($imageUrl) {
                $newPokemon->image_path = $this->storeImage($imageUrl);
            }

            $newPokemon->save();
            $this->pokemonCount++;

            $abilityIds = [];
            foreach ($pokemonData['abilities'] as $ability) {
                if (!$ability['is_hidden']) {
                    $abilityName = $ability['ability']['name'];

                    $dbAbility = Ability::firstOrCreate(
                        ['name' => $abilityName],
                        ['name' => $abilityName]
                    );

                    $abilityIds[] = $dbAbility->id;
                    $this->abilityCount++;
                }
            }

            if (!empty($abilityIds)) {
                $newPokemon->abilities()->sync($abilityIds);
            }

            return true;
        } catch (Exception $err) {
            Log::error("Process Pokemon error for {$pokemonData['name']}: " . $err->getMessage());
            return false;
        }
    }
}
