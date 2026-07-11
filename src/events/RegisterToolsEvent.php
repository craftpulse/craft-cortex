<?php

namespace craftpulse\herald\events;

use craftpulse\herald\tools\ToolInterface;
use yii\base\Event;

/**
 * =========================================================================
 * Fired by `services/Tools` after building the bundled tool registry.
 *
 * Third-party plugins listen to this event and append their own
 * `ToolInterface` instances to `$tools` to extend the herald tool
 * surface. Listener responsibility: every appended tool must implement
 * `ToolInterface`, declare an MCP-unique `getName()`, and respect the
 * security guidance in herald's third-party tool guidelines (PII gates,
 * destructive annotations, permission checks for mutating tools).
 *
 * Example:
 *
 * ```php
 * use craftpulse\herald\events\RegisterToolsEvent;
 * use craftpulse\herald\services\Tools;
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
 * Herald does not enforce behavioural correctness on third-party tools —
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
