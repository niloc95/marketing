<?php
/**
 * Swatches are inline SVG with a fill attribute: CSP forbids style="",
 * and fill is a presentation attribute, not a style.
 *
 * @var ?array<string,array<string,string>> $tokens
 */
?>
<div class="prose-legal">
    <h3>One layout, one stylesheet, one script</h3>
    <ul>
        <li><strong>Layout:</strong> <code>app/Views/layouts/public.php</code> for every public Local page (header, footer, analytics, consent);
            the app shell <code>layouts/_shell.php</code> (sidebar, top bar, <code>#spa-content</code>) for <code>/admin</code>
            (<code>layouts/admin.php</code>) and <code>/bookings</code> (<code>layouts/business.php</code>), with page swaps instead of reloads.</li>
        <li><strong>Stylesheet:</strong> <code>resources/directory.css</code> → Tailwind 3 (<code>tailwind.directory.cjs</code>) →
            <code>public/assets/directory.css</code>, linked with <code>?v=&lt;filemtime&gt;</code>. Components are named classes in
            <code>@layer components</code> (<code>.btn</code>, <code>.panel</code>, <code>.table</code>, <code>.faq-item</code>,
            <code>.chip</code>, <code>.prose-legal</code>, <code>.cal-*</code> for the calendar).</li>
        <li><strong>Script:</strong> <code>public/assets/directory.js</code>, every client behaviour in one file (typeahead, address lookup,
            maps, form steps, repeaters, photo band, lightbox, consent, beacons). Bookings has its own Vite bundle,
            <code>resources/business/*.js</code> → <code>public/assets/business/business.js</code>; the app shell's page swaps
            (<code>resources/shell/</code>) are in it and, for admin, in <code>public/assets/business/shell.js</code>.</li>
        <li><strong>Icons:</strong> Lucide, vendored as SVG in <code>resources/icons/</code>, drawn by <code>lucide()</code>.</li>
        <li><strong>Charts:</strong> inline SVG partials (<code>_bar_chart.php</code>, <code>_stacked_bar_chart.php</code>, <code>_meter_rows.php</code>), no chart library.</li>
    </ul>

    <h3>Rules</h3>
    <ul>
        <li>Content Security Policy is enforcing: no <code>style</code> attributes, no inline <code>&lt;style&gt;</code> without the nonce, no inline handlers. Script sets styles one property at a time through CSSOM.</li>
        <li>Class names are written out in full: a concatenated class is purged from the build and draws nothing.</li>
        <li>Corners: three sizes only. <code>rounded-control</code> (pill) for one line controls, <code>rounded-surface</code> (1.5rem) for cards and panels, <code>rounded-inner</code> (0.75rem) for small things inside a surface.</li>
        <li>Dark mode on every page (<code>.dark</code> on the root, surfaces below). Content pages are centred, with one accordion style (<code>.faq-item</code>) everywhere.</li>
        <li>Type: Inter, then the system stack.</li>
        <li>The tokens are shared: the brand site keeps a copy of <code>tailwind.tokens.cjs</code>, so a token change goes into both repos.</li>
    </ul>

    <h3>Colour tokens (live, from <code>tailwind.tokens.cjs</code>)</h3>
</div>
<?php if ($tokens === null || $tokens === []): ?>
    <p class="text-sm text-slate-500">Not on this server.</p>
<?php else: ?>
    <?php foreach ($tokens as $group => $colours): ?>
        <h4 class="mb-2 mt-4 text-sm font-semibold text-slate-700 dark:text-slate-300"><?= esc($group) ?></h4>
        <ul class="handbook-swatches">
            <?php foreach ($colours as $name => $hex): ?>
                <li>
                    <svg width="40" height="40" viewBox="0 0 40 40" aria-hidden="true"><rect x="0.5" y="0.5" width="39" height="39" rx="9" fill="<?= esc($hex, 'attr') ?>" stroke="currentColor" stroke-opacity="0.15"/></svg>
                    <span><code><?= esc($group . '-' . $name) ?></code><br><?= esc($hex) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endforeach; ?>
<?php endif; ?>
