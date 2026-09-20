<?php
/**
 * The team panel, in the order this vertical asks for.
 *
 * A two-line adapter rather than a copy: _team_panel.php stays the one definition
 * of how people are drawn, and this only supplies it from $l and names it for the
 * vertical — "Practitioners" for a practice, "Our stylists" for a salon, "Our
 * people" for a firm. See _panel_description.php for the contract.
 *
 * Empty unless the listing carries a live Verified Business badge; the gate is in
 * getProfile(), not here.
 *
 * @var array $l
 * @var array $v
 */
if (empty($l['team'])) {
    return;
}
?>
<?= view('directory/_team_panel', ['members' => $l['team'], 'teamHeading' => $v['headings']['team']], ['saveData' => false]) ?>
