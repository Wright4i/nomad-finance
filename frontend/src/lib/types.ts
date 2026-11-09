import type { VehicleKind } from './vehicleKinds';

export type User = {
  name: string;
  baseCurrency: string;
  theme: 'dark' | 'light';
  role: 'owner' | 'demo' | 'user';
  acctPeriodStart: string;
  acctPeriodEnd: string;
  acctPeriodLength: string;
};

export type Account = {
  id: number;
  name: string;
  type: string;
  side: 'asset' | 'liability';
  currency: string;
  exponent: number;
  balanceMinor: number;
  interestBps: number | null;
  minimumDueMinor: number | null;
  dueDay: number | null;
  creditLimitMinor: number | null;
  autopayChoice: 'none' | 'minimum' | 'balance';
  bankAutopay: boolean;
  billRecurrence: 'daily' | 'monthly' | 'yearly';
  billEvery: number;
  liabilityBillType: 'debt' | 'vehicle';
  institution: string | null;
  holder: string | null;
  notes: string | null;
  gasFavorite: boolean;
  shoppingFavorite: boolean;
  assetFavorite: boolean;
  inBudget: boolean;
  archivedAt: string | null;
};

export type Bill = {
  id: number;
  name: string;
  type: string;
  accountId: number;
  accountName: string;
  accountType: string;
  accountHolder: string | null;
  currency: string;
  exponent: number;
  amountMinor: number;
  recurrence: string;
  every: number;
  anchorDate: string;
  nextDueDate: string;
  lastPaidOn: string | null;
  autopay: boolean;
  notes: string | null;
  status: 'active' | 'paused' | 'paid' | 'archived' | 'hidden';
  otherLabel: string | null;
  categoryId: number | null;
  categoryName: string | null;
  locked: boolean;
  sourceAccountId: number | null;
  vehicleId?: number | null;
  odometerMiles?: number | null;
  pricePerGallonMinor?: number | null;
};

export type CategoryTarget = {
  kind: 'needed' | 'balance';
  amountMinor: number | null;
  targetDate: string | null;
  payCycleId: number | null;
  payor: string | null;
  periodsLeft: number | null;
  neededMinor: number | null;
  met: boolean;
  passed: boolean;
  celebrate: boolean;
};

export type EnvelopeGroup = {
  group: 'needs' | 'wants' | 'savings';
  targetPercent: number;
  amountMinor: number;
  shareBasisPoints: number;
  over: boolean;
  assignedMinor: number;
  autoMinor: number;
  envelopeMinor: number;
  maximumMinor: number;
  targetMinor: number;
  goalOver: boolean;
};

export type EnvelopeBill = {
  id: number;
  name: string;
  amountMinor: number;
};

export type EnvelopeCategory = {
  id: number;
  name: string;
  group: 'needs' | 'wants' | 'savings';
  role?: 'goal' | 'locked';
  billType?: string | null;
  amountMinor: number;
  billMinor?: number;
  remainingMinor?: number;
  archivedAt: string | null;
  target: CategoryTarget | null;
  bills?: EnvelopeBill[];
};

export type EnvelopeSheet = {
  baseCurrency: string;
  exponent: number;
  payCycleId: number | null;
  payors: { id: number; payor: string; length: string }[];
  period: { id: number | null; start: string; end: string | null; editable: boolean; upcoming: boolean } | null;
  nextPeriod: { start: string; end: string | null } | null;
  previousPeriod: PeriodStep | null;
  stepNext: PeriodStep | null;
  closed: { id: number; start: string; end: string | null }[];
  availableMinor: number;
  assignedMinor: number;
  poolMinor: number;
  leftOut: { accountId: number; name: string; currency: string; exponent: number; balanceMinor: number }[];
  billLeftOut?: string[];
  incomeMinor: number;
  sources: {
    income: { id: number; payor: string; on: string; length: string; amountMinor: number }[];
    accounts: { id: number; name: string; type: string; institution: string | null; holder: string | null; amountMinor: number }[];
    floats?: { id: number; name: string; type: string; institution: string | null; holder: string | null; balanceMinor: number; coveredMinor: number; uncoveredMinor: number }[];
  };
  acctPeriod?: { start: string; end: string; length: string; phase: string; editable: boolean; upcoming: boolean; label: string };
  categories: EnvelopeCategory[];
  groups: EnvelopeGroup[];
  celebrate: boolean;
};

export type PayKind = 'salary' | 'fixed' | 'hourly';

export type HoursMode = 'typed' | 'weekdays';

export type WeekdayPay = {
  weekdays: number;
  hours: number;
  grossMinor: number;
  earlierDay: number;
  earlierMinor: number;
  laterDay: number;
  laterMinor: number;
};

export type MonthlyWeekday = {
  weekdays: number;
  month: string;
  hours: string;
  paycheckMinor: number;
};

export type PayCycle = {
  id: number;
  payor: string;
  payday: string;
  length: 'weekly' | 'biweekly' | 'semimonthly' | 'monthly' | 'irregular';
  secondDay: number | null;
  nextPayday: string | null;
  payKind: PayKind;
  rateMinor: number;
  hoursMode: HoursMode;
  averageOn: boolean;
  hours: string | null;
  paycheckMinor: number | null;
  monthlyMinor: number | null;
  weekdayPay: WeekdayPay | null;
  monthlyWeekday: MonthlyWeekday | null;
  periodStart: string;
  periodEnd: string | null;
  rollsOn: string | null;
  archivedAt: string | null;
};

export type PeriodStep = {
  id: number | null;
  start: string;
  end: string | null;
  upcoming: boolean;
};

export type Category = {
  id: number;
  name: string;
  group: 'needs' | 'wants' | 'savings';
  role: 'goal' | 'locked';
  billType: string | null;
  locked: boolean;
  fuel: boolean;
  amountMinor: number;
  archivedAt: string | null;
  target: CategoryTarget | null;
};

export type DriveLeg = {
  fromId: number;
  toId: number;
  miles: number;
  driving: boolean;
  travel: 'drive' | 'fly';
  coordinates: [number, number][];
};

export type FuelLabel = {
  miles: number | null;
  milesPerGallon: string | null;
  costMinor: number | null;
  priceMinor: number | null;
};

export type CalendarEntry = {
  id: number;
  text: string;
  startOn: string;
  endOn: string;
  days?: string[];
};

export type Trip = {
  id: number;
  placeName: string;
  note: string | null;
  ratingHalfStars: number | null;
  ratingComment: string | null;
  arriveOn: string;
  departOn: string;
  balanceDueOn: string | null;
  latitude: string;
  longitude: string;
  city: string | null;
  region: string | null;
  address: string | null;
  status: 'upcoming' | 'underway' | 'done';
  accountId: number | null;
  accountName: string | null;
  currency: string | null;
  exponent: number;
  totalMinor: number;
  amountDueMinor: number;
  travel: 'drive' | 'fly';
  routeOrder: number;
  moveEarlier: boolean;
  moveLater: boolean;
  depositMinor: number;
  depositCharged: boolean;
  dueCharged: boolean;
  otherSpentMinor: number;
  fuel: FuelLabel;
  color: string;
};

export type ChargeQuote = {
  accountName: string;
  currency: string;
  exponent: number;
  oldBalanceMinor: number;
  newBalanceMinor: number;
  refused: string | null;
  overLimit: boolean;
  amountMinor: number;
};

export type Stretch = {
  miles: number | null;
  gallons: string | null;
  milesPerGallon: string | null;
  note: string | null;
};

export type PostingLine = {
  categoryId: number;
  categoryName: string;
  amountMinor: number;
  remainingMinor: number;
};

export type Posting = {
  id: number;
  accountId: number;
  accountName: string;
  currency: string;
  exponent: number;
  balanceMinor: number;
  categoryId: number;
  categoryName: string;
  lines: PostingLine[];
  fuel: boolean;
  tripId: number | null;
  placeName: string | null;
  amountMinor: number;
  direction: 'in' | 'out';
  payee: string | null;
  memo: string | null;
  postedOn: string;
  lodgingPart: 'deposit' | 'due' | null;
  odometerMiles: number | null;
  pricePerGallonMinor: number | null;
  vehicleId: number | null;
  stretch: Stretch | null;
  remainingMinor: number;
  assignedMinor: number;
};

export type FuelCharge = {
  id: number;
  amountMinor: number;
  pricePerGallonMinor: number | null;
};

export type VehicleReading = {
  id: number;
  postedOn: string;
  odometerMiles: number | null;
  pricePerGallonMinor: number | null;
  amountMinor: number;
  charges?: FuelCharge[];
  accountId: number;
  accountName: string;
  currency: string;
  categoryId: number;
  direction: 'in' | 'out';
  payee: string | null;
  memo: string | null;
  tripId: number | null;
  miles: number | null;
  gallons: string | null;
  milesPerGallon: string | null;
  note: string | null;
};

export type Vehicle = {
  id: number;
  name: string;
  kind: VehicleKind;
  mpgMinTenths: number;
  mpgMaxTenths: number;
  starred: boolean;
  currentOdometerMiles: number;
  fuelPriceMinor: number | null;
  suggestedPricePerGallonMinor: number | null;
  milesPerGallon: string | null;
  archivedAt: string | null;
  readings: VehicleReading[];
};

export type Rate = {
  id: number | null;
  baseCurrency: string;
  currency: string;
  fallbackRate: string | null;
  liveRate: string | null;
  live: boolean;
  used: boolean;
};

export type Unconverted = {
  accountId: number;
  name: string;
  side: string;
  currency: string;
  balanceMinor: number;
  exponent: number;
};

export type PayAverage = {
  months: string[];
  averageMinor: number;
  excluded: string[];
};

export type IncomeRow = {
  id: number;
  payor: string;
  payKind: PayKind;
  length: string;
  rateMinor: number;
  hours: string | null;
  hoursMode: HoursMode;
  paycheckMinor: number | null;
  weekdayPay: WeekdayPay | null;
  average: PayAverage | null;
  periodStart: string;
  periodEnd: string | null;
};

export type PaydayRow = {
  kind: 'payday';
  on: string;
  payCycleId: number;
  payor: string;
  paycheckMinor: number | null;
  hoursMode: HoursMode;
  weekdayPay: WeekdayPay | null;
};

export type BillRow = {
  kind: 'bill';
  on: string;
  id: number;
  name: string;
  amountMinor: number;
  currency: string;
  exponent: number;
  accountName: string;
  autopay: boolean;
};

export type StayRow = {
  kind: 'stay';
  on: string;
  tripId: number;
  placeName: string;
  arriveOn: string;
  departOn: string;
  fuel: FuelLabel;
};

export type BalanceDueRow = {
  kind: 'balanceDue';
  on: string;
  tripId: number;
  placeName: string;
  amountMinor: number;
  currency: string | null;
  exponent: number;
};

export type TimelineRow = PaydayRow | BillRow | StayRow | BalanceDueRow;

export type DueSlice = {
  type: string;
  amountMinor: number;
};

export type DuePie = {
  currency: string;
  exponent: number;
  slices: DueSlice[];
  leftOut?: string[];
};

export type PlannerWidget = 'income' | 'calendar' | 'trips' | 'accounts' | 'envelopes' | 'bills';

export type PlannerLayoutId = PlannerWidget | 'net';

export type PlannerPayday = {
  payor: string;
  on: string;
  paycheckMinor: number | null;
};

export type PlannerStay = {
  id: number;
  placeName: string;
  city: string | null;
  region: string | null;
  arriveOn: string;
  departOn: string;
  latitude: string | null;
  longitude: string | null;
};

export type AccountTotal = {
  type: string;
  balanceMinor: number;
  side: 'asset' | 'liability';
};

export type Dashboard = {
  baseCurrency: string;
  exponent: number;
  today: string;
  windowStart: string;
  windowEnd: string;
  netWorthMinor: number | null;
  assetsMinor: number;
  liabilitiesMinor: number;
  interestLostMinor: number;
  interestGainedMinor: number;
  unconverted: Unconverted[];
  timeline: TimelineRow[];
  envelopes: EnvelopeSheet;
  income: IncomeRow[];
  incomeMinor: number;
  duePies: DuePie[];
  heading: string;
  headingItalic: boolean;
  payday: PlannerPayday | null;
  incomeComing: number;
  payorCount: number;
  stay: PlannerStay | null;
  accountTotals: AccountTotal[];
  plannerOrder: PlannerLayoutId[];
  plannerSpans: Record<PlannerLayoutId, 1 | 2 | 3 | 4 | 5 | 6>;
};
