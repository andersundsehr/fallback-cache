<?php

declare(strict_types=1);

use PLUS\GrumPHPConfig\RectorSettings;
use PLUS\GrumPHPConfig\VersionUtility;
use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessParamTagRector;
use Rector\Php71\Rector\FuncCall\RemoveExtraParametersRector;
use Rector\Privatization\Rector\ClassMethod\PrivatizeFinalClassMethodRector;
use Rector\Privatization\Rector\Property\PrivatizeFinalClassPropertyRector;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;
use Rector\TypeDeclaration\Rector\ClassMethod\ParamTypeByParentCallTypeRector;
use Ssch\TYPO3Rector\CodeQuality\General\GeneralUtilityMakeInstanceToConstructorPropertyRector;
use Ssch\TYPO3Rector\Set\Typo3LevelSetList;
use Ssch\TYPO3Rector\Set\Typo3SetList;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->parallel();
    $rectorConfig->importNames();
    $rectorConfig->importShortClasses();
    $rectorConfig->cacheClass(FileCacheStorage::class);
    $rectorConfig->cacheDirectory('./var/cache/rector');

    $paths = array_filter(
        explode("\n", (string)shell_exec("git ls-files | xargs ls -d 2>/dev/null | grep -E '\.(php|html|typoscript)$'")),
        static function ($path): bool {
            if (!$path) {
                return false;
            }

            return !str_starts_with($path, 'Tests/');
        }
    );

    $rectorConfig->paths(
        $paths
    );

    $phpVersion = VersionUtility::getMinimalPhpVersion() ?? PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    [$phpMajor, $phpMinor] = explode('.', $phpVersion, 3);
    $phpSet = constant(LevelSetList::class . '::UP_TO_PHP_' . $phpMajor . $phpMinor);
    $typo3Sets = [];
    $minimalTypo3Version = VersionUtility::getMinimalTypo3Version();
    if ($minimalTypo3Version !== null) {
        [$typo3Major] = explode('.', $minimalTypo3Version, 2);
        $typo3LevelSet = match ($typo3Major) {
            '10' => Typo3LevelSetList::UP_TO_TYPO3_10,
            '11' => Typo3LevelSetList::UP_TO_TYPO3_11,
            '12' => Typo3LevelSetList::UP_TO_TYPO3_12,
            '13' => Typo3LevelSetList::UP_TO_TYPO3_13,
            '14', 'dev-main' => Typo3LevelSetList::UP_TO_TYPO3_14,
            default => null,
        };

        if ($typo3LevelSet !== null) {
            $typo3Sets = [
                $typo3LevelSet,
                Typo3SetList::CODE_QUALITY,
                Typo3SetList::GENERAL,
            ];
        }
    }

    /** @var list<string> $sets */
    $sets =
        [
            SetList::CODE_QUALITY,
            SetList::CODING_STYLE,
            SetList::DEAD_CODE,
            SetList::PRIVATIZATION,
            SetList::TYPE_DECLARATION,
            SetList::EARLY_RETURN,
            SetList::INSTANCEOF,
            $phpSet,
            ...$typo3Sets,
        ];

    // define sets of rules
    $rectorConfig->sets($sets);

    // ignore some files
    $rectorConfig->skip(
        [
            ...RectorSettings::skip(),
            PrivatizeFinalClassPropertyRector::class,
            PrivatizeFinalClassMethodRector::class,
            RemoveExtraParametersRector::class,
            RemoveUnusedPrivateMethodRector::class,
            GeneralUtilityMakeInstanceToConstructorPropertyRector::class,
            ParamTypeByParentCallTypeRector::class => [
                __DIR__ . '/Classes/Cache/CacheManager.php'
            ],
            RemoveUselessParamTagRector::class => [
                __DIR__ . '/Classes/Cache/CacheManager.php'
            ],
        ]
    );
};
