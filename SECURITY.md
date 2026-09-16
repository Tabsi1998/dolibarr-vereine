# Security

Associations keep personal data of their members, donors and volunteers in
Dolibarr - names, addresses, birth dates and bank details. The module treats a
security problem as urgent.

## Reporting a problem

Do not open a public issue. Use the private
[security advisory form](https://github.com/Tabsi1998/dolibarr-vereine/security/advisories/new)
and describe the problem and how to reproduce it. Never include real member,
donor or bank data, credentials, API keys or database dumps.

You will get an answer within seven days. A confirmed problem is fixed in a
bugfix version and named in the changelog once the fix is released.

## Supported versions

Security fixes go into the newest version. During the beta phase (0.x) that is
the newest beta; there are no fixes for older betas.

## Safeguards in the code

- Every page loads Dolibarr, refuses when the module is off and checks a right
  or administrator status before doing anything.
- Forms carry Dolibarr's CSRF token; input is read through `GETPOST()` only.
- Output is escaped with `dol_escape_htmltag()`.
- Every API endpoint checks the module state and the user's right.
- The module runs no shell commands, evaluates no code and calls no other servers.
- It never changes Dolibarr's files and writes only below the documents folder.

`scripts/check-module.sh` enforces these rules on every change.
