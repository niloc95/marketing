<?php
/**
 * The bookings API's reference (Developers::bookings), drawn from
 * docs/bookings/openapi.json.
 *
 * @var array<string,mixed> $spec
 */
$siteName = config('Directory')->siteName();
$resolve  = static function (array $schema) use ($spec): array {
    while (isset($schema['$ref'])) {
        $schema = $spec['components'][str_starts_with($schema['$ref'], '#/components/responses/') ? 'responses' : 'schemas'][basename($schema['$ref'])] ?? [];
    }

    return $schema;
};
// Properties of a schema, allOf merged, one level of $ref followed.
$props = static function (array $schema) use ($resolve): array {
    $schema = $resolve($schema);
    $out    = [];
    foreach ($schema['allOf'] ?? [$schema] as $part) {
        $part = $resolve($part);
        foreach ($part['properties'] ?? [] as $name => $p) {
            $out[$name] = $p + ['required' => in_array($name, $part['required'] ?? [], true)];
        }
    }

    return $out;
};
$type = static function (array $p) use ($resolve): string {
    if (isset($p['$ref'])) {
        return basename($p['$ref']);
    }
    if (isset($p['oneOf'])) {
        return implode(' or ', array_map(static fn (array $o): string => isset($o['$ref']) ? basename($o['$ref']) : (string) $o['type'], $p['oneOf']));
    }
    $t = implode(' or ', (array) ($p['type'] ?? 'object'));
    if (($p['type'] ?? '') === 'array') {
        $t = 'list of ' . (isset($p['items']['$ref']) ? basename($p['items']['$ref']) : ($p['items']['type'] ?? ''));
    }

    return $t . (isset($p['enum']) ? ': ' . implode(', ', $p['enum']) : '');
};
$fields = static function (array $schema) use ($props, $type): string {
    $rows = '';
    foreach ($props($schema) as $name => $p) {
        $rows .= '<tr class="border-t border-slate-100 dark:border-slate-800"><td class="py-1 pr-3 align-top"><code>' . esc($name) . '</code>' . (! empty($p['required']) ? ' <span class="text-xs text-rose-600">required</span>' : '') . '</td>'
            . '<td class="py-1 pr-3 align-top text-slate-500">' . esc($type($p)) . '</td><td class="py-1 align-top">' . esc((string) ($p['description'] ?? '')) . '</td></tr>';
    }

    return $rows === '' ? '' : '<div class="overflow-x-auto"><table class="w-full text-left text-sm"><tbody>' . $rows . '</tbody></table></div>';
};
$byTag = [];
foreach ($spec['paths'] as $path => $methods) {
    foreach ($methods as $method => $o) {
        $byTag[$o['tags'][0] ?? 'Other'][] = [$method, $path, $o];
    }
}
$server = $spec['servers'][0]['url'];
?>
<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Online bookings API | ' . $siteName, 'description' => 'Read and book appointments from your own programs with the WebScheduler Local online bookings API.', 'canonical' => base_url('developers/bookings'), 'robots' => 'noindex, follow']) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="section">
    <div class="container">
        <h1 class="mb-2 text-2xl font-extrabold text-slate-900 dark:text-white">Online bookings API</h1>
        <p class="text-slate-700 dark:text-slate-200"><?= esc($spec['info']['description']) ?> Version <?= esc($spec['info']['version']) ?>. The same as a file: <a class="underline" href="<?= base_url('developers/bookings/openapi.json') ?>">openapi.json</a> (OpenAPI 3.1).</p>

        <nav class="mt-4 flex flex-wrap gap-3 text-sm" aria-label="On this page">
            <a class="underline" href="#start">Getting started</a>
            <?php foreach (array_keys($byTag) as $tag): ?>
                <a class="underline" href="#<?= esc(strtolower($tag), 'attr') ?>"><?= esc($tag) ?></a>
            <?php endforeach ?>
            <a class="underline" href="#webhooks">Webhooks</a>
        </nav>

        <h2 id="start" class="mb-2 mt-8 text-xl font-bold">Getting started</h2>
        <ol class="list-decimal space-y-2 pl-5 text-slate-700 dark:text-slate-200">
            <li>An admin of the business makes a key in <strong>Settings, API keys</strong> in their business area, and chooses what it may do (its scopes). The key belongs to that business and acts as that person: it can never do more than they can.</li>
            <li>Send it on every request: <code>Authorization: Bearer wsk_…</code></li>
            <li>Every answer is JSON. Success is <code>{"data": …, "meta": {…}}</code>; a problem is <code>{"error": {"message", "code", "details"}}</code> with a 4xx status.</li>
            <li>Times you get are ISO 8601 in UTC, with the business's local time beside them. Times you send carry an offset, or are a date and time in the business's time zone.</li>
            <li>Up to 120 requests a minute per key; above that the answer is 429 with <code>Retry-After</code>.</li>
            <li>Writes accept JSON (<code>Content-Type: application/json</code>) or a form.</li>
        </ol>
        <pre class="mt-4 overflow-x-auto rounded bg-slate-900 p-3 text-sm text-slate-100"><code>curl <?= esc($server) ?>/appointments?from=2030-01-07 \
  -H "Authorization: Bearer wsk_…"</code></pre>

        <?php foreach ($byTag as $tag => $ops): ?>
            <h2 id="<?= esc(strtolower($tag), 'attr') ?>" class="mb-2 mt-10 text-xl font-bold"><?= esc($tag) ?></h2>
            <?php foreach ($ops as [$method, $path, $o]): ?>
                <article class="panel mt-4" id="<?= esc($o['operationId'], 'attr') ?>">
                    <h3 class="font-bold"><?= esc($o['summary']) ?></h3>
                    <p class="mt-1 font-mono text-sm"><span class="rounded bg-slate-100 px-1 font-bold dark:bg-slate-800"><?= strtoupper($method) ?></span> <?= esc($path) ?></p>
                    <p class="mt-2 text-sm text-slate-700 dark:text-slate-200"><?= esc(trim(explode("\n\nScope:", $o['description'])[0])) ?></p>
                    <p class="mt-1 text-sm">Scope: <code><?= esc($o['x-scope']) ?></code></p>
                    <?php $query = array_filter($o['parameters'] ?? [], static fn (array $p): bool => $p['in'] === 'query') ?>
                    <?php if ($query !== []): ?>
                        <h4 class="mt-3 text-sm font-semibold">Query</h4>
                        <?= $fields(['type' => 'object', 'required' => array_values(array_map(static fn (array $p): string => $p['name'], array_filter($query, static fn (array $p): bool => ! empty($p['required'])))), 'properties' => array_column(array_map(static fn (array $p): array => ['name' => $p['name'], 's' => $p['schema'] + ['description' => $p['description'] ?? '']], $query), 's', 'name')]) ?>
                    <?php endif ?>
                    <?php if (isset($o['requestBody'])): ?>
                        <h4 class="mt-3 text-sm font-semibold">Body</h4>
                        <?php $body = $o['requestBody']['content']['application/json']['schema'] ?>
                        <?php if (($d = $resolve($body)['allOf'][0]['description'] ?? $resolve($body)['description'] ?? '') !== ''): ?><p class="text-sm"><?= esc($d) ?></p><?php endif ?>
                        <?= $fields($body) ?>
                    <?php endif ?>
                    <p class="mt-3 text-sm">Answers: <?= esc(implode(', ', array_keys($o['responses']))) ?>.
                        <?php $ok = $o['responses']['200'] ?? $o['responses']['201'] ?? null ?>
                        <?php if ($ok !== null): ?>
                            <?php $data = $ok['content']['application/json']['schema']['properties']['data'] ?>
                            data is <?= esc(isset($data['items']) ? 'a list of ' . basename($data['items']['$ref']) : basename($data['$ref'] ?? 'object')) ?>.
                        <?php endif ?>
                    </p>
                </article>
            <?php endforeach ?>
        <?php endforeach ?>

        <h2 id="objects" class="mb-2 mt-10 text-xl font-bold">Objects</h2>
        <?php foreach (['Appointment', 'Customer', 'Service', 'Provider', 'Slot', 'Business'] as $name): ?>
            <article class="panel mt-4" id="object-<?= strtolower($name) ?>">
                <h3 class="font-bold"><?= $name ?></h3>
                <?php if (isset($spec['components']['schemas'][$name]['description'])): ?><p class="text-sm"><?= esc($spec['components']['schemas'][$name]['description']) ?></p><?php endif ?>
                <?= $fields(['$ref' => '#/components/schemas/' . $name]) ?>
            </article>
        <?php endforeach ?>

        <h2 id="webhooks" class="mb-2 mt-10 text-xl font-bold">Webhooks</h2>
        <p class="text-slate-700 dark:text-slate-200">An admin sets an https address in <strong>Settings, Connections</strong> and chooses the events. We POST a JSON message to it each time one happens, within a minute or so. Any 2xx answer within 10 seconds counts as received; anything else is tried again after 1, 5, 30, 120 and 360 minutes, and the business can send a failed one again.</p>
        <?= $fields(['$ref' => '#/components/schemas/WebhookEvent']) ?>
        <h3 class="mb-2 mt-6 font-bold">Checking the signature</h3>
        <p class="text-slate-700 dark:text-slate-200">Each message has a header <code>WebScheduler-Signature: t=&lt;unix time&gt;,v1=&lt;hex&gt;</code>. The hex is an HMAC with SHA256 of the time, a full stop and the raw body, under your signing secret (shown in Settings, Connections). Refuse a message whose signature does not match, or whose time is more than five minutes old. The same event may arrive twice: use its <code>id</code> to notice.</p>
        <pre class="mt-3 overflow-x-auto rounded bg-slate-900 p-3 text-sm text-slate-100"><code>$body   = file_get_contents('php://input');
$header = $_SERVER['HTTP_WEBSCHEDULER_SIGNATURE'] ?? '';
parse_str(str_replace(',', '&amp;', $header), $sig);
$wanted = hash_hmac('sha256', ($sig['t'] ?? '') . '.' . $body, $secret);
if (! hash_equals($wanted, $sig['v1'] ?? '') || abs(time() - (int) ($sig['t'] ?? 0)) > 300) {
    http_response_code(400);
    exit;
}
$event = json_decode($body, true);</code></pre>
    </div>
</section>
<?= $this->endSection() ?>
