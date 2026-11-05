<?php
/**
 * Book online on a business profile (Book::page). One page, filled in step
 * by step through plain links (?service, ?provider, ?date, ?start), so it
 * works without script; the details form is the only POST.
 *
 * @var array<string,mixed>            $listing
 * @var list<array<string,mixed>>      $services
 * @var array<string,mixed>|null       $service
 * @var list<array<string,mixed>>      $providers
 * @var array<string,mixed>|null       $provider
 * @var string                         $who       '' not chosen, 'any', or a provider id
 * @var string                         $from
 * @var string                         $today
 * @var list<string>                   $days
 * @var string|null                    $date
 * @var list<array<string,mixed>>      $times
 * @var array<string,mixed>|null       $slot
 * @var array<string,mixed>|null       $next
 * @var list<array<string,mixed>>      $fields
 * @var list<array<string,mixed>>      $questions
 * @var array<int,int|null>            $deposits  deposit in cents by service id
 * @var list<string>                   $modes     how the chosen service can be given (DeliveryModes::offered())
 * @var int|null                       $deposit   the chosen service's
 * @var int                            $holdMinutes
 * @var bool                           $hasTerms
 * @var array<string,mixed>            $policies
 * @var array<string,mixed>            $old
 * @var array<string,string>           $errors
 * @var string                         $timezone
 * @var bool                           $twelveHour
 */
$siteName = config('Directory')->siteName();
$base     = 'directory/' . $listing['slug'] . '/book';
$link     = static function (array $params) use ($base): string {
    $params = array_filter($params, static fn ($v) => $v !== null && $v !== '');

    return base_url($base) . ($params === [] ? '' : '?' . http_build_query($params));
};
$tz      = new DateTimeZone($timezone);
$dayName = static fn (string $d): string => (new DateTimeImmutable($d, $tz))->format('D j M');
$longDay = static fn (string $d): string => (new DateTimeImmutable($d, $tz))->format('l j F Y');
$clock   = static function (string $hhmm) use ($twelveHour): string {
    if (! $twelveHour) {
        return $hhmm;
    }
    [$h, $m] = array_map('intval', explode(':', $hhmm));

    return ($h % 12 ?: 12) . ($m ? ':' . sprintf('%02d', $m) : '') . ($h < 12 ? 'am' : 'pm');
};
$price = static fn (array $s): string => $s['price_cents'] !== null ? money($s['price_cents'] / 100, (string) $s['currency']) : '';
// "R85 deposit online" next to a price, when the service asks for one (DepositService::depositFor()).
$depositText = static fn (array $s): string => ($deposits[(int) $s['id']] ?? null) !== null ? money($deposits[(int) $s['id']] / 100, 'ZAR') . ' deposit online' : '';
$labels = [
    'first_name' => 'First name', 'last_name' => 'Surname', 'email' => 'Email', 'phone' => 'Phone',
    'address' => 'Address', 'notes' => 'Anything we should know?',
];
$types = ['email' => 'email', 'phone' => 'tel'];
$val   = static fn (string $k): string => (string) ($old[$k] ?? '');
$err   = static fn (string $k): string => isset($errors[$k]) ? '<p class="mt-1 text-sm text-rose-600">' . esc($errors[$k]) . '</p>' : '';
$whoParam = $who === '' ? null : $who;
?>
<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => 'Book online with ' . $listing['display_name'] . ' | ' . $siteName,
    'description' => 'Choose a service and a time with ' . $listing['display_name'] . '.',
    'canonical'   => base_url($base),
    'robots'      => 'noindex, follow',
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="section">
    <div class="container">
        <p class="mb-2 text-sm"><a class="underline" href="<?= base_url('directory/' . $listing['slug']) ?>"><?= esc($listing['display_name']) ?></a></p>
        <h1 class="mb-1 text-2xl font-extrabold text-slate-900 dark:text-white">Book online</h1>
        <p class="mb-6 text-sm text-slate-500 dark:text-slate-400">Times are in <?= esc($timezone) ?>.</p>

        <?php // 1. Service ?>
        <div class="panel mb-4">
            <h2 class="mb-3 font-bold">1. Service</h2>
            <?php if ($service !== null && count($services) > 1): ?>
                <p class="flex flex-wrap items-center gap-3"><strong><?= esc((string) $service['name']) ?></strong>
                    <span class="text-sm text-slate-500"><?= (int) $service['duration_min'] ?> min<?= $price($service) !== '' ? ', ' . esc($price($service)) : '' ?><?= $depositText($service) !== '' ? ', ' . esc($depositText($service)) : '' ?></span>
                    <a class="text-sm underline" href="<?= $link([]) ?>">Change</a></p>
            <?php elseif ($service !== null): ?>
                <p><strong><?= esc((string) $service['name']) ?></strong> <span class="text-sm text-slate-500"><?= (int) $service['duration_min'] ?> min<?= $price($service) !== '' ? ', ' . esc($price($service)) : '' ?><?= $depositText($service) !== '' ? ', ' . esc($depositText($service)) : '' ?></span></p>
            <?php else: ?>
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php foreach ($services as $s): ?>
                        <li>
                            <a class="flex items-center gap-3 py-3 hover:text-primary-600" href="<?= $link(['service' => $s['id']]) ?>">
                                <span class="flex-1">
                                    <span class="block font-semibold"><?= esc((string) $s['name']) ?></span>
                                    <?php if (trim((string) ($s['description'] ?? '')) !== ''): ?><span class="block text-sm text-slate-500"><?= esc((string) $s['description']) ?></span><?php endif ?>
                                </span>
                                <span class="text-right text-sm text-slate-500"><?= (int) $s['duration_min'] ?> min<?= $price($s) !== '' ? '<br>' . esc($price($s)) : '' ?><?= $depositText($s) !== '' ? '<br>' . esc($depositText($s)) : '' ?></span>
                            </a>
                        </li>
                    <?php endforeach ?>
                </ul>
            <?php endif ?>
        </div>

        <?php // 2. Who ?>
        <?php if ($service !== null && count($providers) > 1): ?>
            <div class="panel mb-4">
                <h2 class="mb-3 font-bold">2. With</h2>
                <?php if ($who !== ''): ?>
                    <p class="flex flex-wrap items-center gap-3"><strong><?= $provider !== null ? esc((string) $provider['name']) : 'Anyone available' ?></strong>
                        <a class="text-sm underline" href="<?= $link(['service' => $service['id']]) ?>">Change</a></p>
                <?php else: ?>
                    <div class="flex flex-wrap gap-2">
                        <a class="btn btn-ghost" href="<?= $link(['service' => $service['id'], 'provider' => 'any']) ?>">Anyone available</a>
                        <?php foreach ($providers as $p): ?>
                            <a class="btn btn-ghost" href="<?= $link(['service' => $service['id'], 'provider' => $p['id']]) ?>"><?= esc((string) $p['name']) ?></a>
                        <?php endforeach ?>
                    </div>
                <?php endif ?>
            </div>
        <?php endif ?>

        <?php // 3. Day and time ?>
        <?php if ($service !== null && $who !== ''): ?>
            <div class="panel mb-4" id="time">
                <h2 class="mb-3 font-bold"><?= count($providers) > 1 ? '3' : '2' ?>. Choose a time</h2>
                <?php if ($slot !== null): ?>
                    <p class="flex flex-wrap items-center gap-3"><strong><?= esc($longDay((string) $date)) ?>, <?= esc($clock($slot['time'])) ?></strong>
                        <a class="text-sm underline" href="<?= $link(['service' => $service['id'], 'provider' => $whoParam, 'date' => $date]) ?>#time">Change</a></p>
                <?php else: ?>
                    <div class="mb-3 flex items-center gap-2 text-sm">
                        <?php $prev = max($today, (new DateTimeImmutable($from, $tz))->modify('-14 days')->format('Y-m-d')); ?>
                        <?php if ($from > $today): ?><a class="underline" href="<?= $link(['service' => $service['id'], 'provider' => $whoParam, 'from' => $prev]) ?>#time">Earlier</a><?php endif ?>
                        <span class="text-slate-500"><?= esc($dayName($from)) ?> to <?= esc($dayName((new DateTimeImmutable($from, $tz))->modify('+13 days')->format('Y-m-d'))) ?></span>
                        <a class="underline" href="<?= $link(['service' => $service['id'], 'provider' => $whoParam, 'from' => (new DateTimeImmutable($from, $tz))->modify('+14 days')->format('Y-m-d')]) ?>#time">Later</a>
                    </div>
                    <?php if ($days === []): ?>
                        <p class="text-sm">No free times in these days.
                            <?php if ($next !== null): ?>The next free time is <a class="underline" href="<?= $link(['service' => $service['id'], 'provider' => $whoParam, 'from' => $next['date'], 'date' => $next['date']]) ?>#time"><?= esc($longDay($next['date'])) ?> at <?= esc($clock($next['time'])) ?></a>.<?php endif ?>
                        </p>
                    <?php else: ?>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach ($days as $d): ?>
                                <a class="<?= $d === $date ? 'btn btn-primary' : 'btn btn-ghost' ?> btn-xs" href="<?= $link(['service' => $service['id'], 'provider' => $whoParam, 'from' => $from, 'date' => $d]) ?>#time"<?= $d === $date ? ' aria-current="date"' : '' ?>><?= esc($dayName($d)) ?></a>
                            <?php endforeach ?>
                        </div>
                    <?php endif ?>
                    <?php if ($date !== null): ?>
                        <h3 class="mt-5 mb-2 text-sm font-semibold"><?= esc($longDay($date)) ?></h3>
                        <?php if ($times === []): ?>
                            <p class="text-sm">No times left on this day. Try another day.</p>
                        <?php else: ?>
                            <div class="flex flex-wrap gap-2">
                                <?php foreach ($times as $t): ?>
                                    <a class="btn btn-ghost btn-xs tabular-nums" href="<?= $link(['service' => $service['id'], 'provider' => $whoParam, 'from' => $from, 'date' => $date, 'start' => $t['start']]) ?>#details"><?= esc($clock($t['time'])) ?></a>
                                <?php endforeach ?>
                            </div>
                        <?php endif ?>
                    <?php endif ?>
                <?php endif ?>
            </div>
        <?php endif ?>

        <?php // 4. Details ?>
        <?php if ($slot !== null): ?>
            <form method="post" action="<?= base_url($base) ?>" class="form-card" id="details">
                <?= csrf_field() ?>
                <?= honeypot_field() ?>
                <input type="hidden" name="service_id" value="<?= (int) $service['id'] ?>">
                <input type="hidden" name="provider_id" value="<?= $provider !== null ? (int) $provider['id'] : 0 ?>">
                <input type="hidden" name="start" value="<?= esc($slot['start'], 'attr') ?>">
                <h2 class="mb-4 font-bold"><?= count($providers) > 1 ? '4' : '3' ?>. Your details</h2>
                <?php if (count($modes) > 1): ?>
                    <fieldset class="field">
                        <legend class="mb-1 text-sm font-semibold">How would you like it?</legend>
                        <?php foreach ($modes as $i => $m): ?>
                            <label class="flex items-center gap-2 text-sm"><input type="radio" name="delivery_mode" value="<?= esc($m, 'attr') ?>" required<?= $val('delivery_mode') === $m || ($val('delivery_mode') === '' && $i === 0) ? ' checked' : '' ?>> <?= esc(\App\Services\Bookings\Integrations\DeliveryModes::label($m)) ?></label>
                        <?php endforeach ?>
                        <?= $err('delivery_mode') ?>
                    </fieldset>
                <?php elseif ($modes !== [] && $modes[0] !== 'onsite'): ?>
                    <p class="mb-4 text-sm"><?= esc(\App\Services\Bookings\Integrations\DeliveryModes::label($modes[0])) ?>. We send you the link to join.</p>
                <?php endif ?>
                <div class="grid gap-x-4 sm:grid-cols-2">
                    <?php foreach ($fields as $f): ?>
                        <?php if ($f['name'] === 'notes' || $f['name'] === 'address') { continue; } ?>
                        <div class="field">
                            <label for="bk-<?= $f['name'] ?>"><?= $labels[$f['name']] ?><?= $f['required'] ? '' : ' <span class="font-normal text-slate-500">(optional)</span>' ?></label>
                            <input id="bk-<?= $f['name'] ?>" type="<?= $types[$f['name']] ?? 'text' ?>" name="<?= $f['name'] ?>" value="<?= esc($val($f['name']), 'attr') ?>" maxlength="<?= $f['name'] === 'email' ? 190 : 80 ?>"
                                   autocomplete="<?= ['first_name' => 'given-name', 'last_name' => 'family-name', 'email' => 'email', 'phone' => 'tel'][$f['name']] ?? 'off' ?>"<?= $f['required'] ? ' required' : '' ?>>
                            <?= $err($f['name']) ?>
                        </div>
                    <?php endforeach ?>
                </div>
                <?php foreach ($fields as $f): ?>
                    <?php if ($f['name'] !== 'address') { continue; } ?>
                    <div class="field">
                        <label for="bk-address"><?= $labels['address'] ?><?= $f['required'] ? '' : ' <span class="font-normal text-slate-500">(optional)</span>' ?></label>
                        <input id="bk-address" type="text" name="address" value="<?= esc($val('address'), 'attr') ?>" maxlength="255" autocomplete="street-address"<?= $f['required'] ? ' required' : '' ?>>
                        <?= $err('address') ?>
                    </div>
                <?php endforeach ?>
                <?php foreach ($questions as $q): ?>
                    <?= view('business/_question', ['f' => $q, 'id' => 'bk-' . esc($q['key'], 'attr'), 'value' => $val($q['key']), 'error' => $err($q['key']), 'strict' => true]) ?>
                <?php endforeach ?>
                <?php foreach ($fields as $f): ?>
                    <?php if ($f['name'] !== 'notes') { continue; } ?>
                    <div class="field">
                        <label for="bk-notes"><?= $labels['notes'] ?><?= $f['required'] ? '' : ' <span class="font-normal text-slate-500">(optional)</span>' ?></label>
                        <textarea id="bk-notes" name="notes" rows="3" maxlength="1000"<?= $f['required'] ? ' required' : '' ?>><?= esc($val('notes')) ?></textarea>
                        <?= $err('notes') ?>
                    </div>
                <?php endforeach ?>
                <?php if ($hasTerms): ?>
                    <div class="field">
                        <label class="flex items-start gap-2 font-normal">
                            <input type="checkbox" name="accept" value="1" class="mt-1" required<?= $val('accept') !== '' ? ' checked' : '' ?>>
                            <span>I accept the <a class="underline" href="<?= base_url($base . '/terms') ?>" target="_blank" rel="noopener">booking terms of <?= esc($listing['display_name']) ?></a>.</span>
                        </label>
                        <?= $err('accept') ?>
                    </div>
                <?php endif ?>
                <p class="mb-4 text-xs text-slate-500 dark:text-slate-400">
                    <?= esc($listing['display_name']) ?> receives your details to look after your booking. WebScheduler Local keeps them for the business, as our <a class="underline" href="<?= base_url('privacy') ?>">privacy policy</a> explains.
                    <?php if ((int) $policies['cancel_hours'] > 0): ?>You can cancel online up to <?= (int) $policies['cancel_hours'] ?> <?= (int) $policies['cancel_hours'] === 1 ? 'hour' : 'hours' ?> before.<?php endif ?>
                </p>
                <?php if ($deposit !== null): ?>
                    <p class="alert alert-info mb-4 text-sm">To keep this time, pay a deposit of <?= esc(money($deposit / 100, 'ZAR')) ?> online straight after booking. It goes to <?= esc($listing['display_name']) ?>. We hold the time for <?= (int) $holdMinutes ?> minutes; if the deposit is not paid by then, the time is freed for others.</p>
                <?php endif ?>
                <button type="submit" class="btn btn-accent btn-block"><?= $deposit !== null ? 'Book and pay the deposit' : 'Book ' . esc((string) $service['name']) . ', ' . esc($dayName((string) $date)) . ' at ' . esc($clock($slot['time'])) ?></button>
            </form>
        <?php endif ?>

        <p class="mt-6 text-sm text-slate-500 dark:text-slate-400">Booked before? <a class="underline" href="<?= base_url('book/find') ?>">Find your booking</a> to change or cancel it.</p>
    </div>
</section>
<?= $this->endSection() ?>
