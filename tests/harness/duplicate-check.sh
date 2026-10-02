#!/bin/bash
# Two installed copies: a real 1.1.0 in another folder beside the current build.
# Proves the site keeps running, the admin is told, and deleting the old copy keeps the donations.
# MUTANT=1 removes the uninstall guard from the synced copy to prove the test catches data loss.
export MSYS_NO_PATHCONV=1
B=http://127.0.0.1:8099
WP=D:/pdtest/wpx.sh
SQL() { /d/xampp/mysql/bin/mysql.exe -u root -h 127.0.0.1 -P 3399 pdwp_test -N -e "$1"; }
PLUG=D:/pdwp/site/wp-content/plugins
JAR=D:/pdtest/perf/dup-cookies.txt
pass=0; fail=0
ok() { if [ "$1" = "$2" ]; then pass=$((pass+1)); echo "PASS $3"; else fail=$((fail+1)); echo "FAIL $3: got [$1] want [$2]"; fi; }
has() { if echo "$1" | grep -q -- "$2"; then pass=$((pass+1)); echo "PASS $3"; else fail=$((fail+1)); echo "FAIL $3: [$2] not found"; fi; }
old_copy() { # folder
	rm -rf "${PLUG:?}/${1:?}"
	git -C D:/xampp/htdocs/pesadonation archive --prefix="$1/" v1.1.0 | tar -x -C "$PLUG"
}
login() {
	rm -f "$JAR"
	curl -s -o /dev/null -c "$JAR" "$B/wp-login.php"
	curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "log=admin" --data-urlencode "pwd=$(cat D:/pdwp/.adminpass)" -d "wp-submit=Log+In&testcookie=1" "$B/wp-login.php"
}

echo "== setup"
/d/xampp/mysql/bin/mysqldump.exe -u root -h 127.0.0.1 -P 3399 pdwp_test > D:/pdtest/backup/pdwp_test-before-dup.sql
bash D:/pdtest/sync.sh >/dev/null
if [ "$MUTANT" = 1 ]; then
	python -c "
import pathlib
p = pathlib.Path(r'D:/pdwp/site/wp-content/plugins/pesa-donations/includes/core/class-duplicate-guard.php')
t = p.read_text(encoding='utf-8')
a = \"add_action( 'pre_uninstall_plugin', [ \$this, 'disarm_other_uninstall' ], 10, 1 );\"
assert a in t
p.write_text(t.replace(a, ''), encoding='utf-8')
print('mutant: uninstall guard removed')"
fi
: > D:/pdwp/site/wp-content/debug.log
N=$(SQL "SELECT COUNT(*) FROM wp_pd_donations")
echo "donations before: $N"

echo "== old copy first in load order (PesaDonations-main sorts before pesa-donations)"
old_copy PesaDonations-main
$WP plugin activate PesaDonations-main >/dev/null 2>&1
ok "$($WP plugin list --status=active --field=name 2>/dev/null | grep -ci pesa)" "2" "both copies active"
ok "$(curl -s -o /dev/null -w '%{http_code}' $B/give/)" "200" "front end still serves"
login
page=$(curl -s -b "$JAR" "$B/wp-admin/plugins.php")
has "$page" "Two copies of PesaDonations are active" "Plugins screen says two copies are active"
has "$page" "Waiting: pesa-donations/pesa-donations.php" "…and which one waits"

echo "== deactivate the old copy, then delete it the way the Delete button does"
$WP plugin deactivate PesaDonations-main >/dev/null 2>&1
page=$(curl -s -b "$JAR" "$B/wp-admin/plugins.php")
has "$page" "Another copy of PesaDonations is installed" "with the old copy inactive, the new one names it"
$WP plugin uninstall PesaDonations-main >/dev/null 2>&1
ok "$(SQL "SELECT COUNT(*) FROM wp_pd_donations" 2>/dev/null)" "$N" "donations kept after deleting the old copy"
ok "$(SQL "SELECT COUNT(*) FROM wp_options WHERE option_name='pd_db_version'")" "1" "settings kept"
ok "$([ -d "$PLUG/PesaDonations-main" ] && echo present || echo gone)" "gone" "old copy's folder removed"
curl -s -o /dev/null -b "$JAR" "$B/wp-admin/index.php"
ok "$($WP cron event list --fields=hook --format=csv 2>/dev/null | grep -c '^pd_reconcile_payments\|^pd_hourly_campaign_cycles')" "2" "jobs back after one admin page"
page=$(curl -s -b "$JAR" "$B/wp-admin/plugins.php")
if echo "$page" | grep -q "Another copy of PesaDonations"; then fail=$((fail+1)); echo "FAIL duplicate notice gone"; else pass=$((pass+1)); echo "PASS duplicate notice gone"; fi

if [ "$MUTANT" != 1 ]; then
	echo "== new copy first in load order (pesadonations-old sorts after pesa-donations)"
	old_copy pesadonations-old
	$WP plugin activate pesadonations-old >/dev/null 2>&1
	ok "$(curl -s -o /dev/null -w '%{http_code}' $B/give/)" "200" "front end still serves"
	page=$(curl -s -b "$JAR" "$B/wp-admin/plugins.php")
	has "$page" "Another copy of PesaDonations is installed" "Plugins screen names the other copy"
	$WP plugin deactivate pesadonations-old >/dev/null 2>&1

	echo "== deleting the NEW copy while an old one is installed, with 'delete all data' ticked"
	$WP option update pd_remove_data_on_uninstall 1 >/dev/null
	$WP plugin deactivate pesa-donations >/dev/null 2>&1
	$WP plugin uninstall pesa-donations --skip-delete >/dev/null 2>&1
	ok "$(SQL "SELECT COUNT(*) FROM wp_pd_donations" 2>/dev/null)" "$N" "shared tables kept: another copy still uses them"
	ok "$($WP role exists pd_donations_manager >/dev/null 2>&1 && echo yes || echo no)" "yes" "role kept for the other copy"
	rm -rf "${PLUG:?}/pesadonations-old"
	$WP option update pd_remove_data_on_uninstall 0 >/dev/null
	$WP plugin activate pesa-donations >/dev/null 2>&1
fi

echo "== restore"
/d/xampp/mysql/bin/mysql.exe -u root -h 127.0.0.1 -P 3399 pdwp_test < D:/pdtest/backup/pdwp_test-before-dup.sql
rm -rf "${PLUG:?}/PesaDonations-main" "${PLUG:?}/pesadonations-old"
bash D:/pdtest/sync.sh >/dev/null
ok "$(SQL "SELECT COUNT(*) FROM wp_pd_donations")" "$N" "database restored"
grep -i "fatal" D:/pdwp/site/wp-content/debug.log | head -3
echo "passed $pass, failed $fail"
