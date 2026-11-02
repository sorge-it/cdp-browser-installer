# Security policy

## Supported versions

The latest minor release of 1.x. Fixes are released only there, as patch releases. A new minor
release ends the fixes for the previous one.

A fix that refuses a new attack pattern comes as a patch release, even though it can stop an
install that an earlier release accepted.

## Reporting a vulnerability

Do not report a vulnerability in a pull request: pull requests are public. Report it privately
through a GitHub Security Advisory:
[Report a vulnerability](https://github.com/sorge-it/cdp-browser-installer/security/advisories/new).

Include a pin file, an archive or a call that shows the problem, the platform, and the output of
`php -v`, `curl --version` and `unzip -v`. If you have a fix, say so in the report; we will then
open a private fork in the advisory for it. There is no fixed response time.

The package downloads archives from the network and unpacks them, and its `deps` command runs as
root. The README states what it checks ([Security](README.md#security)) and what it does not
([Limits of the security model](README.md#limits-of-the-security-model)).
