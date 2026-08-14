<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Honeypot extends BaseConfig
{
    /**
     * Makes Honeypot visible or not to human
     */
    public bool $hidden = true;

    /**
     * Honeypot Label Content
     */
    public string $label = 'Fill This Field';

    /**
     * Honeypot Field Name
     */
    public string $name = 'honeypot';

    /**
     * Honeypot HTML Template
     */
    public string $template = '<label>{label}</label><input type="text" name="{name}" value="">';

    /**
     * Honeypot container
     *
     * No inline style, because CSP is enabled (App::$CSPEnabled) and enforcing.
     * Honeypot::attachHoneypot() sees that, rewrites this into
     * `<div id="hpc">` and injects a nonced `#hpc { display:none }` into the
     * head — but it only ever *adds* the id, it never strips an inline style,
     * so leaving the framework default here produced a style-src-attr
     * violation on every form on the site.
     *
     * This must keep both the `>` and the `{template}` placeholder: the id
     * rewrite matches on the literal `>{template}`, and an empty container or
     * one missing the placeholder is silently replaced with the inline-style
     * default again.
     *
     * If CSP is ever turned off, restore `style="display:none"` — without it
     * and without the injected rule, the field is visible to humans.
     */
    public string $container = '<div>{template}</div>';

    /**
     * The id attribute for Honeypot container tag
     *
     * Used when CSP is enabled.
     */
    public string $containerId = 'hpc';
}
