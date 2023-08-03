<?php
/*
Plugin Name:        Gravityforms Conversion API
Plugin URI:         http://genero.fi
Description:        A conversion api integration for gravityforms
Version:            1.0.0
Author:             Genero
Author URI:         http://genero.fi/
License:            MIT License
License URI:        http://opensource.org/licenses/MIT
*/

use GeneroWP\GformConversionApi\Plugin;

if (!defined('ABSPATH')) {
    exit;
}

if (file_exists($composer = __DIR__ . '/vendor/autoload.php')) {
    require_once $composer;
}

Plugin::getInstance();
