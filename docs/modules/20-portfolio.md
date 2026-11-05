# Module 20 — Portfolio

Status: dropped

Dropped. Leave this note so the idea is not rebuilt by accident.

A holding sits beside cash: a name, a quantity, and a value you type. It counts in net worth the way a tracking account does, and it stays out of available-to-assign.

Allocation, drift, and a live quote wait for an ADR. A quote feed sends data off this machine, the same conflict [bank sync](23-bank-sync.md) has with the local database file. Until that ADR, the value is the number you type.

Demo seed: one holding named Holding 1, with a quantity and a typed value.

Requires tracking accounts for the net-worth rule. The quote ADR is separate and stays unwritten until you choose a source.
