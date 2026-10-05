<?php
/**
 * What the typed search was read as, above the results: "Showing Dentist
 * profiles in Sandton matching “teeth whitening”", each part a chip that takes
 * it away, and a way back to the words exactly as typed.
 *
 * The point is that nothing is changed behind the visitor's back. A category
 * read out of the words narrows the results as much as one picked from the
 * select, so it has to be just as visible and just as easy to undo.
 *
 * When the keywords matched nothing and the list fell back to the category and
 * place alone (Directory::index()), this says so first, in its own block —
 * otherwise the visitor reads a list of dentists as the answer to "teeth
 * whitening" and concludes we misunderstood them.
 *
 * Only the parts that were actually applied are shown: a category the visitor
 * picked from the select outranks one in the words, so the words' one is not
 * offered as a chip.
 *
 * @var \App\Services\Search\SearchIntent|null $intent
 * @var bool                $relaxed
 * @var array<string,mixed> $filters    as the visitor sent them
 * @var array<string,mixed> $urlFilters the same, shaped for a URL
 * @var bool                $hasNear    a position is already set
 */
if ($intent !== null):
    $subject  = ($filters['category'] ?? '') === '' && ($filters['group'] ?? '') === ''
        ? ($intent->categoryName ?? $intent->groupName)
        : null;
    $place    = ($filters['city'] ?? '') === '' ? $intent->place : null;
    $prov     = ($filters['province'] ?? '') === '' ? $intent->province : null;
    $keywords = $intent->keywords();
    $showNear = $intent->nearMe && ! $hasNear;

    if ($subject !== null || $place !== null || $prov !== null || $showNear):
        $where = implode(', ', array_filter([$place, $prov], static fn ($v) => $v !== null));

        // "Dentist profiles in Sandton", optionally with what it had to match.
        $describe = static function (bool $capital, bool $withKeywords) use ($subject, $where, $keywords): string {
            $out = $subject !== null
                ? '<strong>' . esc($subject) . '</strong> profiles'
                : ($capital ? 'Profiles' : 'profiles');
            if ($where !== '') {
                $out .= ' in <strong>' . esc($where) . '</strong>';
            }
            if ($withKeywords && $keywords !== '') {
                $out .= ' matching <strong>“' . esc($keywords) . '”</strong>';
            }

            return $out;
        };

        // Everything else on the URL rides along, so taking a part away does
        // not also drop a facet, a province picked from the select or a
        // position.
        $keep = array_diff_key($urlFilters, array_flip(['q', 'page', 'exact', 'place']));
        $href = static fn (array $query): string => base_url('directory') . '?'
            . http_build_query(array_filter($query + $keep, static fn ($v) => $v !== '' && $v !== null && $v !== []));

        $chips = [];
        if ($subject !== null) {
            $chips[] = [$subject, $href(['q' => $intent->toQuery('category')])];
        }
        if ($place !== null) {
            $chips[] = [$place, $href(['q' => $intent->toQuery('place')])];
        }
        if ($prov !== null) {
            $chips[] = [$prov, $href(['q' => $intent->toQuery('province')])];
        }
        if ($keywords !== '' && ! $relaxed) {
            $chips[] = ['“' . $keywords . '”', $href(['q' => $intent->toQuery('keywords')])];
        }
        $exact = $href(['q' => $intent->raw, 'exact' => '1']);
?>
<div class="search-reading">
    <?php if ($relaxed): ?>
        <div class="search-notice" role="status">
            <p class="search-notice-title">No <?= $describe(false, false) ?> matched “<?= esc($keywords) ?>”.</p>
            <p>Showing all <?= $describe(false, false) ?> instead.</p>
        </div>
    <?php elseif ($subject !== null || $where !== ''): ?>
        <p class="search-reading-line">Showing <?= $describe(false, true) ?></p>
    <?php endif; ?>

    <?php if ($chips !== []): ?>
        <ul class="search-reading-chips" aria-label="Parts of your search">
            <?php foreach ($chips as [$label, $url]): ?>
                <li>
                    <a class="search-chip" href="<?= esc($url, 'attr') ?>" aria-label="Remove <?= esc($label, 'attr') ?> from your search">
                        <?= esc($label) ?><?= lucide('x', 'h-3.5 w-3.5 shrink-0') ?>
                    </a>
                </li>
            <?php endforeach; ?>
            <li><a class="search-reading-exact" href="<?= esc($exact, 'attr') ?>">Search the exact words instead</a></li>
        </ul>
    <?php endif; ?>

    <?php // Asked for "near me": offered, never assumed. The position is only
          // requested when this button is pressed — see directory.js. Hidden
          // until the script confirms the browser can do it. ?>
    <?php if ($showNear): ?>
        <p class="search-reading-near" data-near-me-wrap hidden>
            To sort these by distance from you, share your location.
            <button type="button" class="btn btn-ghost btn-xs" data-near-me>Use my location</button>
        </p>
    <?php endif; ?>
</div>
<?php
    endif;
endif;
