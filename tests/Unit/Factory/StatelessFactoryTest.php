<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Factory;

use Maatify\Slug\Canonicalization\Service\SlugTextServiceInterface;
use Maatify\Slug\Canonicalization\Factory\SlugProfileRegistryFactory;
use Maatify\Slug\Canonicalization\Factory\SlugTextServiceFactory;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistryInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

final class StatelessFactoryTest extends TestCase
{
    public function testFactorySignaturesAreStatelessAndFrameworkNeutral(): void
    {
        $registryMethod = new ReflectionMethod(SlugProfileRegistryFactory::class, 'createBuiltIn');
        $textMethod = new ReflectionMethod(SlugTextServiceFactory::class, 'create');

        self::assertSame([], $registryMethod->getParameters());
        self::assertCount(1, $textMethod->getParameters());
        self::assertSame(SlugProfileRegistryInterface::class, $this->typeName($textMethod->getParameters()[0]));
        self::assertSame(SlugTextServiceInterface::class, $this->returnTypeName($textMethod));
        self::assertNotContains(\PDO::class, array_map(fn(ReflectionParameter $parameter): ?string => $this->typeName($parameter), $textMethod->getParameters()));
    }

    private function typeName(ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();
        return $type instanceof ReflectionNamedType ? $type->getName() : null;
    }

    private function returnTypeName(ReflectionMethod $method): ?string
    {
        $type = $method->getReturnType();
        return $type instanceof ReflectionNamedType ? $type->getName() : null;
    }
}
