<?php

use App\Casts\PostgresArray;
use App\Services\Catalog\FacetTagger;
use Illuminate\Support\Facades\File;

/** Writes a saved facet page the way FacetStore does and returns its path. */
function facetPage(string $name, array $results): string
{
    $path = storage_path("framework/testing/facets/{$name}.json");
    File::ensureDirectoryExists(dirname($path));
    File::put($path, json_encode(['pagination' => ['totalPages' => 1], 'results' => $results]));

    return $path;
}

function row(string $uid, array $overrides = []): array
{
    return array_merge([
        'uid' => $uid,
        'fire_rated' => false,
        'surface_type' => PostgresArray::literal(['Drywall']),
        'flange_type' => PostgresArray::literal([]),
        'purpose' => PostgresArray::literal([]),
        'type_of_access' => PostgresArray::literal([]),
    ], $overrides);
}

afterEach(fn () => File::deleteDirectory(storage_path('framework/testing/facets')));

it('merges store facet values into tag columns', function () {
    $tagger = (new FacetTagger)->load([
        'custom_surface_type' => ['Masonry' => [facetPage('masonry', [['uid' => '1']])]],
        'custom_flange_type' => ['Flange Behind Drywall' => [facetPage('fbd', [['uid' => '1']])]],
        'custom_type_of_access' => ['Access Doors' => [facetPage('ad', [['uid' => '1'], ['uid' => '2']])]],
    ]);

    $rows = $tagger->apply(['1' => row('1'), '2' => row('2')]);

    expect(PostgresArray::parse($rows['1']['surface_type']))->toBe(['Drywall', 'Masonry'])
        ->and(PostgresArray::parse($rows['1']['flange_type']))->toBe(['Drywall Flange']) // aliased
        ->and(PostgresArray::parse($rows['2']['type_of_access']))->toBe(['Access Doors'])
        ->and(PostgresArray::parse($rows['2']['surface_type']))->toBe(['Drywall']); // untouched
});

it('normalises load ratings and drops non-attribute values', function () {
    $rows = (new FacetTagger)->load([
        'custom_purpose' => [
            '300 psf Load' => [facetPage('300', [['uid' => '1']])],
            '15 PSF Load' => [facetPage('15', [['uid' => '1']])],
        ],
        'custom_surface_type' => ['Retrofit' => [facetPage('retro', [['uid' => '1']])]],
    ])->apply(['1' => row('1')]);

    expect(PostgresArray::parse($rows['1']['purpose']))->toBe(['300 PSF', '15 PSF'])
        ->and(PostgresArray::parse($rows['1']['surface_type']))->toBe(['Drywall']);
});

it('marks products fire-rated from the store facet, never un-marks them', function () {
    $rows = (new FacetTagger)->load([
        'custom_fire_rated' => [
            'Yes' => [facetPage('yes', [['uid' => '1']])],
            'No' => [facetPage('no', [['uid' => '2']])],
        ],
    ])->apply(['1' => row('1'), '2' => row('2', ['fire_rated' => true])]);

    expect($rows['1']['fire_rated'])->toBeTrue()
        ->and($rows['2']['fire_rated'])->toBeTrue(); // name said fire-rated; facet "No" doesn't override
});

it('exposes products only seen in facet pages so they get imported', function () {
    $tagger = (new FacetTagger)->load([
        'custom_surface_type' => ['Masonry' => [facetPage('m', [['uid' => '77', 'sku' => 'BA-X-1']])]],
    ]);

    expect(array_column($tagger->rawResults(), 'sku'))->toBe(['BA-X-1']);
});
