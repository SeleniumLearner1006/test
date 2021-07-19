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
 * Copyright (c) Steve Springett. All Rights Reserved.
 */

namespace CycloneDX\Tests\Composer\Factories;

use Composer\Package\CompletePackageInterface;
use Composer\Package\PackageInterface;
use CycloneDX\Composer\Factories\ComponentFactory;
use CycloneDX\Composer\Factories\LicenseFactory;
use CycloneDX\Core\Enums\HashAlgorithm;
use CycloneDX\Core\Models\Component;
use CycloneDX\Core\Repositories\ComponentRepository;
use CycloneDX\Core\Repositories\DisjunctiveLicenseRepository;
use CycloneDX\Core\Repositories\HashRepository;
use PackageUrl\PackageUrl;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CycloneDX\Composer\Factories\ComponentFactory
 *
 * @uses   \CycloneDX\Core\Models\Component
 */
class ComponentFactoryTest extends TestCase
{
    public function testLicenseFactoryGetterSetter(): void
    {
        $licenseFactory1 = $this->createStub(LicenseFactory::class);
        $licenseFactory2 = $this->createStub(LicenseFactory::class);

        $factory = new ComponentFactory($licenseFactory1);
        self::assertSame($licenseFactory1, $factory->getLicenseFactory());

        $factory->setLicenseFactory($licenseFactory2);
        self::assertSame($licenseFactory2, $factory->getLicenseFactory());
    }

    public function testMakeFromPackageThrowsOnEmptyName(): void
    {
        $licenseFactory = $this->createStub(LicenseFactory::class);
        $factory = new ComponentFactory($licenseFactory);
        $package = $this->createConfiguredMock(
            PackageInterface::class,
            [
                'getPrettyName' => '',
            ]
        );

        $this->expectException(\UnexpectedValueException::class);
        $this->expectErrorMessageMatches('/package without name/i');

        $factory->makeFromPackage($package);
    }

    public function testMakeFromPackageThrowsOnEmptyVersion(): void
    {
        $licenseFactory = $this->createStub(LicenseFactory::class);
        $factory = new ComponentFactory($licenseFactory);
        $package = $this->createConfiguredMock(
            PackageInterface::class,
            [
                'getPrettyName' => 'vendor/name',
                'getPrettyVersion' => '',
            ]
        );

        $this->expectException(\UnexpectedValueException::class);
        $this->expectErrorMessageMatches('/package without version/i');

        $factory->makeFromPackage($package);
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
        ?LicenseFactory $licenseFactory = null
    ): void {
        $factory = new ComponentFactory($licenseFactory ?? $this->createStub(LicenseFactory::class));

        $got = $factory->makeFromPackage($package);

        self::assertEquals($expected, $got);
    }

    public function dpMakeFromPackage(): \Generator
    {
        yield 'minimal package' => [
            $this->createConfiguredMock(
                PackageInterface::class,
                [
                    'getPrettyName' => 'some-package',
                    'getPrettyVersion' => 'v1.2.3',
                ],
            ),
            (new Component('library', 'some-package', '1.2.3'))
                ->setPackageUrl((new PackageUrl('composer', 'some-package'))->setVersion('1.2.3')),
            null,
        ];

        $completePackage = $this->createConfiguredMock(
            CompletePackageInterface::class,
            [
                'getPrettyName' => 'my/package',
                'getPrettyVersion' => 'dev-master',
                'isDev' => true,
                'getDescription' => 'my description',
                'getLicense' => ['MIT'],
                'getDistSha1Checksum' => '1234567890',
            ]
        );
        $license = $this->createStub(DisjunctiveLicenseRepository::class);
        $licenseFactory = $this->createMock(LicenseFactory::class);
        $licenseFactory->expects(self::once())->method('makeFromPackage')
            ->with($completePackage)
            ->willReturn($license);
        yield 'complete package' => [
            $completePackage,
            (new Component('library', 'package', 'dev-master'))
                ->setGroup('my')
                ->setPackageUrl(
                    (new PackageUrl('composer', 'package'))
                        ->setNamespace('my')
                        ->setVersion('dev-master')
                        ->setChecksums(['sha1:1234567890'])
                )
                ->setDescription('my description')
                ->setLicense($license)
                ->setHashRepository(new HashRepository([HashAlgorithm::SHA_1 => '1234567890'])),
            $licenseFactory,
        ];
    }

    /**
     * @dataProvider dpMakeFromPackages
     *
     * @param PackageInterface[] $packages
     *
     * @uses         \CycloneDX\Core\Repositories\ComponentRepository
     * @uses         \CycloneDX\Core\Enums\HashAlgorithm::isValidValue
     * @uses         \CycloneDX\Core\Repositories\HashRepository
     * @uses         \CycloneDX\Core\Enums\Classification::isValidValue
     */
    public function testMakeFromPackages(
        array $packages,
        ?ComponentRepository $expected,
        ?LicenseFactory $licenseFactory
    ): void {
        $factory = new ComponentFactory($licenseFactory ?? $this->createStub(LicenseFactory::class));

        $got = $factory->makeFromPackages($packages);

        self::assertEquals($expected, $got);
    }

    public function dpMakeFromPackages(): \Generator
    {
        yield 'empty' => [[], null, null];

        $dpMakeFromPackage = $this->dpMakeFromPackage();
        [$package, $expected, $licenseFactory] = $this->dpMakeFromPackage()->current();
        yield $dpMakeFromPackage->key() => [
            [$package],
            new ComponentRepository($expected),
            $licenseFactory,
        ];
    }
}
