<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Keeps docs/openapi.yaml honest: every API route must be documented, and nothing may be documented that does not exist.
 * (A tiny line-based reader is enough here because the file's path/method layout is regular; no YAML dependency needed.)
 */
class OpenApiSpecTest extends TestCase
{
    /** @return list<string> e.g. "POST /api/requests/{id}/submit" */
    private function documentedOperations(): array
    {
        $operations = [];
        $path = null;

        foreach (file(base_path('docs/openapi.yaml'), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('#^  (/api/\S+):$#', $line, $m)) {
                $path = $m[1];
            } elseif ($path && preg_match('/^    (get|post|put|patch|delete):$/', $line, $m)) {
                $operations[] = strtoupper($m[1]).' '.$path;
            } elseif (preg_match('/^\S/', $line)) {
                $path = null; // left the "paths:" block
            }
        }

        sort($operations);

        return $operations;
    }

    /** @return list<string> */
    private function registeredOperations(): array
    {
        $operations = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            $uri = '/'.str_replace('{serviceRequest}', '{id}', $route->uri());
            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $operations[] = "{$method} {$uri}";
                }
            }
        }

        sort($operations);

        return $operations;
    }

    public function test_every_api_route_is_documented_and_every_documented_operation_exists(): void
    {
        $this->assertSame($this->registeredOperations(), $this->documentedOperations());
    }

    public function test_a_postman_collection_is_shipped_and_covers_the_same_operations(): void
    {
        $collection = json_decode((string) file_get_contents(base_path('docs/postman_collection.json')), true, flags: JSON_THROW_ON_ERROR);

        $operations = [];
        $walk = function (array $items) use (&$walk, &$operations) {
            foreach ($items as $item) {
                if (isset($item['item'])) {
                    $walk($item['item']);
                } else {
                    $path = '/'.implode('/', array_map(fn ($seg) => $seg === ':id' ? '{id}' : $seg, $item['request']['url']['path']));
                    $operations[] = $item['request']['method'].' '.$path;
                }
            }
        };
        $walk($collection['item']);

        $this->assertEmpty(array_diff($this->registeredOperations(), $operations), 'operations missing from the Postman collection');
    }
}
