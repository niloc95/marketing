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
        <?php else: ?>
            <input id="<?= $id ?>" type="text" name="<?= $name ?>" maxlength="2000" value="<?= esc($value, 'attr') ?>"<?= $req ? ' required' : '' ?>>
        <?php endif ?>
    <?php endif ?>
    <?= $error ?>
</div>
