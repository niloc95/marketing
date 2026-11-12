<?php
/**
 * One of the business's own questions (Settings, Questions tab), in the
 * appointment and customer forms.
 *
 * @var array{key:string,title:string,type:string,required:bool,options:list<string>} $f
 * @var string $id     element id
 * @var string $value  current answer
 * @var string $error  HTML of the error line
 * @var bool   $strict mark required (the appointment form); a customer's own record never requires
 */
$req  = $strict && $f['required'];
$name = esc($f['key'], 'attr');
$opt  = $req ? '' : ' <span class="font-normal text-slate-500">(optional)</span>';
$min  = (int) ($f['min'] ?? 0);
$max  = (int) ($f['max'] ?? 0);
$type = $f['type'];
$hint = match ($type) {
    'za_id'  => '13 digits',
    'number' => \App\Services\Bookings\CustomerService::lengthWords($min, $max, 'digits'),
    'code'   => \App\Services\Bookings\CustomerService::lengthWords($min, $max, 'characters'),
    default  => '',
};
?>
<div class="field">
    <?php if ($f['type'] === 'checkbox'): ?>
        <label class="flex items-start gap-2 font-normal">
            <input id="<?= $id ?>" type="checkbox" name="<?= $name ?>" value="1" class="mt-1"<?= $value !== '' ? ' checked' : '' ?><?= $req ? ' required' : '' ?>>
            <span><?= esc($f['title']) ?></span>
        </label>
    <?php else: ?>
        <label for="<?= $id ?>"><?= esc($f['title']) ?><?= $opt ?></label>
        <?php if ($f['type'] === 'textarea'): ?>
            <textarea id="<?= $id ?>" name="<?= $name ?>" rows="3" maxlength="2000"<?= $req ? ' required' : '' ?>><?= esc($value) ?></textarea>
        <?php elseif ($f['type'] === 'select'): ?>
            <select id="<?= $id ?>" name="<?= $name ?>"<?= $req ? ' required' : '' ?>>
                <option value=""></option>
                <?php foreach ($f['options'] as $o): ?>
                    <option value="<?= esc($o, 'attr') ?>"<?= $value === $o ? ' selected' : '' ?>><?= esc($o) ?></option>
                <?php endforeach ?>
            </select>
        <?php elseif (in_array($type, ['number', 'za_id', 'code'], true)): ?>
            <?php
            // Room for spaces or dashes between groups, which are dropped on save.
            $room = $type === 'za_id' ? 13 : ($max > 0 ? $max : \Config\Bookings::CUSTOM_FIELD_LENGTH_MAX);
            ?>
            <input id="<?= $id ?>" type="text" name="<?= $name ?>" maxlength="<?= $room + 6 ?>" value="<?= esc($value, 'attr') ?>" autocomplete="off" spellcheck="false"<?= $type === 'code' ? ' autocapitalize="characters"' : ' inputmode="numeric"' ?><?= $hint !== '' ? ' aria-describedby="' . $id . '-hint"' : '' ?><?= $req ? ' required' : '' ?>>
            <?php if ($hint !== ''): ?>
                <p id="<?= $id ?>-hint" class="mt-1 text-xs text-slate-500 dark:text-slate-400"><?= esc(ucfirst($hint)) ?></p>
            <?php endif ?>
        <?php else: ?>
            <input id="<?= $id ?>" type="text" name="<?= $name ?>" maxlength="2000" value="<?= esc($value, 'attr') ?>"<?= $req ? ' required' : '' ?>>
        <?php endif ?>
    <?php endif ?>
    <?= $error ?>
</div>
