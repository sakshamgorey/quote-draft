<?php

namespace App\Models;

use App\Casts\PostgresArray;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Scout\Searchable;

class Product extends Model
{
    use Searchable;

    public const TYPE_LABELS = [
        'access_door' => 'Access door',
        'floor_door' => 'Floor door',
        'roof_hatch' => 'Roof hatch',
        'smoke_vent' => 'Smoke vent',
        'skylight' => 'Skylight',
        'ladder' => 'Ladder',
        'safety_rail' => 'Safety rail',
        'valve_box' => 'Valve box',
        'vent_cover' => 'Vent cover',
        'door_louver_window_kit' => 'Door louver / window kit',
    ];

    protected $guarded = ['id'];

    // Never select the tsvector into PHP.
    protected $hidden = ['search_vector'];

    protected function casts(): array
    {
        return [
            'nominal_width' => 'float',
            'nominal_height' => 'float',
            'overall_width' => 'float',
            'overall_height' => 'float',
            'price' => 'decimal:2',
            'msrp' => 'decimal:2',
            'priced_at' => 'datetime',
            'is_available' => 'boolean',
            'rating' => 'float',
            'fire_rated' => 'boolean',
            'insulated' => 'boolean',
            'application' => PostgresArray::class,
            'surface_type' => PostgresArray::class,
            'material' => PostgresArray::class,
            'flange_type' => PostgresArray::class,
            'purpose' => PostgresArray::class,
            'category_paths' => PostgresArray::class,
            'type_of_access' => PostgresArray::class,
        ];
    }

    /**
     * With the pgsql engine the keys here are *column names*, not a document.
     * They must match the columns passed to $table->searchable() in the migration.
     */
    public function toSearchableArray(): array
    {
        return [
            'name' => $this->name,
            'sku' => $this->sku,
            'model' => $this->model,
        ];
    }

    /** What the product's store page says (catalog:parse-pages). */
    public function page(): HasOne
    {
        return $this->hasOne(ProductPage::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class)->orderBy('position');
    }

    public function links(): HasMany
    {
        return $this->hasMany(ProductLink::class);
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->product_type] ?? $this->product_type;
    }

    /** 12" x 24" — the opening size customers quote. */
    public function openingSize(): ?string
    {
        if ($this->nominal_width === null || $this->nominal_height === null) {
            return null;
        }

        $fmt = fn (float $n) => rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.').'"';

        return $fmt($this->nominal_width).' x '.$fmt($this->nominal_height);
    }
}
