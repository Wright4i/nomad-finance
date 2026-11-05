# Module 12 — Splits

Status: now

One posting can name many buckets. A split line has a bucket and an amount. The lines sum to the posting amount. Each amount is greater than zero, and a bucket is on one line. A line may be tax or tip, which are buckets like any other.

The account still moves once, by the posting amount, and only after the confirmation that shows the old balance and the new one. Each line counts toward that bucket's remaining. A posting with one bucket stores no lines.

The log form records one bucket. The splits API still accepts lines, and a posting that already has lines still shows each line in Recent. Fuel readings stay available when a line is a fuel bucket, and gallons use that line's amount.

Demo seed: Payee 1 is $15.00 out of Checking 1 on 2026-11-02, split $10.00 to Needs 1 and $5.00 to Wants 1.

Requires postings.
