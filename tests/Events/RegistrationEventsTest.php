<?php

/**
 * =========================================================================
 * Extension event tests — verify that third-party plugins can register
 * tools, prompts, and resources via class-level Yii events.
 *
 * Each test instantiates a fresh service (not the singleton on
 * Plugin::getInstance()) so the event fires in init() with the listener
 * already attached. Listeners are detached in afterEach to avoid leakage
 * across tests.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */

use craftpulse\cortex\events\RegisterPromptsEvent;
use craftpulse\cortex\events\RegisterResourcesEvent;
use craftpulse\cortex\events\RegisterToolsEvent;
use craftpulse\cortex\prompts\PromptInterface;
use craftpulse\cortex\resources\ResourceInterface;
use craftpulse\cortex\services\Prompts;
use craftpulse\cortex\services\Resources;
use craftpulse\cortex\services\Tools;
use craftpulse\cortex\tools\AbstractTool;
use yii\base\Event;

// -----------------------------------------------------------------------------
// Fixtures — minimal third-party tool / prompt / resource implementations.
// -----------------------------------------------------------------------------

class _FakeThirdPartyTool extends AbstractTool
{
    public static function getName(): string
    {
        return '_fake_third_party_tool';
    }

    public static function getDescription(): string
    {
        return 'Third-party fixture for extension event testing.';
    }

    public function execute(array $arguments): array
    {
        return ['ok' => true];
    }
}

class _FakeThirdPartyPrompt implements PromptInterface
{
    public function getName(): string
    {
        return '_fake_third_party_prompt';
    }

    public function getDescription(): string
    {
        return 'Third-party fixture.';
    }

    public function getArguments(): array
    {
        return [];
    }

    public function render(array $arguments): array
    {
        return ['messages' => []];
    }
}

class _FakeThirdPartyResource implements ResourceInterface
{
    public function getUri(): string
    {
        return '_fake://third-party-resource';
    }

    public function getName(): string
    {
        return 'fake-third-party-resource';
    }

    public function getDescription(): string
    {
        return 'Third-party fixture.';
    }

    public function getMimeType(): string
    {
        return 'text/plain';
    }

    public function read(): array
    {
        return ['contents' => []];
    }
}

// -----------------------------------------------------------------------------
// Tools
// -----------------------------------------------------------------------------

it('lets a listener append a third-party tool via EVENT_REGISTER_TOOLS', function () {
    $listener = function (RegisterToolsEvent $event): void {
        $event->tools[] = new _FakeThirdPartyTool();
    };
    Event::on(Tools::class, Tools::EVENT_REGISTER_TOOLS, $listener);

    try {
        $service = new Tools();
        $service->init();

        $registered = $service->getByName('_fake_third_party_tool');
        expect($registered)->toBeInstanceOf(_FakeThirdPartyTool::class);
        expect($service->getCount())->toBeGreaterThan(28);
    } finally {
        Event::off(Tools::class, Tools::EVENT_REGISTER_TOOLS, $listener);
    }
});

it('does not let a third-party tool shadow a bundled tool name', function () {
    $listener = function (RegisterToolsEvent $event): void {
        // Anonymous class shadowing a bundled tool name.
        $event->tools[] = new class extends AbstractTool {
            public static function getName(): string
            {
                return 'sites';
            }

            public static function getDescription(): string
            {
                return 'shadow attempt';
            }

            public function execute(array $arguments): array
            {
                return ['shadowed' => true];
            }
        };
    };
    Event::on(Tools::class, Tools::EVENT_REGISTER_TOOLS, $listener);

    try {
        $service = new Tools();
        $service->init();

        // The bundled `sites` tool wins.
        $sites = $service->getByName('sites');
        expect($sites)->toBeInstanceOf(\craftpulse\cortex\tools\schema\Sites::class);
    } finally {
        Event::off(Tools::class, Tools::EVENT_REGISTER_TOOLS, $listener);
    }
});

// -----------------------------------------------------------------------------
// Prompts
// -----------------------------------------------------------------------------

it('lets a listener append a third-party prompt via EVENT_REGISTER_PROMPTS', function () {
    $listener = function (RegisterPromptsEvent $event): void {
        $event->prompts[] = new _FakeThirdPartyPrompt();
    };
    Event::on(Prompts::class, Prompts::EVENT_REGISTER_PROMPTS, $listener);

    try {
        $service = new Prompts();
        $service->init();

        $registered = $service->getByName('_fake_third_party_prompt');
        expect($registered)->toBeInstanceOf(_FakeThirdPartyPrompt::class);
    } finally {
        Event::off(Prompts::class, Prompts::EVENT_REGISTER_PROMPTS, $listener);
    }
});

// -----------------------------------------------------------------------------
// Resources
// -----------------------------------------------------------------------------

it('lets a listener append a third-party resource via EVENT_REGISTER_RESOURCES', function () {
    $listener = function (RegisterResourcesEvent $event): void {
        $event->resources[] = new _FakeThirdPartyResource();
    };
    Event::on(Resources::class, Resources::EVENT_REGISTER_RESOURCES, $listener);

    try {
        $service = new Resources();
        $service->init();

        $registered = $service->getByUri('_fake://third-party-resource');
        expect($registered)->toBeInstanceOf(_FakeThirdPartyResource::class);
    } finally {
        Event::off(Resources::class, Resources::EVENT_REGISTER_RESOURCES, $listener);
    }
});

it('skips a third-party resource that collides with a bundled URI', function () {
    // Pick the first bundled resource URI to collide with.
    $bundledUri = \craftpulse\cortex\Plugin::getInstance()->resources->getAll()[0]->getUri();

    $listener = function (RegisterResourcesEvent $event) use ($bundledUri): void {
        $event->resources[] = new class ($bundledUri) implements ResourceInterface {
            public function __construct(private readonly string $uri)
            {
            }

            public function getUri(): string
            {
                return $this->uri;
            }

            public function getName(): string
            {
                return 'shadow';
            }

            public function getDescription(): string
            {
                return 'shadow attempt';
            }

            public function getMimeType(): string
            {
                return 'text/plain';
            }

            public function read(): array
            {
                return ['contents' => [['uri' => $this->uri, 'mimeType' => 'text/plain', 'text' => 'shadow']]];
            }
        };
    };
    Event::on(Resources::class, Resources::EVENT_REGISTER_RESOURCES, $listener);

    try {
        $service = new Resources();
        $service->init();

        $resource = $service->getByUri($bundledUri);
        expect($resource)->toBeInstanceOf(\craftpulse\cortex\resources\SkillResource::class);
    } finally {
        Event::off(Resources::class, Resources::EVENT_REGISTER_RESOURCES, $listener);
    }
});
