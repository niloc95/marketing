<?php
/**
 * The sections of a vacancy (App\Libraries\PostingSections), for a careers
 * role and a Jobs board vacancy alike. Paragraphs, bullets, and Logistics
 * facts with a bold label. Each section is a .posting-section; the caller
 * adds its own closing "About X" section after these.
 *
 * @var list<array{key:string,heading:string,type:string,items:list<string|array{label:string,value:string}>}> $sections
 */
?>
<?php foreach ($sections as $section): ?>
    <section class="posting-section">
        <h2><?= esc($section['heading']) ?></h2>
        <?php if ($section['type'] === 'paragraphs'): ?>
            <?php foreach ($section['items'] as $p): ?>
                <p><?= nl2br(esc($p)) ?></p>
            <?php endforeach; ?>
        <?php else: ?>
            <ul>
                <?php foreach ($section['items'] as $item): ?>
                    <?php if (is_array($item)): ?>
                        <li><strong><?= esc($item['label']) ?>:</strong> <?= esc($item['value']) ?></li>
                    <?php else: ?>
                        <li><?= esc($item) ?></li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
<?php endforeach; ?>
