<?php

use craft\ecs\SetList;
use Symplify\EasyCodingStandard\Config\ECSConfig;

// ECS runs from inside the playground via:
//   ddev exec --dir=/var/www/html/cms vendor/bin/ecs check \
//     --config=vendor/craftpulse/craft-cortex/ecs.php
//
// craftcms/ecs hasn't shipped a CRAFT_CMS_5 set yet — CRAFT_CMS_4 is
// what first-party Craft 5 plugins use too.

return ECSConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSets([SetList::CRAFT_CMS_4]);
