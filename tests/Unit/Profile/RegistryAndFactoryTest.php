<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Profile;

use Maatify\Slug\Canonicalization\Exception\SlugProfileAlreadyRegisteredException;
use Maatify\Slug\Canonicalization\Exception\SlugProfileNotFoundException;
use Maatify\Slug\Canonicalization\Exception\SlugRuntimeCompatibilityException;
use Maatify\Slug\Canonicalization\Factory\SlugProfileRegistryFactory;
use Maatify\Slug\Canonicalization\Factory\SlugTextServiceFactory;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Canonicalization\Service\Profile\BuiltIn\AsciiSlugProfile;
use Maatify\Slug\Canonicalization\Service\Profile\BuiltIn\UnicodeSlugProfile;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistryInterface;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistry;
use Maatify\Slug\Canonicalization\Service\SlugTextService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

final class RegistryAndFactoryTest extends TestCase
{
    public function testBuiltInFactoryProvidesIndependentUnicodeAndAsciiRegistries(): void
    {
        $first = $this->builtIns();
        $second = $this->builtIns();

        self::assertNotSame($first, $second);
        self::assertInstanceOf(UnicodeSlugProfile::class, $first->get(new SlugProfileKey('unicode-v1')));
        self::assertInstanceOf(AsciiSlugProfile::class, $first->get(new SlugProfileKey('ascii-v1')));
        self::assertTrue($first->has(new SlugProfileKey('unicode-v1')));
        self::assertTrue($first->has(new SlugProfileKey('ascii-v1')));

        try {
            $first->register(new StubSlugProfile(new SlugProfileKey('unicode-v1')));
            self::fail('Duplicate built-in profile was accepted.');
        } catch (SlugProfileAlreadyRegisteredException $exception) {
            self::assertInstanceOf(SlugProfileAlreadyRegisteredException::class, $exception);
        }

        $first->register(new StubSlugProfile(new SlugProfileKey('custom-v1')));
        self::assertFalse($second->has(new SlugProfileKey('custom-v1')));
    }

    public function testRegistryRejectsDuplicateBuiltInAndCustomKeysWithoutReplacement(): void
    {
        $registry = new SlugProfileRegistry();
        $custom = new StubSlugProfile(new SlugProfileKey('custom-v1'));
        $registry->register($custom);

        try {
            $registry->register(new StubSlugProfile(new SlugProfileKey('custom-v1')));
            self::fail('Duplicate custom profile was accepted.');
        } catch (SlugProfileAlreadyRegisteredException $exception) {
            self::assertInstanceOf(SlugProfileAlreadyRegisteredException::class, $exception);
        }

        self::assertSame($custom, $registry->get(new SlugProfileKey('custom-v1')));
    }

    public function testUnknownProfileUsesTheAcceptedNotFoundException(): void
    {
        $this->expectException(SlugProfileNotFoundException::class);
        (new SlugProfileRegistry())->get(new SlugProfileKey('missing-v1'));
    }

    public function testStatelessFactoriesAndServiceHaveNoPdoDependency(): void
    {
        $builtInFactory = new ReflectionMethod(SlugProfileRegistryFactory::class, 'createBuiltIn');
        $textFactory = new ReflectionMethod(SlugTextServiceFactory::class, 'create');
        self::assertCount(0, $builtInFactory->getParameters());
        self::assertSame(SlugProfileRegistryInterface::class, $this->typeName($textFactory->getParameters()[0]));
        self::assertNotContains(\PDO::class, array_map(fn(ReflectionParameter $parameter): ?string => $this->typeName($parameter), $textFactory->getParameters()));
        self::assertInstanceOf(SlugTextService::class, SlugTextServiceFactory::create(new SlugProfileRegistry()));
    }

    private function typeName(ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();
        return $type instanceof ReflectionNamedType ? $type->getName() : null;
    }

    private function builtIns(): SlugProfileRegistryInterface
    {
        try {
            return SlugProfileRegistryFactory::createBuiltIn();
        } catch (SlugRuntimeCompatibilityException $exception) {
            self::markTestSkipped($exception->getMessage());
        }
    }
}
