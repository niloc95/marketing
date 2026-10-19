<?php
/**
 * The profile header's pieces, rendered in the order $pieces names them.
 *
 * One definition for both headers in show.php: the light one on a profile
 * without photos, and the one laid over the photo band, which splits the same
 * pieces between the overlay and the row under the band.
 *
 * @var array    $l        the public view of the listing
 * @var string[] $pieces   any of seal, category, name, rating, address, pills, hours
 * @var string   $name
 * @var string   $prof
 * @var string   $catSlug
 * @var string   $province
 * @var string   $place
 */
?>
<?php foreach ($pieces as $piece): ?>
    <?php if ($piece === 'seal'): ?>
        <?php // The seal here, not the pill: this is the page a customer is on when
              // they decide whether to call, and it is the one public surface with
              // room for the mark itself. The pill still carries the same claim on
              // search cards, where 14px is all there is.
              //
              // Floated, and first in the column on purpose: a float only clears
              // content that comes after it, so placed lower it sat on its own row
              // and pushed the header taller. Up here the name and address wrap
              // beside it and the header keeps its height.
              //
              // Still a link, and the label still says what was actually checked.
              // "Verified" with no referent is the kind of badge that erodes trust
              // rather than building it, and the page it links to spells it out.
              // The seal stays aria-hidden so the link is named once, by the
              // anchor, rather than twice. ?>
        <?php if (listing_is_verified_business($l)): ?>
            <a class="profile-seal float-right ml-3 block" href="<?= base_url('verified') ?>"
               title="<?= esc(VERIFIED_BADGE_EXPLAINER, 'attr') ?>: the business's registration document and the owner's ID"
               aria-label="Verified Business, <?= esc(VERIFIED_BADGE_EXPLAINER, 'attr') ?>: the business's registration document and the owner's ID"><?= verified_seal('verified-seal w-16 sm:w-20') ?></a>
        <?php endif; ?>
    <?php elseif ($piece === 'category'): ?>
        <?php // Category and province link to their landing pages: this is what
              // stops those pages being orphans and gives them internal authority. ?>
        <?php if ($prof): ?>
            <?php if ($catSlug !== ''): ?>
                <a class="profile-category text-sm font-bold text-brand-orange hover:underline" href="<?= esc(base_url('directory/' . $catSlug)) ?>"><?= esc($prof) ?></a>
            <?php else: ?>
                <span class="profile-category text-sm font-bold text-brand-orange"><?= esc($prof) ?></span>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (($typeBadge = listing_type_badge($l)) !== ''): ?><span class="ml-1 align-middle"><?= $typeBadge ?></span><?php endif; ?>
    <?php elseif ($piece === 'name'): ?>
        <h1 class="mt-1 text-2xl font-extrabold text-slate-900 dark:text-white sm:text-3xl"><?= esc($name) ?></h1>
    <?php elseif ($piece === 'rating'): ?>
        <?php $headRating = rating_summary($l); ?>
        <?php if ($headRating !== ''): ?>
            <a class="profile-rating mt-1 inline-block hover:underline" href="#reviews"><?= $headRating ?></a>
        <?php endif; ?>
    <?php elseif ($piece === 'address'): ?>
        <?php if ($place): ?>
            <?php // items-start, and the address wrapped in one <span>: the address
                  // is inline text with a link spliced into it, and as bare flex
                  // children the comma before the province would become its own
                  // item and take a gap on each side. ?>
            <div class="mt-1 flex items-start gap-1.5 text-sm text-slate-500 dark:text-slate-400">
                <?= lucide('map-pin', 'mt-0.5 h-4 w-4 shrink-0') ?>
                <span>
                <?php if ($catSlug !== '' && $province !== ''): ?>
                    <?= esc(trim(implode(', ', array_filter([$l['suburb'] ?? '', $l['city'] ?? ''])))) ?><?= ($l['suburb'] ?? '') || ($l['city'] ?? '') ? ', ' : '' ?><a class="hover:text-primary-500 dark:hover:text-primary-300 hover:underline" href="<?= esc(base_url('directory/' . $catSlug . '/' . slugify($province))) ?>"><?= esc($province) ?></a>
                <?php else: ?>
                    <?= esc($place) ?>
                <?php endif; ?>
                </span>
            </div>
        <?php endif; ?>
    <?php elseif ($piece === 'pills'): ?>
        <?php // The same pair as the search card, in the same order: Verified
              // (we checked them) before Featured (we promote them). The seal
              // is the visual; this pill is what says it in words. ?>
        <?php $headVerified = listing_is_verified_business($l); ?>
        <?php if ($headVerified || ! empty($l['is_featured'])): ?>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <?php if ($headVerified): ?><?= verified_badge_pill() ?><?php endif; ?>
                <?php if (! empty($l['is_featured'])): ?><span class="badge badge-featured gap-1"><?= lucide('star', 'h-3.5 w-3.5 shrink-0') ?>Featured</span><?php endif; ?>
            </div>
        <?php endif; ?>
    <?php elseif ($piece === 'hours'): ?>
        <?php // Today's hours at a glance; the full week stays in the panel
              // below, and "See hours" jumps to it. Nothing at all when
              // today is blank: see hours_today_summary(). On a public
              // holiday it follows the owner's holiday answer, and without
              // one says hours may differ: see hours_today_line(). ?>
        <?php $todayHours = hours_today_line($l['trading_hours'] ?? null, $l['holiday_rule'] ?? null, ! empty($l['by_appointment'])); ?>
        <?php if ($todayHours !== null): ?>
            <p class="hours-glance">
                <?= lucide('clock', 'h-4 w-4 shrink-0') ?>
                <?php // Full class names, not built from the state: Tailwind keeps
                      // only the ones it can find spelled out in a template. ?>
                <?php $glanceClass = ['open' => 'hours-glance-open', 'closed' => 'hours-glance-closed'][$todayHours['state'] ?? ''] ?? 'hours-glance-unknown'; ?>
                <span class="<?= $glanceClass ?>"><?= esc($todayHours['label']) ?></span>
                <?php if ($todayHours['detail'] !== ''): ?><span><?= esc($todayHours['detail']) ?></span><?php endif; ?>
                <a href="#hours">See hours</a>
            </p>
        <?php endif; ?>
    <?php endif; ?>
<?php endforeach; ?>
