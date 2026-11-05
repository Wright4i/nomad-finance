# Module 11 — Postings

Status: now

A posting is money moving, in the account's currency, with a bucket or a split across several. You confirm it before the stored balance moves. [A confirmed posting moves the stored balance](../adr/0016-posting-moves-balance.md). Typing a balance still replaces the stored number and does not replay older postings. Mark paid on a bill still does not move a balance.

| Field | Rule |
| --- | --- |
| Account | Required, and the posting uses that account's currency |
| Bucket | Required. One bucket, or a [split](12-splits.md) whose lines sum to the amount |
| Amount | Integer minor units, greater than zero |
| Direction | Into the account, or out of it. The log opens on money out |
| Payee | Optional |
| Memo | Optional |
| Date | Required calendar date. A date after today is refused. The field opens on today |
| Trip | Optional. The stay that covers the date is selected, and on a handoff date the arriving stay is the default. It can be cleared or changed. Moving the dates does not unlink a posting already attached |
| Pay cycle | Optional, and only on an inflow. An outflow cannot name one |
| Vehicle | Required when the posting records an odometer or a price per gallon. Other postings leave it empty |
| Odometer | Optional whole miles, and only when a bucket on the posting is a fuel bucket |
| Price per gallon | Optional, in the account's cents, and only when a bucket on the posting is a fuel bucket. Zero is refused. Gallons use the fuel lines |

Remaining for a bucket in the current month is the assignment minus net postings dated in that month. The assignment stays what you typed. Available-to-assign does not rise when you spend. A negative remaining is shown. Cover now and carry stay about a negative assignment you type.

The menu item is Log. It opens on capture. Remaining and the account balance are on the screen. Recent postings sit under the form, including a split that was already stored. The form does not add a line. A fuel bucket asks for the vehicle and the optional readings. The vehicle select opens on the starred vehicle, or the only vehicle when none is starred. Gallons are the amount divided by the price per gallon. Miles per gallon for a stretch are the miles since the previous odometer on that same vehicle divided by those gallons. The odometer opens on the highest reading already stored for that vehicle. A lower reading is refused. The same reading twice shows no miles per gallon and says the reading did not advance. Deleting a fill-up joins the readings on either side of that vehicle. The screen does not ask for a photo or a PDF.

Date, payee, memo, and stay are on the form. Direction is a fieldset and opens on out. Save is busy while the quote runs. A failed submit focuses the first field with an error. Confirm stays. The dialog still quotes the old balance and the new one before save. Editing or deleting a fuel reading from the vehicle does the same when the amount or the account would change. See [a vehicle is its own odometer](../adr/0019-vehicle-odometer.md).

Paying a card from checking is two postings until [transfers](26-transfers.md).

On a stay, lodging planned is compared with lodging charged, and other postings linked to the stay are listed beside that. Lodging charges are written by the trip module.

Demo seed: one posting out of Checking 1 split across Needs 1 and Wants 1, one posting into Checking 1, Fuel 1, Vehicle 1 (an rv, starred, 6 to 12 mpg) with the fill-ups attached, one fill-up inside that range and one outside it, one fill-up with both readings, one without, and enough advancing fill-ups for three stretches. One inflow names a pay cycle.

Requires accounts, buckets, and trips for the stay link. Tags, reconcile, and import wait on later modules.
