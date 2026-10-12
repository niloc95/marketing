<?php

namespace Config;

use Kint\Parser\ConstructablePluginInterface;
use Kint\Renderer\Rich\TabPluginInterface;
use Kint\Renderer\Rich\ValuePluginInterface;

/**
 * --------------------------------------------------------------------------
 * Kint
 * --------------------------------------------------------------------------
 *
 * We use Kint's `RichRenderer` and `CLIRenderer`. This area contains options
 * that you can set to customize how Kint works for you.
 *
 * @see https://kint-php.github.io/kint/ for details on these settings.
 */
class Kint
{
    /*
    |--------------------------------------------------------------------------
    | Global Settings
    |--------------------------------------------------------------------------
    */

    /**
     * Kint's own default parser plugins, minus ColorPlugin.
     *
     * ColorPlugin treats any string that is a CSS colour name as a colour and
     * draws a swatch with an inline style="background:…" attribute. The debug
     * toolbar dumps view data through Kint, and the signup and manage forms
     * carry the country list, so "Peru" (the colour #CD853F) and friends drew
     * swatches that our CSP's style-src-attr 'self' blocks: five console
     * errors and five /csp-report posts on every local page load of
     * /add-profile, which hid real violations. Development only; the toolbar
     * never runs in production. Read from Kint::$plugins so a Kint upgrade
     * keeps its new defaults.
     *
     * @var list<class-string<ConstructablePluginInterface>|ConstructablePluginInterface>|null
     */
    public $plugins;

    public function __construct()
    {
        $this->plugins = array_values(array_filter(
            \Kint\Kint::$plugins,
            static fn ($plugin): bool => $plugin !== \Kint\Parser\ColorPlugin::class
        ));
    }

    public int $maxDepth           = 6;
    public bool $displayCalledFrom = true;
    public bool $expanded          = false;

    /*
    |--------------------------------------------------------------------------
    | RichRenderer Settings
    |--------------------------------------------------------------------------
    */
    public string $richTheme = 'aante-light.css';
    public bool $richFolder  = false;

    /**
     * @var array<string, class-string<ValuePluginInterface>>|null
     */
    public $richObjectPlugins;

    /**
     * @var array<string, class-string<TabPluginInterface>>|null
     */
    public $richTabPlugins;

    /*
    |--------------------------------------------------------------------------
    | CLI Settings
    |--------------------------------------------------------------------------
    */
    public bool $cliColors      = true;
    public bool $cliForceUTF8   = false;
    public bool $cliDetectWidth = true;
    public int $cliMinWidth     = 40;
}
