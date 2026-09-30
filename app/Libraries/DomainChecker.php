<?php

namespace App\Libraries;

/**
 * Does a website's domain exist? A DNS lookup only: the site itself is never
 * contacted, so a slow or down site cannot hold up a signup, and nothing is
 * fetched from an address a stranger typed.
 *
 * It catches typos and made-up domains ("mybusines.co.za"). It cannot tell a
 * live site from a parked one, and it is not meant to.
 *
 * A service (Services::domainChecker()) so tests swap in a fake; the testing
 * environment gets one that says yes to everything, so the suite never
 * touches the network.
 */
class DomainChecker
{
    public function exists(string $host): bool
    {
        $host = rtrim(strtolower($host), '.') . '.';

        return checkdnsrr($host, 'A') || checkdnsrr($host, 'AAAA') || checkdnsrr($host, 'CNAME');
    }
}
