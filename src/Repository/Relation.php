<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use InvalidArgumentException;

final class Relation
{
    private function __construct() {}

    /** @param class-string<TableRepository> $related */
    public static function belongsTo(
        string $related,
        string $foreignKey,
        string $ownerKey = 'id',
    ): RelationDefinition {
        return new RelationDefinition(
            RelationDefinition::BELONGS_TO,
            $related,
            $foreignKey,
            $ownerKey,
        );
    }

    /** @param class-string<TableRepository> $related */
    public static function belongsToMany(
        string $related,
        string $pivot,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $parentKey = 'id',
        string $relatedKey = 'id',
    ): RelationDefinition {
        return new RelationDefinition(
            RelationDefinition::BELONGS_TO_MANY,
            $related,
            $parentKey,
            $relatedKey,
            pivotTable: $pivot,
            pivotParentKey: $foreignPivotKey,
            pivotRelatedKey: $relatedPivotKey,
        );
    }

    /** @param class-string<TableRepository> $related */
    public static function hasMany(
        string $related,
        string $foreignKey,
        string $localKey = 'id',
    ): RelationDefinition {
        return new RelationDefinition(
            RelationDefinition::HAS_MANY,
            $related,
            $localKey,
            $foreignKey,
        );
    }

    /**
     * Define a has-many-through relation using explicit intermediate keys.
     *
     * @param class-string<TableRepository> $related
     * @param class-string<TableRepository> $through
     */
    public static function hasManyThrough(
        string $related,
        string $through,
        string $firstKey,
        string $secondKey,
        string $localKey = 'id',
        string $secondLocalKey = 'id',
    ): RelationDefinition {
        return new RelationDefinition(
            RelationDefinition::HAS_MANY,
            $related,
            $localKey,
            $secondKey,
            through: $through,
            throughParentKey: $firstKey,
            throughKey: $secondLocalKey,
        );
    }

    /** @param class-string<TableRepository> $related */
    public static function hasOne(
        string $related,
        string $foreignKey,
        string $localKey = 'id',
    ): RelationDefinition {
        return new RelationDefinition(
            RelationDefinition::HAS_ONE,
            $related,
            $localKey,
            $foreignKey,
        );
    }

    /**
     * Define a has-one-through relation using explicit intermediate keys.
     *
     * @param class-string<TableRepository> $related
     * @param class-string<TableRepository> $through
     */
    public static function hasOneThrough(
        string $related,
        string $through,
        string $firstKey,
        string $secondKey,
        string $localKey = 'id',
        string $secondLocalKey = 'id',
    ): RelationDefinition {
        return new RelationDefinition(
            RelationDefinition::HAS_ONE,
            $related,
            $localKey,
            $secondKey,
            through: $through,
            throughParentKey: $firstKey,
            throughKey: $secondLocalKey,
        );
    }

    /**
     * Inverse polymorphic many-to-many uses the same bounded pivot mechanism;
     * naming this factory keeps repository definitions readable.
     *
     * @param class-string<TableRepository> $related
     */
    public static function morphedByMany(
        string $related,
        string $pivot,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $morphTypeColumn,
        string $morph,
        string $parentKey = 'id',
        string $relatedKey = 'id',
    ): RelationDefinition {
        return self::morphToMany(
            $related,
            $pivot,
            $foreignPivotKey,
            $relatedPivotKey,
            $morphTypeColumn,
            $morph,
            $parentKey,
            $relatedKey,
        );
    }

    /**
     * Define a polymorphic has-many relation using an explicit discriminator.
     *
     * @param class-string<TableRepository> $related
     */
    public static function morphMany(
        string $related,
        string $name,
        string $morph,
        string $localKey = 'id',
    ): RelationDefinition {
        [$typeColumn, $idColumn] = self::morphColumns($name);

        return new RelationDefinition(
            RelationDefinition::MORPH_MANY,
            $related,
            $localKey,
            $idColumn,
            morphTypeColumn: $typeColumn,
            morphIdColumn: $idColumn,
            morphAlias: self::requireName($morph, 'morph alias'),
        );
    }

    /**
     * Define a polymorphic has-one relation using an explicit discriminator.
     *
     * @param class-string<TableRepository> $related
     */
    public static function morphOne(
        string $related,
        string $name,
        string $morph,
        string $localKey = 'id',
    ): RelationDefinition {
        [$typeColumn, $idColumn] = self::morphColumns($name);

        return new RelationDefinition(
            RelationDefinition::MORPH_ONE,
            $related,
            $localKey,
            $idColumn,
            morphTypeColumn: $typeColumn,
            morphIdColumn: $idColumn,
            morphAlias: self::requireName($morph, 'morph alias'),
        );
    }

    /**
     * Define a polymorphic inverse relation. Database discriminator values are
     * resolved only through the supplied map; class names are never inferred.
     *
     * @param array<string,class-string<TableRepository>> $morphMap
     */
    public static function morphTo(
        string $typeColumn,
        string $idColumn,
        array $morphMap,
        string $ownerKey = 'id',
    ): RelationDefinition {
        if ($morphMap === []) {
            throw new InvalidArgumentException('Morph-to relations require a non-empty morph map.');
        }

        return new RelationDefinition(
            RelationDefinition::MORPH_TO,
            null,
            self::requireName($idColumn, 'morph id column'),
            self::requireName($ownerKey, 'morph owner key'),
            morphTypeColumn: self::requireName($typeColumn, 'morph type column'),
            morphIdColumn: $idColumn,
            morphMap: $morphMap,
        );
    }

    /**
     * Define a polymorphic many-to-many relation. The pivot discriminator is
     * explicit and never derived from a PHP class name.
     *
     * @param class-string<TableRepository> $related
     */
    public static function morphToMany(
        string $related,
        string $pivot,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $morphTypeColumn,
        string $morph,
        string $parentKey = 'id',
        string $relatedKey = 'id',
    ): RelationDefinition {
        return new RelationDefinition(
            RelationDefinition::MORPH_TO_MANY,
            $related,
            $parentKey,
            $relatedKey,
            pivotTable: $pivot,
            pivotParentKey: $foreignPivotKey,
            pivotRelatedKey: $relatedPivotKey,
            morphTypeColumn: self::requireName($morphTypeColumn, 'morph type column'),
            morphAlias: self::requireName($morph, 'morph alias'),
        );
    }

    /** @return array{0:string,1:string} */
    private static function morphColumns(string $name): array
    {
        $name = self::requireName($name, 'morph name');

        return [$name . '_type', $name . '_id'];
    }

    private static function requireName(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException(ucfirst($label) . ' must not be empty.');
        }

        return $value;
    }
}
