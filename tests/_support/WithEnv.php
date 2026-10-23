<?php

namespace Tests\Support;

/**
 * Set .env style keys for the length of a test, the way CI4's env() reads
 * them: $_ENV, then $_SERVER, then getenv(). All three are written, because
 * a key in the developer's own .env would otherwise win over putenv().
 * restoreEnv() puts everything back; call it from tearDown().
 */
trait WithEnv
{
    /** @var array<string,array{env:mixed,server:mixed,get:string|false,hadEnv:bool,hadServer:bool}> */
    private array $envBackup = [];

    protected function setEnv(string $key, string $value): void
    {
        if (! array_key_exists($key, $this->envBackup)) {
            $this->envBackup[$key] = [
                'env'       => $_ENV[$key] ?? null,
                'server'    => $_SERVER[$key] ?? null,
                'get'       => getenv($key),
                'hadEnv'    => array_key_exists($key, $_ENV),
                'hadServer' => array_key_exists($key, $_SERVER),
            ];
        }

        $_ENV[$key] = $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }

    protected function restoreEnv(): void
    {
        foreach ($this->envBackup as $key => $b) {
            if ($b['hadEnv']) {
                $_ENV[$key] = $b['env'];
            } else {
                unset($_ENV[$key]);
            }
            if ($b['hadServer']) {
                $_SERVER[$key] = $b['server'];
            } else {
                unset($_SERVER[$key]);
            }
            $b['get'] === false ? putenv($key) : putenv($key . '=' . $b['get']);
        }
        $this->envBackup = [];
    }
}
