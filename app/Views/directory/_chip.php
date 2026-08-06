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
 */
$count = $count ?? null;
?>
<a class="chip" href="<?= esc($href, 'attr') ?>"><?= esc($label) ?><?php if ($count !== null): ?> <span class="chip-count">(<?= (int) $count ?>)</span><?php endif; ?></a>
