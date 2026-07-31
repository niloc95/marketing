<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Generate a password_hash() for the directory admin panel, so nobody has to
 * hand-roll one or paste a plaintext password into .env.
 *
 *   php spark directory:adminhash
 *   php spark directory:adminhash "my secret"
 */
class AdminHash extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'directory:adminhash';
    protected $description = 'Generate a bcrypt hash for directory.adminPasswordHash.';
    protected $usage       = 'directory:adminhash [password]';

    public function run(array $params): int
    {
        $password = $params[0] ?? CLI::prompt('Admin password', null, 'required');
        $password = (string) $password;

        if (strlen($password) < 12) {
            CLI::error('Use at least 12 characters — this password guards edit and delete.');
            return EXIT_ERROR;
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        CLI::newLine();
        CLI::write('Add this to your .env (quote it — the hash contains $ characters):', 'green');
        CLI::newLine();
        CLI::write("directory.adminPasswordHash = '{$hash}'");
        CLI::newLine();
        CLI::write('Then remove directory.adminPassword.', 'yellow');
        CLI::newLine();

        return EXIT_SUCCESS;
    }
}
