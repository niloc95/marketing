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
