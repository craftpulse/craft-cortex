<?php

use craft\ecs\SetList;
use Symplify\EasyCodingStandard\Config\ECSConfig;

// ECS runs from inside the playground via:
//   ddev exec --dir=/var/www/html/cms vendor/bin/ecs check \
//     --config=vendor/craftpulse/craft-cortex/ecs.php
//
// craftcms/ecs:dev-main pins symplify/easy-coding-standard to ^10.3.3,
// so this config uses the v10 closure API, not the v11+ chainable
// builder. craftcms/ecs hasn't shipped a CRAFT_CMS_5 set yet —
// CRAFT_CMS_4 is what first-party Craft 5 plugins use too.

return static function (ECSConfig $ecsConfig): void {
    $ecsConfig->paths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ]);

    $ecsConfig->sets([SetList::CRAFT_CMS_4]);
};
