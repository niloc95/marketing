<?php
/**
 * The branches panel, in the order this vertical asks for.
 *
 * Same adapter shape as _panel_team.php: _location_panel.php remains the one
 * definition, and this names it for the vertical — "Other rooms & practices",
 * "Other workshops", "Other campuses". See _panel_description.php.
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
    'sectionHeading' => $v['headings']['locations'],
    'hoursHeading'   => $v['headings']['hours'],
], ['saveData' => false]) ?>
