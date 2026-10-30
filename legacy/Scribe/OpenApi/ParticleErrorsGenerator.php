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
 * the declared statuses under {@see self::STASH}, marking the declared body's own schema with {@see self::MARK}. This hook,
 * which runs at assembly after the bridge and after {@see DeliveryGenerator}, marks a declared status's response
 * `x-particle-error: true` only while its final schema still carries that mark, so a response a later writer replaced
 * (a delivery's generic 422) is never taken for the refusal. A client generator types a refusal only from the response
 * mark: a 4xx with a `$ref` is not evidence that an op declared it (a host's generic error bodies are refs too), so
 * provenance travels with the declaration rather than being inferred from a status.
 *
 * An endpoint that declared no refusal stashes nothing and is returned untouched. Wired in the host's
 * `config/scribe.php` beside {@see DeliveryGenerator}, after the bridge's `DataSchemaGenerator`.
 */
class ParticleErrorsGenerator extends OpenApiGenerator
{
    /** The endpoint `custom` key the strategy records the declared refusal statuses under. */
    public const STASH = 'particleErrors';

    /** The provenance mark: on the declared body's schema (stashed) and, once verified, on its response. */
    public const MARK = 'x-particle-error';

    public function pathItem(array $pathItem, array $groupedEndpoints, OutputEndpointData $endpoint): array
    {
        $declared = $endpoint->custom[self::STASH] ?? [];
        if (! is_array($declared) || $declared === []) {
            return $pathItem;
        }

        $responses = $pathItem['responses'] ?? [];
        $responses = is_array($responses) ? $responses : (array) $responses;
        foreach ($declared as $status) {
            $response = $responses[(string) $status] ?? null;
            // Identity, not status (review-r1 14:14Z): a later writer may replace the declared body at the same status
            // (DeliveryGenerator writes its generic format rejection at 422), and that response is not the refusal.
            if (is_array($response) && ($response['content']['application/json']['schema'][self::MARK] ?? false) === true) {
                $responses[(string) $status][self::MARK] = true;
            }
        }
        $pathItem['responses'] = $responses;

        return $pathItem;
    }
}
