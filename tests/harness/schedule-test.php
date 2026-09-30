<?php
declare(strict_types=1);
if ( 'cli' !== PHP_SAPI ) { exit; } // php schedule-test.php
// Minimal WP stubs so the schedule can run outside WordPress.
function wp_timezone(): DateTimeZone { return new DateTimeZone('Africa/Kampala'); }
function wp_date(string $f, int $ts, ?DateTimeZone $tz = null): string { return (new DateTimeImmutable('@'.$ts))->setTimezone($tz ?? wp_timezone())->format($f); }
function __(string $s, string $d = ''): string { return $s; }
require 'D:/xampp/htdocs/pesadonation/includes/models/class-campaign-period.php';
require 'D:/xampp/htdocs/pesadonation/includes/models/class-campaign-schedule.php';
use PesaDonations\Models\Campaign_Schedule as S;

$fail = 0; $pass = 0;
function eq($got, $want, $msg) { global $fail, $pass; if ($got === $want) { $pass++; } else { $fail++; echo "FAIL $msg: got ".var_export($got,true)." want ".var_export($want,true)."\n"; } }
function d(string $s): DateTimeImmutable { return DateTimeImmutable::createFromFormat('!Y-m-d', $s, wp_timezone()); }
function span($p) { return $p ? $p->get_start()->format('Y-m-d').'..'.$p->get_end()->format('Y-m-d') : null; }

// 1. Monthly from 31 Jan: clamps, never drifts.
$s = new S('monthly', '2026-01-31');
eq(span($s->period_for(d('2026-02-27'))), '2026-01-31..2026-02-27', 'm31 p0');
eq(span($s->period_for(d('2026-02-28'))), '2026-02-28..2026-03-30', 'm31 p1');
eq(span($s->period_for(d('2026-03-30'))), '2026-02-28..2026-03-30', 'm31 p1 end');
eq(span($s->period_for(d('2026-03-31'))), '2026-03-31..2026-04-29', 'm31 p2');
eq(span($s->period_for(d('2027-01-31'))), '2027-01-31..2027-02-27', 'm31 a year on');
// 2. Calendar months and their label.
$s = new S('monthly', '2026-10-01');
eq($s->period_for(d('2026-10-31'))->get_key(), '2026-10-01', 'cal month key');
eq($s->period_for(d('2026-10-31'))->get_label(), 'October 2026', 'cal month label');
eq($s->period_for(d('2026-11-01'))->get_key(), '2026-11-01', 'cal month next');
eq($s->period_for(d('2026-11-01'))->get_counts_from()->format('Y-m-d'), '2026-11-01', 'later periods count from start');
// 3. Weekly.
$s = new S('weekly', '2026-10-05');
eq(span($s->period_for(d('2026-10-11'))), '2026-10-05..2026-10-11', 'week 0');
eq(span($s->period_for(d('2026-10-12'))), '2026-10-12..2026-10-18', 'week 1');
eq(span($s->period_for(d('2027-03-29'))), '2027-03-29..2027-04-04', 'week 25');
eq($s->period_for(d('2026-10-12'))->get_label(), 'Week of 12 Oct 2026', 'week label');
// 4. Quarterly, 6-monthly, yearly.
$s = new S('quarterly', '2026-01-15');
eq(span($s->period_for(d('2026-04-14'))), '2026-01-15..2026-04-14', 'q0');
eq(span($s->period_for(d('2026-04-15'))), '2026-04-15..2026-07-14', 'q1');
$s = new S('biannual', '2026-03-01');
eq(span($s->period_for(d('2026-09-01'))), '2026-09-01..2027-02-28', 'h1 across year');
eq($s->period_for(d('2026-09-01'))->get_label(), '1 Sep 2026 – 28 Feb 2027', 'range label across years');
$s = new S('yearly', '2026-01-01');
eq($s->period_for(d('2027-06-01'))->get_label(), '2027', 'yearly label');
// 5. Leap year.
$s = new S('monthly', '2028-01-31');
eq(span($s->period_for(d('2028-02-29'))), '2028-02-29..2028-03-30', 'leap Feb 29');
// 6. School terms with gaps; since before term 1.
$terms = [
  ['id'=>'t3','label'=>'Term 3 2026','start'=>'2026-09-07','end'=>'2026-12-04'],
  ['id'=>'t1','label'=>'Term 1 2026','start'=>'2026-02-02','end'=>'2026-05-01'],
  ['id'=>'t2','label'=>'','start'=>'2026-05-25','end'=>'2026-08-14'],
];
$s = new S('custom', '', $terms, '2026-01-10 09:00:00');
eq($s->period_for(d('2026-01-20'))->get_key(), 't1', 'before term 1 counts to term 1');
eq($s->period_for(d('2026-01-20'))->get_counts_from()->format('Y-m-d'), '2026-01-10', 'term 1 counts from switch-on');
eq($s->period_for(d('2026-05-10'))->get_key(), 't2', 'holiday counts to next term');
eq($s->period_for(d('2026-05-10'))->get_counts_from()->format('Y-m-d'), '2026-05-02', 'term 2 window opens day after term 1');
eq($s->period_for(d('2026-05-10'))->get_label(), '25 May – 14 Aug 2026', 'blank label falls back to dates');
eq($s->period_for(d('2026-12-04'))->get_key(), 't3', 'last day of last term');
eq($s->period_for(d('2026-12-05')), null, 'after last term: none');
eq($s->previous($s->period_for(d('2026-05-10')))->get_key(), 't1', 'previous of t2');
eq($s->previous($s->period_for(d('2026-03-01'))), null, 'previous of t1');
eq(array_map(fn($p) => $p->get_key(), $s->recent(12)), ['t1','t2','t3'], 'recent (today 2026-09-30 is term 3)');
// 7. Before a fixed start: upcoming period 0, counts from switch-on.
$s = new S('monthly', '2026-11-01', [], '2026-09-30 10:00:00');
eq($s->period_for(d('2026-10-15'))->get_key(), '2026-11-01', 'upcoming first month');
eq($s->period_for(d('2026-10-15'))->get_counts_from()->format('Y-m-d'), '2026-09-30', 'first month counts from switch-on');
eq($s->previous($s->period_for(d('2026-12-02')))->get_key(), '2026-11-01', 'previous month');
// 8. One time, and bad input.
$s = new S('once');
eq($s->repeats(), false, 'once does not repeat'); eq($s->current(), null, 'once has no period');
$s = new S('nonsense', '2026-01-01');
eq($s->get_type(), 'once', 'unknown type falls back to once');
$s = new S('custom', '', [['start'=>'2026-05-01','end'=>'2026-04-01']]);
eq($s->repeats(), false, 'custom with only an inverted row does not repeat');
$s = new S('monthly', '', [], '2026-09-12 08:00:00');
eq($s->period_for(d('2026-10-20'))->get_key(), '2026-10-12', 'no start date: anchors on switch-on');
// 9. Window SQL bounds.
$p = (new S('monthly', '2026-10-01'))->period_for(d('2026-10-10'));
eq([$p->window_start_sql(), $p->window_end_sql()], ['2026-10-01 00:00:00', '2026-10-31 23:59:59'], 'window bounds');
eq($p->get_reset_date()->format('Y-m-d'), '2026-11-01', 'reset date');
// 10. M2: a gift from before repeating was switched on belongs to no period.
$s = new S('monthly', '2026-10-01', [], '2026-09-30 10:00:00');
eq($s->period_for(d('2026-09-29')), null, 'M2: before switch-on, no period');
eq($s->period_for(d('2026-09-30'))->get_key(), '2026-10-01', 'M2: switch-on day counts toward period one');
$s = new S('monthly', '', [], '2026-09-12 08:00:00');
eq($s->period_for(d('2024-03-01')), null, 'M2: legacy history is not labelled period one');
// 11. L5: period one counts from the moment repeating was switched on.
$s = new S('monthly', '2026-10-01', [], '2026-09-30 10:00:00');
eq($s->period_for(d('2026-10-05'))->window_start_sql(), '2026-09-30 10:00:00', 'L5: window starts at switch-on time');
eq($s->period_for(d('2026-11-05'))->window_start_sql(), '2026-11-01 00:00:00', 'L5: later periods start at midnight');
// 12. L4: a start day with no local midnight (Cairo, DST from 24 Apr 2026 00:00).
$cairo = new DateTimeZone('Africa/Cairo');
$s = new S('monthly', '2026-04-24', [], '', $cairo);
$cd = fn(string $v) => DateTimeImmutable::createFromFormat('!Y-m-d', $v, $cairo);
eq($s->period_for($cd('2026-05-24'))->get_key(), '2026-05-24', 'L4: first day of period two belongs to period two');
eq($s->period_for($cd('2026-05-23'))->get_key(), '2026-04-24', 'L4: last day of period one');
eq($s->period_for($cd('2026-04-24'))->get_start()->format('H:i'), '00:00', 'L4: no 01:00 creeping into periods');
// 13. Labels west of UTC name the right month.
$ny = new DateTimeZone('America/New_York');
$s = new S('monthly', '2026-10-01', [], '', $ny);
eq($s->period_for(DateTimeImmutable::createFromFormat('!Y-m-d', '2026-10-10', $ny))->get_label(), 'October 2026', 'label in New York');
echo "passed $pass, failed $fail\n"; exit($fail ? 1 : 0);
