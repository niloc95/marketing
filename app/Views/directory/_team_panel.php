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
 * @var array  $members     rows from DirectoryListingTeamModel::forListing()
 * @var string $teamHeading panel heading; the vertical's word for these people
 */
$teamHeading = $teamHeading ?? 'Our team';
?>
<div class="panel mb-5">
    <h3><?= esc($teamHeading) ?></h3>
    <div class="team-list">
        <?php foreach ($members as $m): ?>
            <div class="team-member">
                <?php if (! empty($m['photo_path'])): ?>
                    <img src="<?= esc(base_url($m['photo_path']), 'attr') ?>"
                         alt="<?= esc($m['name'], 'attr') ?>" width="72" height="72"
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

                    <?php $areas = TeamMemberService::splitSpecializations($m['specializations'] ?? null); ?>
                    <?php if ($areas !== []): ?>
                        <div class="taglist mt-2">
                            <?php foreach ($areas as $area): ?><span class="tag"><?= esc($area) ?></span><?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (! empty($m['bio'])): ?>
                        <p class="team-member-bio"><?= esc($m['bio']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
