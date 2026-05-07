<?php

namespace craftpulse\cortex\events;

use craftpulse\cortex\resources\ResourceInterface;
use yii\base\Event;

/**
 * =========================================================================
 * Fired by `services/Resources` after building the bundled resource
 * registry.
 *
 * Third-party plugins listen to this event and append their own
 * `ResourceInterface` instances to `$resources`. Each resource is
 * keyed by its URI; listeners must ensure URI uniqueness across the
 * registry — duplicate URIs are skipped (last write wins is brittle;
 * fail-loud is preferred but the registry is too late in the boot
 * cycle to throw, so we silently keep the first registration).
 *
 * URI scheme convention: bundled resources use `craft-skills://`; the
 * Pro custom-skills feature reserves `custom-skills://`. Third-party
 * plugins SHOULD use a plugin-specific scheme (e.g. `seo://`,
 * `commerce-docs://`) to avoid collisions.
 *
 * Example:
 *
 * ```php
 * use craftpulse\cortex\events\RegisterResourcesEvent;
 * use craftpulse\cortex\services\Resources;
 * use yii\base\Event;
 *
 * Event::on(
 *     Resources::class,
 *     Resources::EVENT_REGISTER_RESOURCES,
 *     function (RegisterResourcesEvent $event): void {
 *         $event->resources[] = new MyPlugin\Resources\BestPractices();
 *     },
 * );
 * ```
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
class RegisterResourcesEvent extends Event
{
    /**
     * @var ResourceInterface[]
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public array $resources = [];
}
