<?php

namespace Clickbar\Magellan\Database\Eloquent;

use Clickbar\Magellan\Data\Geometries\Geometry;
use Clickbar\Magellan\Data\Geometries\GeometryCollection;
use Clickbar\Magellan\Exception\PostgisColumnsNotDefinedException;
use Clickbar\Magellan\Exception\SridMissmatchException;
use Clickbar\Magellan\IO\Generator\BaseGenerator;
use Clickbar\Magellan\IO\Generator\WKT\WKTGenerator;
use Clickbar\Magellan\IO\Parser\WKB\WKBParser;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;

trait HasPostgisColumns
{
    public function getPostgisTypeAndSrid(string $key)
    {
        $this->assertKeyIsInPostgisColumns($key);

        $default = [
            'type' => Config::get('magellan.eloquent.default_postgis_type', 'geometry'),
            'srid' => Config::get('magellan.eloquent.default_srid', 4326),
        ];

        return Arr::get($this->postgisColumns, $key, $default);
    }

    public function getPostgisColumnNames()
    {
        $this->assertPostgisColumnsNotEmpty();

        return array_map(function ($key) {
            if (is_int($key)) {
                return $this->postgisColumns[$key];
            }

            return $key;
        }, array_keys($this->postgisColumns));
    }

    private function getGenerator(): BaseGenerator
    {
        $generatorClass = Config::get('magellan.sql_generator', WKTGenerator::class);

        return new $generatorClass();
    }

    protected function geomFromText(Geometry $geometry, int $srid = 4326)
    {
        $generator = $this->getGenerator();
        $geometrySql = $generator->toPostgisGeometrySql($geometry, Config::get('magellan.schema', 'public'));

        if ($geometry->hasSrid() && $geometry->getSrid() != $srid) {
            if (Config::get('magellan.eloquent.transform_to_database_projection', false)) {
                $geometrySql = 'ST_TRANSFORM('.$geometrySql.', '.$srid.')';
            } else {
                throw new SridMissmatchException($srid, $geometry->getSrid());
            }
        }

        return $this->getConnection()->raw($geometrySql);
    }

    protected function geogFromText(Geometry $geometry, int $srid = 4326)
    {
        $generator = $this->getGenerator();
        $geometrySql = $generator->toPostgisGeographySql($geometry, Config::get('magellan.schema', 'public'));

        return $this->getConnection()->raw($geometrySql);
    }

    public function getGeometryAsInsertable(Geometry $geometry, array $columnConfig)
    {
        return match (strtoupper($columnConfig['type'])) {
            'GEOMETRY' => $this->geomFromText($geometry, $columnConfig['srid']),
            default => $this->geogFromText($geometry, $columnConfig['srid']),
        };
    }

    protected function transformGeometriesInAttributes(): array
    {
        $geometryCache = [];

        foreach ($this->attributes as $key => $value) {
            if ($value instanceof Geometry) {
                $geometryCache[$key] = $value; //Preserve the geometry objects prior to the insert
                if ($value instanceof GeometryCollection) {
                    // --> Only insertable into geometry column types
                    $this->attributes[$key] = $this->geomFromText($value);
                } else {
                    $columnConfig = $this->getPostgisTypeAndSrid($key);
                    $this->attributes[$key] = $this->getGeometryAsInsertable($value, $columnConfig);
                }
            }
        }

        return $geometryCache;
    }

    protected function restoreOriginalGeometriesInAttributes(array $originalGeometries)
    {
        foreach ($originalGeometries as $key => $value) {
            $this->attributes[$key] = $value; //Retrieve the geometry objects, so they can be used in the model
        }
    }

    protected function performInsert(EloquentBuilder $query, array $options = [])
    {
        $originalGeometries = $this->transformGeometriesInAttributes();
        $insert = parent::performInsert($query, $options);
        $this->restoreOriginalGeometriesInAttributes($originalGeometries);

        return $insert; //Return the result of the parent insert
    }

    protected function performUpdate(EloquentBuilder $query)
    {
        $originalGeometries = $this->transformGeometriesInAttributes();
        $update = parent::performUpdate($query);
        $this->restoreOriginalGeometriesInAttributes($originalGeometries);

        return $update; //Return the result of the parent insert
    }

    public function setRawAttributes(array $attributes, $sync = false)
    {
        $pgfields = $this->getPostgisColumnNames();

        // PostGIS always returns the geometry as a WKB string, so we need to convert it to a Geometry object
        $parser = App::make(WKBParser::class);

        foreach ($attributes as $key => &$value) {
            if (in_array($key, $pgfields) && is_string($value)) {
                $value = $parser->parse($value);
            }
        }

        return parent::setRawAttributes($attributes, $sync);
    }

    protected function assertPostgisColumnsNotEmpty()
    {
        if (! property_exists($this, 'postgisColumns')) {
            throw new PostgisColumnsNotDefinedException(__CLASS__.' has not defined any postgis columns within the $postgisColumns property.');
        }
    }

    protected function assertKeyIsInPostgisColumns(string $key)
    {
        if (! in_array($key, $this->getPostgisColumnNames())) {
            throw new PostgisColumnsNotDefinedException(__CLASS__." has not defined the column '$key' within the \$postgisColumns property.");
        }
    }
}
