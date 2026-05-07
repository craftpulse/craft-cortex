<?php

namespace craftpulse\cortex\events;

use craftpulse\cortex\resources\ResourceInterface;
use craftpulse\cortex\resources\ResourceTemplateInterface;
use yii\base\Event;

/**
 * =========================================================================
 * Fired by `services/Resources` after building the bundled resource
 * registry.
 *
 * Third-party plugins listen to this event and append either a concrete
 * `ResourceInterface` instance (one per addressable URI) or a
 * `ResourceTemplateInterface` instance (one per URI family — the
 * dispatcher resolves concrete URIs against templates at read time).
 * The registry accepts both shapes through this single event so plugin
 * authors aren't forced to listen to two events to surface a mixed
 * resource bundle.
 *
 * Each `ResourceInterface` is keyed by its URI; duplicate URIs surface a
 * `Craft::warning()` line on the `cortex` channel and the second
 * registration is dropped (first registration wins). Templates don't
 * shadow concrete URIs — concrete-URI lookups always hit `getByUri()`
 * first and only fall back to template matching on miss.
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
 *         $event->resources[] = new MyPlugin\Resources\EntryByIdTemplate();
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
     * Concrete resources and URI templates the registry should pick up.
     * Listeners append instances of either `ResourceInterface` (one per
     * concrete URI) or `ResourceTemplateInterface` (one per URI family).
     * The registry routes each by `instanceof` at boot.
     *
     * @var array<int, ResourceInterface|ResourceTemplateInterface>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public array $resources = [];
}
