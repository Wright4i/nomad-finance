# Module 23 — Bank sync

Status: dropped

Dropped. Leave this note so the idea is not rebuilt by accident.

A bank connection would fetch postings. A local bridge is the first shape, if this module is ever opened. Plaid, MX, Finicity, and Teller come only after an ADR. They send financial data off this machine, which cuts against the local database file in [SQLite in the repo](../adr/0004-sqlite-file-in-repo.md).

Reconnection alerts belong here, with the connection. They are not a portal feature.

The ADR was never written. The module stays dropped. Postings would have to exist before a connection had anywhere to put a fetched line.
