<?php

namespace craftpulse\cortex\events;

use craftpulse\cortex\tools\ToolInterface;
use yii\base\Event;

/**
 * =========================================================================
 * Fired by `services/Tools` after building the bundled tool registry.
 *
 * Third-party plugins listen to this event and append their own
 * `ToolInterface` instances to `$tools` to extend the cortex tool
 * surface. Listener responsibility: every appended tool must implement
 * `ToolInterface`, declare an MCP-unique `getName()`, and respect the
 * security guidance in cortex's third-party tool guidelines (PII gates,
 * destructive annotations, permission checks for mutating tools).
 *
 * Example:
 *
 * ```php
 * use craftpulse\cortex\events\RegisterToolsEvent;
 * use craftpulse\cortex\services\Tools;
 * use yii\base\Event;
 *
 * Event::on(
 *     Tools::class,
 *     Tools::EVENT_REGISTER_TOOLS,
 *     function (RegisterToolsEvent $event): void {
 *         $event->tools[] = new MyPlugin\Tools\MyCustomTool();
 *     },
 * );
 * ```
 *
 * Cortex does not enforce behavioural correctness on third-party tools —
 * the same trust model Craft itself uses for plugins.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class RegisterToolsEvent extends Event
{
    /**
     * @var ToolInterface[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public array $tools = [];
}
