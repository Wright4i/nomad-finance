# A paycheck is figured from a rate

Each pay cycle stores a pay kind and a rate in the base currency, and the paycheck for one period is figured from those. Salary is an annual rate, split across 52 weekly, 26 biweekly, 24 semimonthly, or 12 monthly paydays. Fixed pay is the rate itself. Hourly pay is the rate times the hours in the period. An irregular cycle has no even split, so a salary on that cycle is not a paycheck. Storing only a typed paycheck would hide whether the number is a wage, a salary, or a flat amount.
