# Security policy

## Supported versions

Only the latest minor release of 2.x is supported. Fixes are released only there, as patch
releases. A new minor release ends the fixes for the previous one. Release 1.x gets no more fixes.

A fix that refuses a new attack pattern comes as a patch release, even though it can stop an
install that an earlier release accepted.

## Reporting a vulnerability

Do not report a vulnerability in a pull request: pull requests are public. Report it privately
through a GitHub Security Advisory:
[Report a vulnerability](https://github.com/sorge-it/cdp-browser-installer/security/advisories/new).

If `install` reports another SHA-256 for a new download of a pinned version, do not pin the new
bytes; report it here.

Include a pin file, an archive or a call that shows the problem, the platform, and the output of
`php -v`, `curl --version` and `unzip -v`. If you have a fix, say so in the report; I will then
open a private fork in the advisory for it. There is no fixed response time.

The package downloads archives from the network and unpacks them, and its `deps` command runs as
root. The README states what it checks ([Security](../README.md#security)) and what it does not
([Limits of the security model](../README.md#limits-of-the-security-model)).
