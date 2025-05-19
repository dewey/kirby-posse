<?php

/**
 * Bootstrap file for POSSE plugin
 * Registers all plugin classes for autoloading
 */

$dir = __DIR__ . '/models';
load([
    // Core Models
    'Notmyhostname\\Posse\\Models\\Config'     => $dir . '/Config.php',
    'Notmyhostname\\Posse\\Models\\Database'   => $dir . '/Database.php',
    'Notmyhostname\\Posse\\Models\\Posse'      => $dir . '/Posse.php',
    
    // Service Interfaces and Abstract Classes
    'Notmyhostname\\Posse\\Models\\Services\\ServiceInterface' => $dir . '/Services/ServiceInterface.php',
    'Notmyhostname\\Posse\\Models\\Services\\AbstractService'  => $dir . '/Services/AbstractService.php',
    
    // Service Implementations
    'Notmyhostname\\Posse\\Models\\Services\\MastodonService' => $dir . '/Services/MastodonService.php',
    'Notmyhostname\\Posse\\Models\\Services\\BlueskyService'  => $dir . '/Services/BlueskyService.php',
]);