#!/bin/bash
# The live site from its real starting point: a database that has only ever had 1.1.0.
# OLD=<folder> names the old copy's folder (PesaDonations-main sorts before pesa-donations, pesadonations after).
# Steps as Rio did them in the browser (activation as an administrator), checking the dashboard after each.
export MSYS_NO_PATHCONV=1
B=http://127.0.0.1:8099
WP=D:/pdtest/wpx.sh
SQL() { /d/xampp/mysql/bin/mysql.exe -u root -h 127.0.0.1 -P 3399 pdwp_test -N -e "$1"; }
PLUG=D:/pdwp/site/wp-content/plugins
REPO=D:/xampp/htdocs/pesadonation
OLD=${OLD:-PesaDonations-main}
NEW_ZIP=${NEW_ZIP:-D:/PesaDonations-builds/pesa-donations-1.2.1.zip}
JAR=D:/pdtest/perf/fresh-cookies.txt
pass=0; fail=0
ok() { if [ "$1" = "$2" ]; then pass=$((pass+1)); echo "PASS $3"; else fail=$((fail+1)); echo "FAIL $3: got [$1] want [$2]"; fi; }
login() {
	rm -f "$JAR"
	curl -s -o /dev/null -c "$JAR" "$B/wp-login.php"
	curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "log=admin" --data-urlencode "pwd=$(cat D:/pdwp/.adminpass)" -d "wp-submit=Log+In&testcookie=1" "$B/wp-login.php"
}
look() { # what an admin sees: where the Donations menu goes, and what /dashboard/ is
	login
	local menu dash title
	menu=$(curl -s -o /dev/null -b "$JAR" -w '%{http_code} %{redirect_url}' "$B/wp-admin/admin.php?page=pesa-donations")
	dash=$(curl -s -o /dev/null -b "$JAR" -w '%{http_code} %{redirect_url}' "$B/dashboard/")
	title=$(curl -s -b "$JAR" -L "$B/dashboard/" | grep -o '<title>[^<]*' | head -1)
	echo "   Donations menu: $menu"
	echo "   /dashboard/:    $dash  ->  ${title#<title>}"
	curl -s -b "$JAR" "$B/wp-admin/plugins.php" | grep -o 'Two copies of PesaDonations are active\|Another copy of PesaDonations is installed' | head -1 | sed 's/^/   notice: /'
}
dash_ok() { curl -s -b "$JAR" "$B/dashboard/" | grep -c 'x-data="pdDashboard'; }

/d/xampp/mysql/bin/mysqldump.exe -u root -h 127.0.0.1 -P 3399 pdwp_test > D:/pdtest/backup/pdwp_test-before-fresh.sql

echo "== a site that has only ever had 1.1.0, in $OLD/"
$WP plugin deactivate pesa-donations >/dev/null 2>&1
rm -rf "${PLUG:?}/pesa-donations" "${PLUG:?}/${OLD:?}"
SQL "DELETE FROM wp_options WHERE option_name IN ('pd_dashboard_slug','pd_flush_rewrite','pd_pages_checked_at'); UPDATE wp_options SET option_value='1.0.0' WHERE option_name='pd_db_version'"
$WP role delete pd_donations_manager >/dev/null 2>&1
for h in pd_reconcile_payments pd_hourly_campaign_cycles; do $WP cron event delete $h >/dev/null 2>&1; done
$WP eval 'foreach ( wp_roles()->role_objects as $r ) { foreach ( array_keys( $r->capabilities ) as $c ) { if ( str_starts_with( $c, "pd_" ) || str_contains( $c, "pd_campaign" ) ) { $r->remove_cap( $c ); } } }' >/dev/null 2>&1
git -C "$REPO" archive --prefix="$OLD/" v1.1.0 | tar -x -C "$PLUG"
$WP --user=admin plugin activate "$OLD" >/dev/null 2>&1
$WP rewrite flush >/dev/null 2>&1
ok "$($WP option get pd_db_version 2>/dev/null)" "1.0.0" "starting point: schema 1.0.0, only 1.1.0 active"

echo "== Rio uploads the first 1.2.0 zip and clicks Activate"
git -C "$REPO" archive --format=zip --prefix=pesa-donations/ -o D:/pdtest/perf/first-1.2.0.zip 68c8988
$WP --user=admin plugin install D:/pdtest/perf/first-1.2.0.zip --activate >/dev/null 2>&1
curl -s -o /dev/null "$B/"; curl -s -o /dev/null "$B/give/"
look

echo "== Rio uploads 1.2.1 (Replace current with uploaded)"
$WP --user=admin plugin install "$NEW_ZIP" --force >/dev/null 2>&1
curl -s -o /dev/null "$B/"
look

echo "== deactivate the old copy, then Delete it"
$WP --user=admin plugin deactivate "$OLD" >/dev/null 2>&1
$WP --user=admin plugin uninstall "$OLD" >/dev/null 2>&1
curl -s -o /dev/null "$B/"
look
ok "$(dash_ok)" "1" "after the recovery, /dashboard/ is the staff dashboard"
ok "$( [ "$(SQL "SELECT COUNT(*) FROM wp_pd_donations")" -gt 0 ] && echo yes)" "yes" "donations present"

echo "== restore"
/d/xampp/mysql/bin/mysql.exe -u root -h 127.0.0.1 -P 3399 pdwp_test < D:/pdtest/backup/pdwp_test-before-fresh.sql
rm -rf "${PLUG:?}/pesa-donations" "${PLUG:?}/${OLD:?}"
bash D:/pdtest/sync.sh >/dev/null
echo "passed $pass, failed $fail"
