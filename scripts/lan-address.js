/**
 * lan-address.js — this machine's address on the local network.
 * -----------------------------------------------------------------------------
 * Shared by dev-hot.js and dev-reload.js so the startup banner can print a URL
 * another device can actually open, instead of a localhost link that is only
 * true on the machine doing the serving.
 *
 * Looked up at print time rather than written into a config, because these are
 * DHCP leases — the address that was right last week is not necessarily right
 * now. The Bonjour name is the stable one, which is why it is offered alongside.
 *
 * Note this only reports the address. Reaching it also needs the server bound to
 * 0.0.0.0 (see dev-hot.js) and the host listed in app.devHostnames in .env, or
 * base_url() keeps emitting localhost and CSP blocks every asset.
 * -----------------------------------------------------------------------------
 */

import { execFileSync } from 'child_process';
import os from 'os';

/** Every non-internal IPv4 address, in interface order. */
export function lanAddresses() {
  return Object.values(os.networkInterfaces())
    .flat()
    .filter((nic) => nic && nic.family === 'IPv4' && !nic.internal)
    .map((nic) => nic.address);
}

/**
 * The Bonjour hostname, or null when it cannot be determined.
 *
 * os.hostname() is the wrong source on macOS: it returns the DNS name
 * ("NilosMacStudio"), while the name Bonjour actually advertises is the
 * LocalHostName ("Nilos-Mac-Studio"). They differ by the hyphens, so deriving
 * one from the other prints a URL that does not resolve — worse than printing
 * nothing. scutil is the authority, so ask it.
 *
 * Anywhere else, only trust a hostname that already carries .local; a bare name
 * is no use to another machine.
 */
export function bonjourHost() {
  if (process.platform === 'darwin') {
    try {
      const name = execFileSync('scutil', ['--get', 'LocalHostName'], {
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'ignore'],
      }).trim();

      return name === '' ? null : `${name.toLowerCase()}.local`;
    } catch {
      // No LocalHostName set, or scutil missing. Not worth failing a banner over.
      return null;
    }
  }

  const name = os.hostname();

  return name.endsWith('.local') ? name.toLowerCase() : null;
}

/**
 * Lines describing where the server can be reached, localhost first.
 *
 * @param {number} port
 * @returns {string[]}
 */
export function serverUrls(port) {
  const hosts = ['localhost', ...lanAddresses()];
  const bonjour = bonjourHost();
  if (bonjour !== null) hosts.push(bonjour);

  return hosts.map((host) => `http://${host}:${port}`);
}
