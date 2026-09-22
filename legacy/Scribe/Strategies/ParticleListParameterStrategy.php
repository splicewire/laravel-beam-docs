<?php

namespace Splicewire\Beam\Scribe\Strategies;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Strategy;
use Knuckles\Scribe\Tools\DocumentationConfig;
use ReflectionClass;
use Rushing\DataFilters\Facades\DataFilter;
use Rushing\DataFilters\Keywords;
use Rushing\DataFilters\Query\ResourceQuery;
use Schemastud\DataSchemas\Generators\Generator;
use Splicewire\Beam\Discovery\SubSurface;
use Splicewire\Beam\Filters\ResourceFilterDefinition;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Routing\BeamRouteAction;
use Splicewire\Beam\Routing\RouteMetadataReader;

/**
 * Document a DISSOLVED particle index's full list contract from the declarations the route already carries.
 *
 * The sibling of {@see ParticleRequestStrategy} one axis over: the schema signal is the ROUTE (its
 * `_particle` default names a {@see ParticleResource}), and {@see ParticleResource::$key} IS the
 * data-filters resource key, so this needs ZERO new declaration — no config lookup, no per-resource
 * registration.
 *
 * Two sources, deliberately, because neither is sufficient alone (api-surface-coherence ticket 07 §2):
 *
 *  - **The SET** comes from {@see ResourceQuery::filterNames()} and friends,
 *    which merge the attribute-declared facets with the imperative `extraFilters()`/`extraSorts()`/
 *    `extraIncludes()` escape hatches. Deriving the set from the schema instead would silently DROP every
 *    closure filter — relocating the exact defect this strategy exists to close.
 *  - **The TYPES** come from the generated Filter-Data schema (the same generator and
 *    `config('data-schemas.strategies')` wiring `filter-schema/{resource}` serves at runtime), joined to
 *    the set by facet NAME — not property name, since `#[Filterable(name: …)]` routinely renames a facet.
 *    A name with no backing property documents as UNTYPED; it is never omitted.
 *
 * Four axes, one strategy: `filter[…]`, `sort`, `include`, and pagination. They all ride the same
 * spatie/laravel-query-builder grammar off the same resource key, so splitting them would be four
 * resolutions of one declaration for no gained knob.
 *
 * Every name here is DERIVED, never spelled: the `filter`/`sort`/`include` prefixes come from
 * `config('query-builder.parameters')` and pagination from {@see ParticleController::PAGE}/`PER_PAGE`,
 * so the camelCase cutover (ticket 22) carries the reference with it and nothing in this file changes.
 *
 * Returns `null` (defer) for any non-particle route, so it composes transparently alongside Scribe's
 * stock query-parameter strategies.
 */
class ParticleListParameterStrategy extends Strategy
{
    protected RouteMetadataReader $meta;

    /**
     * Scribe constructs strategies itself — `new $strategyClass($this->config)`
     * (`knuckleswtf/scribe/src/Extracting/Extractor.php:470`), bypassing the container — so the arity is
     * fixed by a third party and the reader arrives as a DEFAULTED parameter rather than a required one
     * (api-surface-coherence 126). Defaulted, not resolved inline with `app()`: a test can hand this
     * object a reader directly, which service location does not allow.
     */
    public function __construct(DocumentationConfig $config, ?RouteMetadataReader $meta = null)
    {
        parent::__construct($config);

        $this->meta = $meta ?? BeamRouteAction::reader();
    }

    public function __invoke(ExtractedEndpointData $endpointData, array $settings = []): ?array
    {
        $key = $endpointData->route?->defaults[ParticleController::RESOURCE] ?? null;

        if ($key === null) {
            return null; // Not a particle route — defer to the other strategies.
        }

        // The list contract belongs to the resource's own collection READ. show/store/update/destroy
        // take none of it, and neither do the sub-surfaces mounted beside the resource.
        if (! $this->isCollectionRead($endpointData)) {
            return [];
        }

        // ASK, don't demand (api-surface-coherence 102). `inResource()` stamps this same route default on
        // a HAND-ROLLED exposure, and its argument is a *data-filters* resource key that need not also be
        // a `#[ParticleResource]` — `guest-links` and `releases` at the flagship are both, deliberately.
        // Demanding here turned that legitimate mount into a per-route `RuntimeException`; Scribe catches
        // per-route, prints only under `-v` and exits WARN, so 30 live endpoints were absent from
        // `openapi.yaml`, the SDK and the docs surface with nothing on screen but a mild warning.
        $resource = app(ParticleResourceRegistry::class)->find($key);

        if ($resource === null) {
            // No particle declaration, but the key may still be a data-filters resource — which is the
            // whole point of `inResource($key)`. Document the query contract that IS
            // declared and omit pagination: a hand-rolled index chooses its own paging (the flagship's
            // `ReleaseController::index` does a bare `->get()`), so `perPage` would be an invention.
            // The absence itself is reported by `ParticleRouteResourceAudit`, not swallowed.
            return $this->fromFilterRegistry($key);
        }

        // Use the runtime's declared filter definition, including inferred Data attributes.
        $resolver = app(ResourceFilterDefinition::class);
        $definition = $resolver->definition($resource->key);

        if ($definition === null) {
            return $this->pagination($resource);
        }

        $query = $resolver->query($definition);
        $facets = $this->facets($definition->data);

        return [
            ...$this->filters($query->filterNames(), $facets['filters']),
            ...$this->sorts($query->sortNames()),
            ...$this->includes($query->includeNames()),
            ...$this->pagination($resource),
        ];
    }

    /**
     * Collection cardinality belongs to the declared return shape. The conventional CRUD index
     * method also denotes a collection; capability routes never inherit that list contract.
     */
    protected function isCollectionRead(ExtractedEndpointData $endpointData): bool
    {
        $route = $endpointData->route;

        if ($route === null || SubSurface::of($route) !== SubSurface::CRUD) {
            return false;
        }

        return $this->meta->returnsMany($route)
            || $endpointData->method?->getName() === 'index';
    }

    /**
     * The filter/sort/include contract for a route stamped `inResource()` with a key that has a
     * data-filters declaration but no `#[ParticleResource]` (api-surface-coherence 102).
     *
     * Deliberately the same three axes minus pagination — see the caller. A key registered in NEITHER
     * registry documents as a plain endpoint with no query contract, which is the honest reading of a
     * stamp that resolves to nothing.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function fromFilterRegistry(string $key): array
    {
        $definition = DataFilter::tryResource($key);

        if ($definition === null) {
            return [];
        }

        $query = DataFilter::query($key);
        $facets = $this->facets($definition->data);

        return [
            ...$this->filters($query->filterNames(), $facets['filters']),
            ...$this->sorts($query->sortNames()),
            ...$this->includes($query->includeNames()),
        ];
    }

    /**
     * The declared facets, indexed by the name they answer to ON THE WIRE — `x-filter.name` /
     * `x-sort.name` — rather than by property name.
     *
     * @param  class-string  $dataClass
     * @return array{filters: array<string, array<string, mixed>>, sorts: array<string, array<string, mixed>>}
     */
    protected function facets(string $dataClass): array
    {
        // Container-resolved, and this site changed in TWO ways rather than one.
        //
        // Dispatch: `data-schemas.generators` is a LIST and the rule "the first member whose
        // `canGenerate()` accepts this class" lives only inside `ChainedGenerator`, so hand-building
        // the default member ran the PLAIN generator over a class a narrow member owns at
        // `~/Herd/thingsontv` — a downgraded facet set behind a successful extraction.
        //
        // Config: the hand-build passed `strategies` and NOTHING ELSE, which withheld `base_uri`
        // from every class reaching it. That is the exact `a6989da` shape — a `data:` class
        // implementing `SchemaIdentity` threw `MissingSchemaBaseUri` here, Scribe caught it
        // per-route and printed only under `-v`, and the index endpoint left the spec. This file
        // already carries one 30-endpoint scar from that mechanism (see `__invoke`); the narrowed
        // config was a second one waiting.
        //
        // Widening the config is safe for what this method reads. Backed enums — the only facet type
        // whose `$defs` entry `dereference()` needs — always hoist as `#/$defs/<Short>`
        // (`ensureEnumDef()` never consults `base_uri`), so enum accepted-value lists are unchanged.
        // A nested SchemaIdentity OBJECT now hoists under its absolute `$id` instead, which
        // `dereference()` declines to follow — and that degrades correctly, because the property's
        // own keywords win there anyway and `x-filter`/`x-sort` live on the property, not the `$defs`
        // entry.
        //
        // GUARDED: the chain throws where the hand-built generator generated regardless, and a throw
        // here is silent amputation, not a loud failure. A refused class yields no facets, so the
        // filter/sort names still publish from the data-filters query — untyped and undescribed,
        // which is what this method's own `$facets[$name] ?? null` fallback already handles.
        $reflection = new ReflectionClass($dataClass);
        $generator = app(Generator::class);

        if (! $generator->canGenerate($reflection)) {
            return ['filters' => [], 'sorts' => []];
        }

        $schema = $generator->generate($reflection);

        $filters = [];
        $sorts = [];

        foreach ($schema['properties'] ?? [] as $property) {
            $property = $this->dereference($property, $schema);

            if ($name = $property[Keywords::Filter]['name'] ?? null) {
                $filters[$name] = $property;
            }

            if ($name = $property[Keywords::Sort]['name'] ?? null) {
                $sorts[$name] = $property;
            }
        }

        return ['filters' => $filters, 'sorts' => $sorts];
    }

    /**
     * Fold a local `$ref`, including a nullable wrapper, back into the property.
     *
     * A backed-enum facet emits `{$ref: #/$defs/Status}`, wrapped in `anyOf` with `null` when nullable,
     * and carries no `type` of its own. Resolve only a sole non-null member: a wider union can admit
     * values outside that enum and must not be documented as a finite domain.
     *
     * @param  array<string, mixed>  $property
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected function dereference(array $property, array $schema): array
    {
        $ref = $property['$ref'] ?? null;

        if ($ref === null) {
            $members = array_values(array_filter(
                $property['anyOf'] ?? [],
                fn (array $member) => ($member['type'] ?? null) !== 'null',
            ));

            if (count($members) === 1) {
                $ref = $members[0]['$ref'] ?? null;
            }
        }

        if (! is_string($ref) || ! str_starts_with($ref, '#/$defs/')) {
            return $property;
        }

        $definition = $schema['$defs'][substr($ref, strlen('#/$defs/'))] ?? null;

        // The property's own keywords win: `$defs` describes the TYPE, the property describes this USE
        // of it (which is where `x-filter` and any per-property prose live).
        return is_array($definition) ? [...$definition, ...$property] : $property;
    }

    /**
     * @param  list<string>  $names
     * @param  array<string, array<string, mixed>>  $facets
     * @return array<string, array<string, mixed>>
     */
    protected function filters(array $names, array $facets): array
    {
        $prefix = config('query-builder.parameters.filter', 'filter');
        $parameters = [];

        foreach ($names as $name) {
            $property = $facets[$name] ?? null;
            $keyword = $property[Keywords::Filter] ?? [];

            $parameters["{$prefix}[{$name}]"] = [
                'type' => $this->scribeType($property),
                'description' => $this->filterDescription($name, $property, $keyword),
                'required' => false,
                'enumValues' => $this->enumValues($property),
                // No example on purpose: an optional parameter with a null example is documented but left
                // out of the rendered example request, which is what keeps a 20-facet resource readable.
                'example' => null,
            ];
        }

        return $parameters;
    }

    /**
     * @param  list<string>  $names
     * @return array<string, array<string, mixed>>
     */
    protected function sorts(array $names): array
    {
        if ($names === []) {
            return [];
        }

        $sort = config('query-builder.parameters.sort', 'sort');

        return [$sort => [
            'type' => 'string',
            'description' => 'Sort the result set by one of '.$this->code($names)
                .'. Prefix with `-` for descending (e.g. `-'.$names[0].'`). Comma-separate to sort by several.',
            'required' => false,
            'example' => null,
        ]];
    }

    /**
     * @param  list<string>  $names
     * @return array<string, array<string, mixed>>
     */
    protected function includes(array $names): array
    {
        if ($names === []) {
            return [];
        }

        $include = config('query-builder.parameters.include', 'include');

        return [$include => [
            'type' => 'string',
            'description' => 'Comma-separated related resources to load alongside each record. One or more of '
                .$this->code($names).'.',
            'required' => false,
            'example' => null,
        ]];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function pagination(ParticleResource $resource): array
    {
        return [
            ParticleController::PAGE => [
                'type' => 'integer',
                'description' => 'The page of results to return.',
                'required' => false,
                'example' => 1,
            ],
            ParticleController::PER_PAGE => [
                'type' => 'integer',
                'description' => "Records per page. Defaults to {$resource->perPage}.",
                'required' => false,
                'example' => $resource->perPage,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $property
     * @param  array<string, mixed>  $keyword
     */
    protected function filterDescription(string $name, ?array $property, array $keyword): string
    {
        if ($property === null) {
            // An escape-hatch filter: real, accepted, and invisible to the schema because it has no backing
            // property. Documented untyped rather than dropped.
            return "Filter by `{$name}`.";
        }

        $description = $property['description'] ?? "Filter by `{$name}`.";

        if ($operator = $keyword['operator'] ?? null) {
            $description .= " Matched with the `{$operator}` operator.";
        }

        return $description;
    }

    /**
     * The JSON-Schema type of the backing property, mapped onto Scribe's vocabulary. A declared `array`
     * facet documents as a **string**: spatie/laravel-query-builder splits the value on its configured
     * delimiter, so `filter[tags]=a,b` is what actually goes over the wire.
     *
     * @param  array<string, mixed>|null  $property
     */
    protected function scribeType(?array $property): string
    {
        $declared = array_values(array_filter(
            (array) ($property['type'] ?? []),
            fn ($type) => $type !== 'null',
        ));

        return match ($declared[0] ?? null) {
            'integer' => 'integer',
            'number' => 'number',
            'boolean' => 'boolean',
            default => 'string',
        };
    }

    /**
     * The finite domain a facet accepts, read off the (dereferenced) property schema. Absent for
     * relational facets, whose domain is a table rather than an enumeration.
     *
     * @param  array<string, mixed>|null  $property
     * @return list<mixed>
     */
    protected function enumValues(?array $property): array
    {
        return array_values((array) ($property['enum'] ?? []));
    }

    /**
     * @param  list<string>  $names
     */
    protected function code(array $names): string
    {
        return implode(', ', array_map(fn (string $name) => "`{$name}`", $names));
    }
}
