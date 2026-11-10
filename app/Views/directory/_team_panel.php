<?php

use App\Services\TeamMemberService;

/**
 * The people inside a business, on its public profile.
 *
 * Only ever reached with rows to show: DirectoryService::getProfile() hands
 * back an empty array unless the listing has a live Verified Business badge, so
 * the gate is not repeated here. _panel_team.php is the caller, and is where
 * the heading comes from.
 *
 * Everything is escaped. Unlike the business description there is deliberately
 * no rich text on this page — see the migration for why bios are plain.
 *
 * $teamHeading rather than $heading for the reason _location_panel.php spells
 * out: CI4 keeps view data between render() calls, and 'heading' is already
 * spoken for by the contact, map and hours panels, so an optional $heading here
 * would inherit one of theirs instead of falling back to its own default.
 *
 * @var array  $members     rows from DirectoryListingTeamModel::forProfile()
 * @var array  $practitioners bookable people (PublicBookingService::practitioners()):
 *                          joined to their team card by team_member_id, left
 *                          out when that card is hidden, and shown as a plain
 *                          card when they have none
 * @var string $teamHeading panel heading; the vertical's word for these people
 * @var string $business    the business's name, for the headshots' alt text
 * @var array|null $regulator the listing category's regulator, for each person's registration
 */
$teamHeading = $teamHeading ?? 'Our team';
$regulator   = $regulator ?? null;
$bookable    = [];
$cardless    = [];
$cardIds     = array_map('intval', array_column($members, 'id'));
foreach ($practitioners ?? [] as $p) {
    if ($p['team_member_id'] === null) {
        $cardless[] = $p;
    } elseif (in_array($p['team_member_id'], $cardIds, true)) {
        $bookable[$p['team_member_id']] = $p;
    }
    // A card that is not in $members is hidden from the profile (T31):
    // bookable on the booking page, not drawn here.
}
if ($members === [] && $cardless === []) {
    return;
}
$link        = 'font-medium text-primary-500 dark:text-primary-300 hover:underline';
?>
<div class="panel mb-5">
    <h3><?= esc($teamHeading) ?></h3>
    <div class="team-list">
        <?php foreach ($members as $m): ?>
            <?php // The anchor the typeahead links to and the Person @id in the
                  // JSON-LD points at; see team_member_anchor(). ?>
            <div class="team-member"<?php if (($anchor = team_member_anchor($m)) !== ''): ?> id="<?= esc($anchor, 'attr') ?>"<?php endif; ?>>
                <?php if (! empty($m['photo_path'])): ?>
                    <img src="<?= esc(base_url($m['photo_path'])) ?>"
                         alt="<?= esc(trim($m['name'] . (trim((string) ($m['role'] ?? '')) !== '' ? ', ' . trim((string) $m['role']) : '') . (($business ?? '') !== '' ? ' at ' . $business : '')), 'attr') ?>" width="72" height="72"
                         class="team-avatar" loading="lazy">
                <?php else: ?>
                    <?php // An initial rather than a stock silhouette: a placeholder
                          // that is obviously a placeholder beats one that looks like
                          // a photograph of somebody who is not this person. ?>
                    <span class="team-avatar team-avatar-empty" aria-hidden="true"><?= esc(mb_substr(trim($m['name']), 0, 1)) ?></span>
                <?php endif; ?>

                <div class="team-member-body">
                    <p class="team-member-name"><?= esc($m['name']) ?></p>
                    <?php if (! empty($m['role'])): ?>
                        <p class="team-member-role"><?= esc($m['role']) ?></p>
                    <?php endif; ?>
                    <?php if (! empty($m['credentials'])): ?>
                        <p class="team-member-credentials"><?= esc($m['credentials']) ?></p>
                    <?php endif; ?>

                    <?php // Self declared unless an admin ticked it, and always
                          // beside the regulator's own lookup. ?>
                    <?php $reg = \App\Libraries\ProfessionalRegistration::forPerson($m, $regulator); ?>
                    <?php if ($reg !== null): ?>
                        <ul class="team-member-registration" data-registration-line>
                            <?php if ($reg['number'] !== ''): ?>
                                <li>
                                    <abbr title="<?= esc($reg['name'], 'attr') ?>" class="no-underline"><?= esc($reg['short']) ?></abbr> registration
                                    <strong class="text-slate-900 dark:text-white"><?= esc($reg['number']) ?></strong>
                                    <span class="text-xs text-slate-500 dark:text-slate-400">(<?= $reg['checked'] ? 'checked by ' . esc(config('Directory')->siteName()) : 'self declared' ?>)</span>
                                    <span aria-hidden="true">&middot;</span>
                                    <a class="<?= $link ?>" href="<?= esc($reg['register'], 'attr') ?>" target="_blank" rel="noopener nofollow">Check on the <?= esc($reg['short']) ?> register</a>
                                </li>
                            <?php endif; ?>
                            <?php if ($reg['orcid'] !== null || $reg['researchgate'] !== null): ?>
                                <li>
                                    Research profiles:
                                    <?php if ($reg['orcid'] !== null): ?><a class="<?= $link ?>" href="<?= esc($reg['orcid'], 'attr') ?>" target="_blank" rel="noopener nofollow">ORCID</a><?php endif; ?>
                                    <?php if ($reg['orcid'] !== null && $reg['researchgate'] !== null): ?><span aria-hidden="true">&middot;</span><?php endif; ?>
                                    <?php if ($reg['researchgate'] !== null): ?><a class="<?= $link ?>" href="<?= esc($reg['researchgate'], 'attr') ?>" target="_blank" rel="noopener nofollow">ResearchGate</a><?php endif; ?>
                                </li>
                            <?php endif; ?>
                        </ul>
                    <?php endif; ?>

                    <?php $areas = TeamMemberService::splitSpecializations($m['specializations'] ?? null); ?>
                    <?php if ($areas !== []): ?>
                        <div class="taglist mt-2">
                            <?php foreach ($areas as $area): ?><span class="tag"><?= esc($area) ?></span><?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (! empty($m['bio'])): ?>
                        <p class="team-member-bio"><?= esc($m['bio']) ?></p>
                    <?php endif; ?>

                    <?php if (isset($m['id'], $bookable[(int) $m['id']])): ?>
                        <?= view('directory/_team_booking', ['p' => $bookable[(int) $m['id']]], ['saveData' => false]) ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php // Bookable people with no card at all (none since T31 backfilled them): what booking knows of them. ?>
        <?php foreach ($cardless as $p): ?>
            <div class="team-member">
                <span class="team-avatar team-avatar-empty" aria-hidden="true"><?= esc(mb_substr(trim($p['name']), 0, 1)) ?></span>
                <div class="team-member-body">
                    <p class="team-member-name"><?= esc($p['name']) ?></p>
                    <?php if ($p['title'] !== ''): ?>
                        <p class="team-member-role"><?= esc($p['title']) ?></p>
                    <?php endif; ?>
                    <?php if ($p['bio'] !== ''): ?>
                        <p class="team-member-bio"><?= esc($p['bio']) ?></p>
                    <?php endif; ?>
                    <?= view('directory/_team_booking', ['p' => $p], ['saveData' => false]) ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
