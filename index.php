<?php

/**
 * POSSE for Kirby Plugin
 *
 * Publish (on your) Own Site, Syndicate Elsewhere
 * Syndicate content to Mastodon, Bluesky and other services
 *
 * @package   POSSE for Kirby
 * @author    Philipp Defner
 * @link      https://github.com/dewey/kirby-posse
 * @copyright Philipp Defner
 * @license   MIT
 * @version   1.0.2
 */

// Include the Composer autoloader if available
@include_once __DIR__ . '/vendor/autoload.php';

// Include the plugin bootstrap file for class loading
require_once __DIR__ . '/src/bootstrap.php';

// Register the plugin
Kirby::plugin('notmyhostname/posse', [
    'version'      => '1.0.2',
    'areas'        => require_once __DIR__ . '/src/extensions/areas.php',
    'hooks'        => require_once __DIR__ . '/src/extensions/hooks.php',
    'routes'       => require_once __DIR__ . '/src/extensions/routes.php',
    'options'      => require_once __DIR__ . '/src/extensions/options.php',
    'api'          => require_once __DIR__ . '/src/extensions/api.php',
    'snippets'     => [
        'posse/default' => __DIR__ . '/snippets/posse/default.php'
    ]
]);