<?php

namespace craftpulse\herald\events;

use craftpulse\herald\prompts\PromptInterface;
use yii\base\Event;

/**
 * =========================================================================
 * Fired by `services/Prompts` after building the bundled prompt registry.
 *
 * Third-party plugins listen to this event and append their own
 * `PromptInterface` instances to `$prompts` to extend the herald prompt
 * surface — typically to ship plugin-specific MCP prompts that pair
 * with their tools and resources.
 *
 * Naming convention: bundled prompts use the `craftcms_*` prefix; the
 * Pro custom-skills feature reserves `custom_*`. Third-party plugins
 * SHOULD use a plugin-specific prefix (e.g. `seo_`, `commerce_`) to
 * avoid collisions.
 *
 * Example:
 *
 * ```php
 * use craftpulse\herald\events\RegisterPromptsEvent;
 * use craftpulse\herald\services\Prompts;
 * use yii\base\Event;
 *
 * Event::on(
 *     Prompts::class,
 *     Prompts::EVENT_REGISTER_PROMPTS,
 *     function (RegisterPromptsEvent $event): void {
 *         $event->prompts[] = new MyPlugin\Prompts\OptimizationGuide();
 *     },
 * );
 * ```
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
class RegisterPromptsEvent extends Event
{
    /**
     * @var PromptInterface[]
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public array $prompts = [];
}
