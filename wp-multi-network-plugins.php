<?php

declare(strict_types=1);

/**
 * MU WordPress.
 * @wordpress-muplugin
 * Plugin Name: WP Multi Network Plugins
 * Description: Plugin lists for global multisite networks.
 * Version: 0.1.1
 * Author: Austin Passy
 * Author URI: https://github.com/thefrosty
 */

use Symfony\Component\HttpFoundation\Request;
use TheFrosty\WpMultiNetworkPlugins\Plugins;

add_action('muplugins_loaded', static function (): void {
    if (is_blog_admin() || is_network_admin()) {
        (new Plugins(Request::createFromGlobals()))->addHooks();
    }
}, 20);
