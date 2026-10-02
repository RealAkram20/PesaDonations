#!/bin/bash
# Donor-chosen currencies through the real checkout endpoint (PesaPal answered by the test-site mock).
export MSYS_NO_PATHCONV=1
B=http://127.0.0.1:8099
AJ="$B/wp-admin/admin-ajax.php"
IPN="$B/wp-json/pesa-donations/v1/pesapal-ipn"
WP=D:/pdtest/wpx.sh
SQL() { /d/xampp/mysql/bin/mysql.exe -u root -h 127.0.0.1 -P 3399 pdwp_test -N -e "$1"; }
pass=0; fail=0
ok() { if [ "$1" = "$2" ]; then pass=$((pass+1)); echo "PASS $3"; else fail=$((fail+1)); echo "FAIL $3: got [$1] want [$2]"; fi; }
has() { if echo "$1" | grep -q -- "$2"; then pass=$((pass+1)); echo "PASS $3"; else fail=$((fail+1)); echo "FAIL $3: [$2] not in [$(echo "$1" | head -c 300)]"; fi; }
unlock() { SQL "DELETE FROM wp_options WHERE option_name LIKE '_transient%pd_pesapal_sync_%' OR option_name LIKE '_transient%pd_rl_%'"; }
php_eval() { $WP eval "$1" 2>/dev/null; }
RUN=$(date +%s)
CID=11
N=$(curl -s "$AJ?action=pd_nonce" | grep -o '"nonce":"[a-f0-9]*"' | cut -d'"' -f4)
give() { # email currency amount [quote] [campaign]
	local args=(--data-urlencode "action=pd_init_donation" --data-urlencode "nonce=$N" --data-urlencode "campaign_id=${5:-$CID}"
		--data-urlencode "amount=$3" --data-urlencode "currency=$2" --data-urlencode "gateway=pesapal"
		--data-urlencode "first_name=Test" --data-urlencode "last_name=Donor" --data-urlencode "email=$1" --data-urlencode "country=UG")
	[ -n "$4" ] && args+=(--data-urlencode "quote=$4")
	curl -s -o D:/pdtest/perf/cur.json -w "%{http_code}" "$AJ" "${args[@]}"
}
row() { SQL "SELECT $2 FROM wp_pd_donations WHERE donor_email='$1' ORDER BY id DESC LIMIT 1"; }
expect() { php_eval "\$q = PesaDonations\Payments\Charge_Quote::make( $1, '$2', '$3' ); echo is_wp_error( \$q ) ? 'ERR' : \$q->charge_amount() . ' ' . \$q->charge_currency() . ' ' . \$q->base_amount();"; }

echo "== setup"
for o in pd_mock_calls pd_mock_status pd_mock_amount_delta pd_mock_fail; do $WP option delete $o >/dev/null 2>&1; done
$WP option update pd_pesapal_environment sandbox >/dev/null; $WP option update pd_pesapal_consumer_key mockkey >/dev/null; $WP option update pd_pesapal_consumer_secret mocksecret >/dev/null
$WP option update pd_default_currency UGX >/dev/null; $WP option update pd_currency_choice 1 >/dev/null; $WP option update pd_charge_usd 1 >/dev/null
$WP post meta update $CID _pd_base_currency UGX >/dev/null; $WP post meta delete $CID _pd_single_currency >/dev/null 2>&1
$WP cron event run pd_refresh_exchange_rates >/dev/null 2>&1
php_eval 'PesaDonations\Models\Campaign_Totals::flush();'
unlock
raised0=$(php_eval 'echo PesaDonations\Models\Campaign::get(11)->get_raised_amount();')

echo "== euros on a UGX campaign: converted, charged in UGX"
E1=cur-eur-$RUN@example.com
read -r EXP_AMT EXP_CUR EXP_BASE <<< "$(expect 50 EUR UGX)"
echo "   server quote for 50 EUR: $EXP_AMT $EXP_CUR (counts $EXP_BASE UGX)"
ok "$(give $E1 EUR 50)" "409" "no quote sent: refused (409)"
has "$(cat D:/pdtest/perf/cur.json)" '"code":"quote"' "…says the charge changed and gives the new figure"
ok "$(give $E1 EUR 50 $((${EXP_AMT%.*} + 1000)))" "409" "a quote that differs from today's rate: refused"
ok "$(give $E1 EUR 50 ${EXP_AMT%.*})" "200" "the shown quote: accepted"
ok "$(row $E1 "CONCAT(amount*1e0,' ',currency,' | ',original_amount*1e0,' ',original_currency,' | ',amount_base*1e0,' ',base_currency)")" "${EXP_AMT%.*} UGX | 50 EUR | ${EXP_AMT%.*} UGX" "row: charged UGX, given 50 EUR, counted UGX"

echo "== dollars: charged as dollars, counted in UGX"
E2=cur-usd-$RUN@example.com
read -r U_AMT U_CUR U_BASE <<< "$(expect 20 USD UGX)"
ok "$(give $E2 USD 20)" "200" "USD needs no quote (not converted)"
ok "$(row $E2 "CONCAT(amount*1e0,' ',currency,' | ',IFNULL(original_currency,'-'),' | ',amount_base*1e0,' ',base_currency)")" "20 USD | - | $U_BASE UGX" "row: 20 USD charged, counted $U_BASE UGX"

echo "== refusals"
ok "$(give cur-x-$RUN@example.com XYZ 50000)" "422" "unknown currency refused (never silently charged as UGX)"
ok "$(give cur-min-$RUN@example.com EUR 0.5 1)" "422" "below the minimum in euros refused"
has "$(cat D:/pdtest/perf/cur.json)" "about" "…with the minimum in euros"

echo "== payment completes; the campaign's bar counts both gifts"
unlock
curl -s -o /dev/null "$IPN?OrderTrackingId=$(row $E1 order_tracking_id)&OrderMerchantReference=$(row $E1 merchant_reference)"
unlock
curl -s -o /dev/null "$IPN?OrderTrackingId=$(row $E2 order_tracking_id)&OrderMerchantReference=$(row $E2 merchant_reference)"
ok "$(row $E1 status)|$(row $E2 status)" "completed|completed" "both completed (PesaPal's amount and currency match the charge)"
raised1=$(php_eval 'PesaDonations\Models\Campaign_Totals::flush(); echo PesaDonations\Models\Campaign::get(11)->get_raised_amount();')
ok "$(/d/xampp/php/php.exe -r "echo round($raised1 - $raised0, 2);")" "$(/d/xampp/php/php.exe -r "echo round(${EXP_AMT} + ${U_BASE}, 2);")" "raised grew by exactly the two counted amounts"

echo "== switches"
$WP post meta update $CID _pd_single_currency 1 >/dev/null
ok "$(give cur-s-$RUN@example.com EUR 50 ${EXP_AMT%.*})" "422" "campaign set to its own currency only: euros refused"
$WP post meta delete $CID _pd_single_currency >/dev/null
$WP option update pd_currency_choice 0 >/dev/null
ok "$(give cur-g-$RUN@example.com EUR 50 ${EXP_AMT%.*})" "422" "currency choice off site-wide: euros refused"
ok "$(give cur-g2-$RUN@example.com UGX 50000)" "200" "…the campaign's own currency still works"
$WP option update pd_currency_choice 1 >/dev/null
$WP option update pd_charge_usd 0 >/dev/null
ok "$(give cur-u-$RUN@example.com USD 20)" "409" "USD charging off: dollars are converted (quote required)"
$WP option update pd_charge_usd 1 >/dev/null
$WP option update pd_enabled_currencies '["UGX","USD"]' --format=json >/dev/null
ok "$(give cur-e-$RUN@example.com EUR 50 ${EXP_AMT%.*})" "422" "euros removed from the offered list: refused"
$WP option update pd_enabled_currencies "$($WP eval 'echo wp_json_encode( PesaDonations\Utils\Currencies::codes() );')" --format=json >/dev/null
unlock

echo "== rates older than a week are not charged against"
$WP eval '$t = get_option( "pd_exchange_rates" ); $t["updated"] = time() - 8 * DAY_IN_SECONDS; update_option( "pd_exchange_rates", $t, false ); delete_transient( "pd_fx_retry" );' >/dev/null
ok "$(give cur-old-$RUN@example.com EUR 50 ${EXP_AMT%.*})" "422" "stale rates: euros refused"
ok "$(give cur-old2-$RUN@example.com UGX 50000)" "200" "stale rates: the campaign's own currency still works"
ok "$($WP cron event list --fields=hook --format=csv 2>/dev/null | grep -c '^pd_refresh_exchange_rates$')" "2" "stale rates: a catch-up refresh was scheduled"
$WP cron event run pd_refresh_exchange_rates >/dev/null 2>&1
unlock
ok "$(give cur-new-$RUN@example.com EUR 50 ${EXP_AMT%.*})" "200" "after the refresh: euros accepted again"

echo "== checkout page"
page=$(curl -s "$B/donation-checkout/?pd_cid=$CID")
has "$page" '<optgroup label="East Africa">' "currency picker grouped, East Africa first"
has "$page" 'value="EUR"' "euros offered"
has "$page" 'Rates By Exchange Rate API' "rate source credited"
echo "passed $pass, failed $fail"
