# SNMP fixtures

The files in this directory contain synthetic lab values only. They do not contain production addresses, communities, users, authentication passphrases, privacy passphrases, engine identifiers, or packet captures.

`interfaces.json` is an intentionally committed, bounded SNMP walk result used by the protocol and monitor-check tests. Keep this directory and every fixture reference lowercase so the contract is identical on case-sensitive CI hosts and Windows development hosts.
