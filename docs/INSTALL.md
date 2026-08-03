# Installation & Setup

You can install Herald via the plugin store, or through Composer.

## Craft Plugin Store

To install **Herald**, navigate to the _Plugin Store_ section of your Craft control panel, search for `Herald`, and click the _Try_ button.

## Composer

You can also add the package to your project using Composer and the command line.

1. Open your terminal and go to your Craft project:

```shell
cd /path/to/project
```

2. Tell Composer to require the plugin, then Craft to install it:

```shell
composer require craftpulse/craft-herald
php craft plugin/install herald
```

## DDEV

If your project runs in DDEV, run the same commands through DDEV from the project root:

```shell
ddev composer require craftpulse/craft-herald
ddev craft plugin/install herald
```

## Next steps

Herald serves nothing until an MCP client is pointed at it. Run `php craft herald/install` for a copy-paste snippet per client, then see [Connecting a client](CONNECTING.md).

## Licensing

You can try Herald in a development environment for as long as you like. Once your site goes live, you are required to purchase a license for the plugin.

For more information, see [Craft's Commercial Plugin Licensing](https://craftcms.com/docs/5.x/extend/plugin-store.html#commercial-plugins).
