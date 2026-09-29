<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Compilation;

use ReflectionClass;
use ReflectionException;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

use function class_exists;

/**
 * Общие проверки типов для AOT-анализатора и генератора, чтобы они принимали одинаковые решения.
 */
final class AotTypeInspector
{
    /**
     * Есть ли в типе хотя бы один класс/интерфейс (в т.ч. внутри union/intersection).
     */
    public static function hasClassType(ReflectionType $type): bool
    {
        if ($type instanceof ReflectionNamedType) {
            return !$type->isBuiltin();
        }

        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $nestedType) {
                if (self::hasClassType($nestedType)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Аналог проверки autowiring в runtime: класс существует и может быть создан.
     */
    public static function isInstantiableClass(string $className): bool
    {
        if (!class_exists($className)) {
            return false;
        }

        try {
            return new ReflectionClass($className)->isInstantiable();
        } catch (ReflectionException) {
            return false;
        }
    }
}
