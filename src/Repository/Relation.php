<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

final class Relation
{
    /**
     * @param class-string<TableRepository> $related
     */
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

    /**
     * @param class-string<TableRepository> $related
     */
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
     * @param class-string<TableRepository> $related
     */
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
     * @param class-string<TableRepository> $related
     */
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

    private function __construct() {}
}
