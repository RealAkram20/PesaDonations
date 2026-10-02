#!/bin/bash
# Upgrade 1.2.x -> 1.3.0 on the throwaway site: every campaign's raised amount and donor count must be unchanged,
# the new column back-filled, the currency list migrated, the rates job scheduled.
export MSYS_NO_PATHCONV=1
WP=D:/pdtest/wpx.sh
SQL() { /d/xampp/mysql/bin/mysql.exe -u root -h 127.0.0.1 -P 3399 pdwp_test -N -e "$1"; }
pass=0; fail=0
ok() { if [ "$1" = "$2" ]; then pass=$((pass+1)); echo "PASS $3"; else fail=$((fail+1)); echo "FAIL $3: got [$1] want [$2]"; fi; }
TOTALS='PesaDonations\Models\Campaign_Totals::flush(); $out = []; foreach ( get_posts( [ "post_type" => "pd_campaign", "post_status" => "any", "numberposts" => -1, "orderby" => "ID", "order" => "ASC" ] ) as $p ) { $c = PesaDonations\Models\Campaign::get( $p->ID ); $out[] = $p->ID . ":" . $c->get_raised_amount() . ":" . $c->get_donor_count(); } echo md5( implode( "|", $out ) ) . " " . count( $out );'

/d/xampp/mysql/bin/mysqldump.exe -u root -h 127.0.0.1 -P 3399 pdwp_test > D:/pdtest/backup/pdwp_test-before-1.3.sql
echo "== on the 1.2.x code"
DEST=D:/pdwp/site/wp-content/plugins/pesa-donations
rm -rf "${DEST:?}"
git -C D:/xampp/htdocs/pesadonation archive --prefix=pesa-donations/ 68c8988 | tar -x -C D:/pdwp/site/wp-content/plugins
# Some gifts in other currencies, as 1.2 stored them (amount_base = amount, in the gift's currency).
SQL "UPDATE wp_pd_donations SET currency='USD' WHERE id % 97 = 0 AND status='completed'"
before=$($WP eval "$TOTALS" 2>/dev/null)
echo "   totals fingerprint: $before"
ok "$($WP option get pd_db_version 2>/dev/null)" "1.2.2" "starting schema 1.2.2"

echo "== upgrade to 1.3.0"
bash D:/pdtest/sync.sh >/dev/null
$WP eval 'PesaDonations\Core\Installer::maybe_upgrade();' >/dev/null 2>&1
ok "$($WP option get pd_db_version 2>/dev/null)" "1.3.0" "schema 1.3.0"
ok "$(SQL "SELECT COUNT(*) FROM wp_pd_donations WHERE base_currency IS NULL OR base_currency <> currency")" "0" "every existing gift: base_currency = its own currency"
after=$($WP eval "$TOTALS" 2>/dev/null)
ok "$after" "$before" "every campaign's raised amount and donor count unchanged"
ok "$($WP option get pd_enabled_currencies --format=json 2>/dev/null | tr -cd ',' | wc -c)" "22" "currency list migrated to the full 23"
ok "$($WP cron event list --fields=hook --format=csv 2>/dev/null | grep -c '^pd_refresh_exchange_rates$')" "1" "daily rates job scheduled"
$WP cron event run pd_refresh_exchange_rates >/dev/null 2>&1
ok "$($WP eval 'echo PesaDonations\Utils\Exchange_Rates::available() ? "yes" : "no";' 2>/dev/null)" "yes" "rates fetched by the job"
ok "$($WP eval 'echo count( PesaDonations\Utils\Exchange_Rates::for_codes( PesaDonations\Utils\Currencies::codes() ) );' 2>/dev/null)" "23" "a rate for every offered currency"
echo "passed $pass, failed $fail"
