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
            (<code>layouts/admin.php</code>) and <code>/business</code> (<code>layouts/business.php</code>), with page swaps instead of reloads.</li>
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

    <h3>App shell pages (/admin and /business)</h3>
    <ul>
        <li><strong>Width:</strong> every page fills the space beside the sidebar, like the calendar. <code>layouts/admin.php</code> and <code>layouts/business.php</code> set the padding (<code>w-full px-4 py-6 lg:px-8</code>); views never wrap themselves in <code>.section</code>, <code>.container</code> or a <code>max-w-*</code>. A single action form stays <code>.form-card-narrow</code>.</li>
        <li><strong>Header:</strong> the top bar is the page's header. Its <code>h1</code> is the page title: the view's <code>shell_title</code> section (it may carry a <code>.page-title-count</code>), or the sidebar item's name. The page's buttons go in <code>shell_actions</code>. Views draw no h1 of their own, except the bare sign in pages. <code>spa.js</code> swaps the head with the page.</li>
        <li><strong>Tabs:</strong> <code>.page-tabs</code> / <code>.page-tab.is-active</code>, docked under the top bar (<code>--shell-top</code>, set by <code>resources/shell/tabs.js</code>, which also keeps the active tab in view on a phone).</li>
        <li><strong>Pager:</strong> <code>directory/_pager.php</code> on every list (<code>pager_links()</code> for a CI4 paginate, <code>pager_result()</code> for a list that counts its own rows). In the shell it docks at the foot of the screen in the same place on a short page as a long one, Previous and Next fixed at the two ends.</li>
        <li><strong>Boxes and headings:</strong> one box, <code>.panel</code> (<code>.form-card</code> is the same box in the shell); <code>h2.section-title</code> and <code>h3.subsection-title</code>.</li>
        <li><strong>Buttons:</strong> the main action is <code>.btn-primary</code> (ocean), the rest <code>.btn-ghost</code>, row actions <code>.btn-xs</code>; <code>.btn-accent</code> (orange) only for an upgrade.</li>
        <li><strong>Profile editor</strong> (<code>/business/profile/{section}</code> and <code>/admin/edit/{id}/{tab}</code>): every section is on the page as a pane with its own form; the tabs (<code>layouts/_profile_tabs.php</code>), the owner's sidebar items and the strength steps switch panes in place without a page load (<code>directory.js</code>, "profile panes": address bar, sidebar highlight, top bar title and Save follow; Back and Forward work). Each pane's form where every part is a heading over a hairline (no grey box in a white one), and from 1280px a sticky right column with Save and profile strength (<code>.profile-editor</code>). Narrower, the column follows the form and Save is in <code>.form-savebar</code> at the foot of the screen.</li>
        <li><code>ShellStyleStandardTest</code> reads the views for all of this.</li>
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
