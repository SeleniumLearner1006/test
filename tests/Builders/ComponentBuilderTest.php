<?php

declare(strict_types=1);

/*
 * This file is part of CycloneDX PHP Composer Plugin.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * SPDX-License-Identifier: Apache-2.0
 * Copyright (c) OWASP Foundation. All Rights Reserved.
 */

namespace CycloneDX\Tests\Builders;

use Composer\Package\CompletePackageInterface;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackage;
use CycloneDX\Composer\Builders\ComponentBuilder;
use CycloneDX\Composer\Builders\ExternalReferenceRepositoryBuilder;
use CycloneDX\Composer\Factories\LicenseFactory;
use CycloneDX\Composer\Factories\PackageUrlFactory;
use CycloneDX\Core\Enums\HashAlgorithm;
use CycloneDX\Core\Models\Component;
use CycloneDX\Core\Repositories\DisjunctiveLicenseRepository;
use CycloneDX\Core\Repositories\ExternalReferenceRepository;
use CycloneDX\Core\Repositories\HashRepository;
use PackageUrl\PackageUrl;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CycloneDX\Composer\Builders\ComponentBuilder
 *
 * @uses   \CycloneDX\Core\Models\Component
 * @uses   \CycloneDX\Core\Models\BomRef
 */
class ComponentBuilderTest extends TestCase
{
    public function testConstructor(): ComponentBuilder
    {
        $licenseFactory = $this->createMock(LicenseFactory::class);
        $packageUrlFactory = $this->createMock(PackageUrlFactory::class);
        $enableVersionNormalization = true;
        $extRefBuilder = $this->createMock(ExternalReferenceRepositoryBuilder::class);

        $builder = new ComponentBuilder($licenseFactory, $packageUrlFactory, $extRefBuilder, $enableVersionNormalization);

        self::assertSame($licenseFactory, $builder->getLicenseFactory());
        self::assertSame($packageUrlFactory, $builder->getPackageUrlFactory());
        self::assertSame($enableVersionNormalization, $builder->getVersionNormalization());

        return $builder;
    }

    /**
     * @depends testConstructor
     */
    public function testGetterSetterVersionNormalization(ComponentBuilder $builder): void
    {
        $enableVersionNormalization = !$builder->getVersionNormalization();

        $actual = $builder->setVersionNormalization($enableVersionNormalization);

        self::assertSame($builder, $actual);
        self::assertSame($enableVersionNormalization, $builder->getVersionNormalization());
    }

    public function testMakeFromPackageThrowsOnEmptyName(): void
    {
        $licenseFactory = $this->createStub(LicenseFactory::class);
        $packageUrlFactory = $this->createStub(PackageUrlFactory::class);
        $extRefBuilder = $this->createMock(ExternalReferenceRepositoryBuilder::class);
        $builder = new ComponentBuilder($licenseFactory, $packageUrlFactory, $extRefBuilder);
        $package = $this->createConfiguredMock(
            PackageInterface::class,
            [
                'getPrettyName' => '',
            ]
        );

        $this->expectException(\UnexpectedValueException::class);
        $this->expectErrorMessageMatches('/package without name/i');

        $builder->makeFromPackage($package);
    }

    public function testMakeFromPackageThrowsOnEmptyVersion(): void
    {
        $licenseFactory = $this->createStub(LicenseFactory::class);
        $packageUrlFactory = $this->createStub(PackageUrlFactory::class);
        $extRefBuilder = $this->createMock(ExternalReferenceRepositoryBuilder::class);
        $builder = new ComponentBuilder($licenseFactory, $packageUrlFactory, $extRefBuilder);
        $package = $this->createConfiguredMock(
            PackageInterface::class,
            [
                'getPrettyName' => 'vendor/name',
                'getPrettyVersion' => '',
            ]
        );

        $this->expectException(\UnexpectedValueException::class);
        $this->expectErrorMessageMatches('/package without version/i');

        $builder->makeFromPackage($package, '');
    }

    /**
     * @uses \CycloneDX\Core\Enums\Classification::isValidValue
     * @uses \CycloneDX\Core\Enums\HashAlgorithm::isValidValue
     * @uses \CycloneDX\Core\Repositories\HashRepository
     */
    public function testMakeFromPackageEmptyPurlOnThrow(): void
    {
        $licenseFactory = $this->createStub(LicenseFactory::class);
        $packageUrlFactory = $this->createMock(PackageUrlFactory::class);
        $extRefBuilder = $this->createMock(ExternalReferenceRepositoryBuilder::class);
        $builder = new ComponentBuilder($licenseFactory, $packageUrlFactory, $extRefBuilder);
        $package = $this->createConfiguredMock(
            PackageInterface::class,
            [
                'getType' => 'library',
                'getPrettyName' => 'some-library',
                'getPrettyVersion' => '1.2.3',
            ],
        );

        $packageUrlFactory->expects(self::once())
            ->method('makeFromComponent')
            ->willThrowException(new \DomainException());

        $actual = $builder->makeFromPackage($package);

        self::assertNull($actual->getPackageUrl());
    }

    /**
     * @dataProvider dpMakeFromPackage
     *
     * @uses         \CycloneDX\Core\Enums\Classification::isValidValue
     * @uses         \CycloneDX\Core\Enums\HashAlgorithm::isValidValue
     * @uses         \CycloneDX\Core\Repositories\HashRepository
     */
    public function testMakeFromPackage(
        PackageInterface $package,
        Component $expected,
        bool $enableVersionNormalization = true,
        ?LicenseFactory $licenseFactory = null
    ): void {
        $externalReferenceRepository = $this->createStub(ExternalReferenceRepository::class);
        $expected->setExternalReferenceRepository($externalReferenceRepository);

        $extRefBuilder = $this->createMock(ExternalReferenceRepositoryBuilder::class);
        $packageUrlFactory = $this->createMock(PackageUrlFactory::class);
        $builder = new ComponentBuilder(
            $licenseFactory ?? $this->createStub(LicenseFactory::class),
            $packageUrlFactory,
            $extRefBuilder,
            $enableVersionNormalization
        );

        $purlMadeFromComponent = null;
        $packageUrlFactory->expects(self::once())
            ->method('makeFromComponent')
            ->with(
                $this->callback(
                    static function (Component $c) use (&$purlMadeFromComponent): bool {
                        $purlMadeFromComponent = $c;

                        return true;
                    }
                )
            )
            ->willReturn($expected->getPackageUrl());
        $extRefBuilder->expects(self::once())
            ->method('makeFromPackage')
            ->with($package)
            ->willReturn($externalReferenceRepository);

        $actual = $builder->makeFromPackage($package);

        self::assertEquals($expected, $actual);
        self::assertSame($actual, $purlMadeFromComponent);
    }

    /**
     * @psalm-return \Generator<string, list{PackageInterface, Component, bool?, ?LicenseFactory?}>
     */
    public function dpMakeFromPackage(): \Generator
    {
        yield 'minimal library' => [
            $this->createConfiguredMock(
                PackageInterface::class,
                [
                    'getType' => 'library',
                    'getPrettyName' => 'some-library',
                    'getPrettyVersion' => '1.2.3',
                ],
            ),
            (new Component('library', 'some-library', '1.2.3'))
                ->setPackageUrl((new PackageUrl('composer', 'some-library'))->setVersion('1.2.3'))
                ->setBomRefValue('pkg:composer/some-library@1.2.3'),
        ];

        yield 'minimal project' => [
            $this->createConfiguredMock(
                PackageInterface::class,
                [
                    'getType' => 'project',
                    'getPrettyName' => 'some-project',
                    'getPrettyVersion' => '1.2.3',
                ],
            ),
            (new Component('application', 'some-project', '1.2.3'))
                ->setPackageUrl((new PackageUrl('composer', 'some-project'))->setVersion('1.2.3'))
                ->setBomRefValue('pkg:composer/some-project@1.2.3'),
        ];

        yield 'minimal composer-plugin' => [
            $this->createConfiguredMock(
                PackageInterface::class,
                [
                    'getType' => 'composer-plugin',
                    'getPrettyName' => 'some-composer-plugin',
                    'getPrettyVersion' => '1.2.3',
                ],
            ),
            (new Component('application', 'some-composer-plugin', '1.2.3'))
                ->setPackageUrl((new PackageUrl('composer', 'some-composer-plugin'))->setVersion('1.2.3'))
                ->setBomRefValue('pkg:composer/some-composer-plugin@1.2.3'),
        ];

        yield 'minimal inDev of unknown type' => [
            $this->createConfiguredMock(
                PackageInterface::class,
                [
                    'getType' => 'myType',
                    'getPrettyName' => 'some-inDev',
                    'getPrettyVersion' => 'dev-master',
                    'isDev' => true,
                ],
            ),
            (new Component('library', 'some-inDev', 'dev-master'))
                ->setPackageUrl((new PackageUrl('composer', 'some-inDev'))->setVersion('dev-master'))
                ->setBomRefValue('pkg:composer/some-inDev@dev-master'),
        ];

        /**
         * Fallback behaviour if {@see RootPackage::DEFAULT_PRETTY_VERSION} is undefined.
         *
         * @see ComponentBuilder::compatGetDefaultPrettyVersion()
         *
         * @var string
         */
        $_rp_DEFAULT_PRETTY_VERSION = \defined(RootPackage::class.'::DEFAULT_PRETTY_VERSION')
            ? RootPackage::DEFAULT_PRETTY_VERSION
            : '1.0.0+no-version-set';
        yield 'minimal without version fallback' => [
            $this->createConfiguredMock(
                RootPackage::class,
                [
                    'getType' => 'myType',
                    'getPrettyName' => 'some-noVersion',
                    'getPrettyVersion' => '',
                ],
            ),
            (new Component('library', 'some-noVersion', $_rp_DEFAULT_PRETTY_VERSION))
                ->setPackageUrl((new PackageUrl('composer', 'some-noVersion'))->setVersion(null))
                ->setBomRefValue('pkg:composer/some-noVersion'),
        ];

        $completePackage = $this->createConfiguredMock(
            CompletePackageInterface::class,
            [
                'getPrettyName' => 'my/package',
                'getPrettyVersion' => 'v1.2.3',
                'getDescription' => 'my description',
                'getLicense' => ['MIT'],
                'getDistSha1Checksum' => '12345678901234567890123456789012',
            ]
        );
        $license = $this->createStub(DisjunctiveLicenseRepository::class);
        $licenseFactory = $this->createMock(LicenseFactory::class);
        $licenseFactory->expects(self::once())
            ->method('makeFromPackage')
            ->with($completePackage)
            ->willReturn($license);
        yield 'complete library' => [
            $completePackage,
            (new Component('library', 'package', '1.2.3'))
                ->setGroup('my')
                ->setPackageUrl(
                    (new PackageUrl('composer', 'package'))
                        ->setNamespace('my')
                        ->setVersion('1.2.3')
                        ->setChecksums(['sha1:12345678901234567890123456789012'])
                )
                ->setDescription('my description')
                ->setLicense($license)
                ->setHashRepository(new HashRepository([HashAlgorithm::SHA_1 => '12345678901234567890123456789012']))
                ->setBomRefValue('pkg:composer/my/package@1.2.3?checksum=sha1:12345678901234567890123456789012'),
            true,
            $licenseFactory,
        ];

        yield 'library with non-normalized version' => [
            $this->createConfiguredMock(
                CompletePackageInterface::class,
                [
                    'getPrettyName' => 'my/package',
                    'getPrettyVersion' => 'v1.2.3',
                ]
            ),
            (new Component('library', 'package', 'v1.2.3'))
                ->setGroup('my')
                ->setPackageUrl(
                    (new PackageUrl('composer', 'package'))
                        ->setNamespace('my')
                        ->setVersion('v1.2.3')
                )
                ->setBomRefValue('pkg:composer/my/package@v1.2.3'),
            false,
        ];
    }
}
