<?php
/**
 * The business's own description, on its public profile.
 *
 * One of the eight panels show.php renders by walking Config\Verticals' 'order',
 * so every one of them takes the same two variables and decides for itself
 * whether it has anything to draw. That is what lets a vertical reorder the page
 * from a config file rather than from an if-chain in the view.
 *
 * @var array $l the listing, from DirectoryService::getProfile()
 * @var array $v vertical_profile() — headings, nouns, CTA and order
 */
if (empty($l['description'])) {
    return;
}
?>
<div class="panel mb-5">
    <h3><?= esc($v['headings']['description']) ?></h3>
    <?php /* The only unescaped listing content on the site. listing_rich_text()
             runs it through RichText::sanitise() first — do not swap this for a
             bare echo, and do not add esc() (it would print the tags). */ ?>
    <div class="listing-prose text-sm text-slate-700 dark:text-slate-300"><?= listing_rich_text($l['description']) ?></div>
</div>
