#!/bin/bash
# The live site's state: the first 1.2.0 zip (commit 68c8988) in pesa-donations/ and 1.1.0 in another folder, both active.
# Then the recovery: upload the 1.2.1 zip over pesa-donations/ (Replace current with uploaded), deactivate the old copy, Delete it.
export MSYS_NO_PATHCONV=1
B=http://127.0.0.1:8099
WP=D:/pdtest/wpx.sh
SQL() { /d/xampp/mysql/bin/mysql.exe -u root -h 127.0.0.1 -P 3399 pdwp_test -N -e "$1"; }
PLUG=D:/pdwp/site/wp-content/plugins
REPO=D:/xampp/htdocs/pesadonation
pass=0; fail=0
ok() { if [ "$1" = "$2" ]; then pass=$((pass+1)); echo "PASS $3"; else fail=$((fail+1)); echo "FAIL $3: got [$1] want [$2]"; fi; }

/d/xampp/mysql/bin/mysqldump.exe -u root -h 127.0.0.1 -P 3399 pdwp_test > D:/pdtest/backup/pdwp_test-before-rehearsal.sql
N=$(SQL "SELECT COUNT(*) FROM wp_pd_donations")

echo "== the live state"
rm -rf "${PLUG:?}/pesa-donations" "${PLUG:?}/PesaDonations-main"
git -C "$REPO" archive --prefix=pesa-donations/ 68c8988 | tar -x -C "$PLUG"
git -C "$REPO" archive --prefix=PesaDonations-main/ v1.1.0 | tar -x -C "$PLUG"
$WP plugin activate pesa-donations PesaDonations-main >/dev/null 2>&1
ok "$($WP plugin list --status=active --fields=name,version --format=csv 2>/dev/null | grep -i pesa | sort | tr '\n' ' ')" "PesaDonations-main,1.1.0 pesa-donations,1.2.0 " "1.1.0 and 1.2.0 both active, as on the live site"

echo "== step 1: upload the 1.2.1 zip over pesa-donations (Replace current with uploaded)"
$WP plugin install "${ZIP:-D:/PesaDonations-builds/pesa-donations-1.2.1.zip}" --force >/dev/null 2>&1
ok "$($WP plugin get pesa-donations --field=version 2>/dev/null)" "${VER:-1.2.1}" "pesa-donations replaced in place, now ${VER:-1.2.1}"
ok "$($WP plugin list --field=name 2>/dev/null | grep -ciE '^(pesa-donations|PesaDonations-main)$')" "2" "still two copies, no third"

echo "== step 2: deactivate the 1.1.0 copy; step 3: Delete it"
$WP plugin deactivate PesaDonations-main >/dev/null 2>&1
$WP plugin uninstall PesaDonations-main >/dev/null 2>&1
ok "$(SQL "SELECT COUNT(*) FROM wp_pd_donations" 2>/dev/null)" "$N" "every donation kept"
ok "$($WP plugin list --field=name 2>/dev/null | grep -ciE '^(pesa-donations|PesaDonations-main)$')" "1" "one copy left"
ok "$($WP plugin list --status=active --field=name 2>/dev/null | grep -c '^pesa-donations$')" "1" "and it is active"
curl -s -o /dev/null "$B/wp-admin/" # any admin request restores jobs (logged out: redirect, still runs admin_init? no) — use WP-CLI eval instead
$WP eval 'do_action( "admin_init" );' >/dev/null 2>&1
ok "$($WP cron event list --fields=hook --format=csv 2>/dev/null | grep -c '^pd_reconcile_payments\|^pd_hourly_campaign_cycles\|^pd_purge_gateway_logs\|^pd_daily_campaign_status')" "4" "all four jobs scheduled"
ok "$(curl -s -o /dev/null -w '%{http_code}' $B/give/)" "200" "front end serves"

echo "== the staff dashboard after the recovery, with no manual step"
loc=$(curl -s -o /dev/null -w '%{redirect_url}' "$B/dashboard/")
echo "   signed out, /dashboard/ redirects to: $loc"
ok "$(echo "$loc" | grep -c 'wp-login.php.*dashboard')" "1" "/dashboard/ is the plugin's page (signed out: to login and back), not WordPress's /wp-admin/ redirect"
JAR=D:/pdtest/perf/rehearsal-cookies.txt; rm -f "$JAR"
curl -s -o /dev/null -c "$JAR" "$B/wp-login.php"
curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "log=admin" --data-urlencode "pwd=$(cat D:/pdwp/.adminpass)" -d "wp-submit=Log+In&testcookie=1" "$B/wp-login.php"
menu=$(curl -s -o /dev/null -b "$JAR" -w '%{redirect_url}' "$B/wp-admin/admin.php?page=pesa-donations")
ok "$(echo "$menu" | grep -c '/dashboard/$')" "1" "Donations menu sends an admin to /dashboard/"
ok "$(curl -s -b "$JAR" "$B/dashboard/" | grep -c 'x-data="pdDashboard')" "1" "signed in, /dashboard/ shows the staff dashboard"

echo "== restore"
/d/xampp/mysql/bin/mysql.exe -u root -h 127.0.0.1 -P 3399 pdwp_test < D:/pdtest/backup/pdwp_test-before-rehearsal.sql
rm -rf "${PLUG:?}/pesa-donations" "${PLUG:?}/PesaDonations-main"
bash D:/pdtest/sync.sh >/dev/null
ok "$(SQL "SELECT COUNT(*) FROM wp_pd_donations")" "$N" "database restored"
echo "passed $pass, failed $fail"
