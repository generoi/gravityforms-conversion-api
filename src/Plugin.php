<?php

namespace GeneroWP\GformConversionApi;

use GFAddOn;
use GFForms;

class Plugin
{
    protected static Plugin $instance;

    public static function getInstance(): self
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct()
    {
        add_action('gform_loaded', [$this, 'loadAddon']);
    }

    public function loadAddon(): void
    {
        GFForms::include_feed_addon_framework();
        GFAddOn::register(GformAddOn::class);
    }
}
