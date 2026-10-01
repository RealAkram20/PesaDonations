#!/bin/bash
# Uninstall on the throwaway site: keep-data (default) and delete-data. Restore the DB afterwards.
WP=D:/pdtest/wpx.sh
SQL() { /d/xampp/mysql/bin/mysql.exe -u root -h 127.0.0.1 -P 3399 pdwp_test -N -e "$1"; }
pass=0; fail=0
ok() { if [ "$1" = "$2" ]; then pass=$((pass+1)); echo "PASS $3"; else fail=$((fail+1)); echo "FAIL $3: got [$1] want [$2]"; fi; }
crons() { $WP cron event list --fields=hook --format=csv 2>/dev/null | grep -c '^pd_'; }

before=$(SQL "SELECT COUNT(*) FROM wp_pd_donations")
ok "$( [ "$(crons)" -gt 0 ] && echo yes )" "yes" "active plugin has its jobs scheduled"

echo "== uninstall, keep data (default)"
$WP option update pd_remove_data_on_uninstall 0 >/dev/null
$WP plugin uninstall pesa-donations --deactivate --skip-delete >/dev/null 2>&1
ok "$(SQL "SELECT COUNT(*) FROM wp_pd_donations")" "$before" "donations kept"
ok "$(SQL "SELECT COUNT(*) FROM wp_posts WHERE post_type='pd_campaign'" | tr -d ' ' | awk '{print ($1>0)?"yes":"no"}')" "yes" "campaigns kept"
ok "$(crons)" "0" "every pd_ job unscheduled"
ok "$($WP role exists pd_donations_manager >/dev/null 2>&1 && echo yes || echo no)" "no" "Donations Manager role removed"
ok "$($WP cap list administrator 2>/dev/null | grep -c '^pd_\|pd_campaign')" "0" "administrator capabilities removed"

echo "== reactivate"
$WP plugin activate pesa-donations >/dev/null 2>&1
ok "$( [ "$(crons)" -gt 0 ] && echo yes )" "yes" "jobs scheduled again"
ok "$($WP role exists pd_donations_manager >/dev/null 2>&1 && echo yes || echo no)" "yes" "role back"
ok "$( [ "$($WP cap list administrator 2>/dev/null | grep -c 'pd_manage_donations')" -gt 0 ] && echo yes )" "yes" "administrator can manage donations again"

echo "== uninstall, delete data (opted in)"
$WP option update pd_remove_data_on_uninstall 1 >/dev/null
$WP plugin uninstall pesa-donations --deactivate --skip-delete >/dev/null 2>&1
ok "$(SQL "SHOW TABLES LIKE 'wp_pd_%'" | wc -l | tr -d ' ')" "0" "plugin tables dropped"
ok "$(SQL "SELECT COUNT(*) FROM wp_posts WHERE post_type='pd_campaign'")" "0" "campaigns deleted"
ok "$(SQL "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE 'pd\_%' AND option_name NOT LIKE 'pd\_mock%'")" "0" "plugin options deleted"
ok "$(SQL "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE '\_transient%pd\_%'")" "0" "plugin transients deleted"

echo "== restore"
/d/xampp/mysql/bin/mysql.exe -u root -h 127.0.0.1 -P 3399 pdwp_test < D:/pdtest/backup/pdwp_test-before-uninstall.sql
ok "$(SQL "SELECT COUNT(*) FROM wp_pd_donations")" "$before" "database restored"
ok "$($WP plugin is-active pesa-donations && echo yes)" "yes" "plugin active again after restore"
echo "passed $pass, failed $fail"
