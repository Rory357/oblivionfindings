# Flow protocol fixtures

`tests/Unit/Monitoring/FlowDecoderTest.php` constructs deterministic network-byte-order packets for NetFlow v5, NetFlow v9 template/data sets, IPFIX template/data sets (including an enterprise field), and an sFlow v5 raw Ethernet/IPv4/TCP sample. Keeping the builders next to the assertions makes packet length, sequence, template, truncation, and record-limit mutations reviewable without storing opaque production captures.

The packets contain documentation-only RFC1918 addresses and synthetic counters. They contain no production traffic, identifiers, or credentials.
