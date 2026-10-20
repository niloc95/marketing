<?php
/**
 * Every location of a business with branches: its own address and each branch,
 * as one list of cards.
 *
 * Not drawn in the left column any more, although the key stays in each
 * vertical's 'order': show.php skips it there and renders this in the right
 * column instead, under the Contact panel, where the primary's map and hours
 * already were. Branches used to sit at the foot of the left column, after
 * the reviews, and visitors missed them.
 *
 * Same adapter shape as _panel_team.php: _location_panel.php remains the one
 * definition, and this names it for the vertical. The vertical's heading is
 * written for branches alone ("Other rooms & practices"); with the primary in
 * the list too it drops the "Other".
 *
 * $hoursHeading is threaded through as well so a branch's hours are called what
 * the listing's own hours are called; without it a practice read "Consulting
 * hours" for itself and "Trading hours" for its second set of rooms.
 *
 * @var array $l
 * @var array $v
 */
if (empty($l['locations'])) {
    return;
}
?>
<?= view('directory/_location_panel', [
    'locations'      => $l['locations'],
    'primary'        => $l,
    'sectionHeading' => ucfirst((string) preg_replace('/^Other\s+/i', '', $v['headings']['locations'])),
    'hoursHeading'   => $v['headings']['hours'],
], ['saveData' => false]) ?>
