<?php

declare(strict_types=1);

use Contao\EasyCodingStandard\Set\SetList;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
    ->withSets([SetList::CONTAO])
    ->withPaths([__DIR__.'/src', __DIR__.'/Resources/contao', __DIR__.'/tests', __DIR__.'/scripts'])
    ->withRootFiles()
;
