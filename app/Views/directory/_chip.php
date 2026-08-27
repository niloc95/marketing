<?php
/**
 * A linked pill. The single definition of the chip that was previously
 * copy-pasted across index.php, categories.php and landing.php.
 *
 * Callers MUST pass ['saveData' => false] as view()'s third argument. CI4's
 * renderer keeps data between render() calls by default, so a counted chip
 * followed by an uncounted one ("All provinces") would print the previous
 * chip's count. The guard below only covers the very first call.
 *
 * @var string   $label
 * @var string   $href
 * @var int|null $count Rendered in brackets when given; null for a plain chip.
 * @var string|null $icon Lucide name, drawn after the label; null for none.
 * @var string|null $tint Category-group colour class; null leaves the chip slate.
 */
$count = $count ?? null;
$icon  = $icon ?? null;
$tint  = $tint ?? null;
?>
<a class="chip<?= $icon !== null ? ' inline-flex items-center gap-1' : '' ?><?= $tint !== null ? ' ' . $tint : '' ?>" href="<?= esc($href, 'attr') ?>"><?= esc($label) ?><?php if ($icon !== null): ?><?= lucide($icon, 'h-3.5 w-3.5 shrink-0') ?><?php endif; ?><?php if ($count !== null): ?> <span class="chip-count">(<?= (int) $count ?>)</span><?php endif; ?></a>
