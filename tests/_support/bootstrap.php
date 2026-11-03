<?php

/**
 * CodeIgniter's test bootstrap, then the two settings no developer's .env
 * may leak into the suite.
 *
 * A worktree previewing both sites locally has sites.app.enabled = true and
 * sites.app.host = app.localhost in its .env (Config\Sites). The suite assumes
 * local.webscheduler.app is off unless a test switches it on (Tests\Support\
 * WithEnv), and uses its real name. <env> in phpunit.dist.xml cannot pin them:
 * CodeIgniter's bootstrap loads .env after PHPUnit applies it. So they are set
 * here, after it, the way WithEnv sets them: $_ENV, $_SERVER and putenv().
 */
require __DIR__ . '/../../vendor/codeigniter4/framework/system/Test/bootstrap.php';

foreach (['sites.app.enabled' => 'false', 'sites.app.host' => 'local.webscheduler.app'] as $key => $value) {
    $_ENV[$key] = $_SERVER[$key] = $value;
    putenv($key . '=' . $value);
}

// The index gates go back to Config\Directory's defaults. The dev .env mirrors
// production's (indexMinDescription = 0 since 25 Oct 2026), and ProfileVisibilityTest
// pins the defaults, so it failed on any machine whose .env followed prod.
foreach (['directory.indexMinDescription', 'directory.indexMinQuality'] as $key) {
    unset($_ENV[$key], $_SERVER[$key]);
    putenv($key);
}

// Paystack: no key and PayFast for new checkouts unless a test says otherwise,
// so a developer's Paystack key can never send the suite to the real API.
// Tests that need Paystack set both with WithEnv and inject a fake client
// (Services::injectMock('paystack')).
foreach (['directory.paystackSecretKey', 'directory.paymentProvider'] as $key) {
    unset($_ENV[$key], $_SERVER[$key]);
    putenv($key);
}
