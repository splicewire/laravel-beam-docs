<?php

namespace Splicewire\Beam\Scribe\OpenApi;

use Knuckles\Camel\Output\OutputEndpointData;
use Knuckles\Scribe\Writing\OpenApiSpecGenerators\OpenApiGenerator;
use Splicewire\Beam\Scribe\Strategies\ParticleResponseStrategy;

/**
 * Mark a particle operation's DECLARED refusals in the document (music-capability-lift 34/35 prerequisite).
 *
 * An op's `errors:` slot declares the body it writes at each non-success status. {@see ParticleResponseStrategy} stashes
 * that body's schema beside the 200 (the shared `dataResponseSchemas` stash, which the schema bridge projects) and records
 * the declared statuses under {@see self::STASH}. This hook, which runs at assembly after the bridge, marks exactly those
 * responses `x-particle-error: true`. A client generator types a refusal only from that mark: a 4xx with a `$ref` is not
 * evidence that an op declared it (a host's generic error bodies are refs too), so provenance travels with the
 * declaration rather than being inferred from a status.
 *
 * An endpoint that declared no refusal stashes nothing and is returned untouched. Wired in the host's
 * `config/scribe.php` beside {@see DeliveryGenerator}, after the bridge's `DataSchemaGenerator`.
 */
class ParticleErrorsGenerator extends OpenApiGenerator
{
    /** The endpoint `custom` key the strategy records the declared refusal statuses under. */
    public const STASH = 'particleErrors';

    public function pathItem(array $pathItem, array $groupedEndpoints, OutputEndpointData $endpoint): array
    {
        $declared = $endpoint->custom[self::STASH] ?? [];
        if (! is_array($declared) || $declared === []) {
            return $pathItem;
        }

        $responses = $pathItem['responses'] ?? [];
        $responses = is_array($responses) ? $responses : (array) $responses;
        foreach ($declared as $status) {
            if (isset($responses[(string) $status]) && is_array($responses[(string) $status])) {
                $responses[(string) $status]['x-particle-error'] = true;
            }
        }
        $pathItem['responses'] = $responses;

        return $pathItem;
    }
}
