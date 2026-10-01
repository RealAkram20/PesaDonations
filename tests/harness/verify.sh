#!/bin/bash
# Verification suite for the 1.2.0 bug-scan fixes, against the throwaway site.
# PesaPal is answered by the mu-plugin pd-test-pesapal-mock.php (test site only).
export MSYS_NO_PATHCONV=1
B=http://127.0.0.1:8099
AJ="$B/wp-admin/admin-ajax.php"
IPN="$B/wp-json/pesa-donations/v1/pesapal-ipn"
SQL() { /d/xampp/mysql/bin/mysql.exe -u root -h 127.0.0.1 -P 3399 pdwp_test -N -e "$1"; }
WP=D:/pdtest/wpx.sh
JAR=D:/pdtest/perf/verify-cookies.txt
pass=0; fail=0
ok() { if [ "$1" = "$2" ]; then pass=$((pass+1)); echo "PASS $3"; else fail=$((fail+1)); echo "FAIL $3: got [$1] want [$2]"; fi; }
has() { if echo "$1" | grep -q -- "$2"; then pass=$((pass+1)); echo "PASS $3"; else fail=$((fail+1)); echo "FAIL $3: [$2] not in [$(echo "$1" | head -c 300)]"; fi; }
hasnt() { if echo "$1" | grep -q -- "$2"; then fail=$((fail+1)); echo "FAIL $3: [$2] present"; else pass=$((pass+1)); echo "PASS $3"; fi; }
calls() { $WP option get pd_mock_calls --format=json 2>/dev/null | grep -o 'GetTransactionStatus' | wc -l | tr -d ' '; }
unlock() { SQL "DELETE FROM wp_options WHERE option_name LIKE '_transient%pd_pesapal_sync_%' OR option_name LIKE '_transient%pd_rl_%'"; }
nonce() { curl -s "$AJ?action=pd_nonce" | grep -o '"nonce":"[a-f0-9]*"' | cut -d'"' -f4; }
give() { # email amount [extra form args...]
	local email=$1 amount=$2; shift 2
	curl -s -o D:/pdtest/perf/give.json -w "%{http_code}" "$AJ" --data-urlencode "action=pd_init_donation" --data-urlencode "nonce=$N" \
		--data-urlencode "campaign_id=$CID" --data-urlencode "amount=$amount" --data-urlencode "currency=UGX" --data-urlencode "gateway=pesapal" \
		--data-urlencode "first_name=Test" --data-urlencode "last_name=Donor" --data-urlencode "email=$email" --data-urlencode "country=UG" "$@"
}
row() { SQL "SELECT $2 FROM wp_pd_donations WHERE donor_email='$1' ORDER BY id DESC LIMIT 1"; }

echo "== setup"
: > D:/pdwp/site/wp-content/debug.log 2>/dev/null
$WP option update pd_pesapal_environment sandbox >/dev/null
$WP option update pd_pesapal_consumer_key mockkey >/dev/null
$WP option update pd_pesapal_consumer_secret mocksecret >/dev/null
for o in pd_mock_calls pd_mock_status pd_mock_amount_delta pd_mock_fail; do $WP option delete $o >/dev/null 2>&1; done
SQL "DELETE FROM wp_pd_donations WHERE donor_email LIKE 'verify%@example.com'"
unlock
CID=11
RUN=$(date +%s)
N=$(nonce)
ok "$([ -n "$N" ] && echo yes)" "yes" "pd_nonce hands out a fresh token"

echo "== checkout"
code=$(curl -s -o D:/pdtest/perf/give.json -w "%{http_code}" "$AJ" -d "action=pd_init_donation&campaign_id=$CID&amount=5000")
ok "$code" "403" "no token: 403"
has "$(cat D:/pdtest/perf/give.json)" '"code":"nonce"' "no token: says code nonce (the checkout retries on it)"
ok "$(give verify-a-$RUN@example.com abc)" "422" "amount 'abc' refused"
ok "$(give verify-a-$RUN@example.com -500)" "422" "negative amount refused"
REF_SRC=$($WP option get pd_referral_sources --format=json | grep -o '"[^"]*"' | head -1 | tr -d '"')
E1=verify-1-$RUN@example.com
code=$(give $E1 "50,000" --data-urlencode "currency=USD" --data-urlencode "address1=Plot 5" --data-urlencode "city=Kampala" \
	--data-urlencode "how_heard=$REF_SRC" --data-urlencode "updates=1" --data-urlencode "is_org=1")
ok "$code" "200" "'50,000' accepted"
has "$(cat D:/pdtest/perf/give.json)" 'pay.example.test' "redirect URL from the gateway"
ok "$(row $E1 "CONCAT(status,'|',currency,'|',amount,'|',environment)")" "pending|UGX|50000.00|sandbox" "row: pending, base currency (USD tamper ignored), 50000, sandbox"
ok "$(row $E1 "CONCAT(referral_source,'|',wants_updates,'|',is_organization)")" "$REF_SRC|1|1" "row: referral, updates and organisation stored"
has "$(row $E1 donor_address)" 'Kampala' "row: address stored"
TRK=$(row $E1 order_tracking_id); REF=$(row $E1 merchant_reference)
ok "$(echo $TRK | cut -c1-4)" "trk-" "tracking id stored from SubmitOrder"

echo "== IPN"
ok "$(curl -s -o /dev/null -w "%{http_code}" "$IPN?OrderTrackingId=x&OrderMerchantReference=NOPE-$RUN&OrderNotificationType=IPNCHANGE")" "404" "unknown reference: 404"
before=$(calls)
ok "$(curl -s -o /dev/null -w "%{http_code}" "$IPN?OrderTrackingId=trk-forged&OrderMerchantReference=$REF&OrderNotificationType=IPNCHANGE")" "200" "foreign tracking id answered"
ok "$(calls)" "$before" "foreign tracking id: no call to PesaPal"
ok "$(row $E1 status)" "pending" "foreign tracking id: nothing changed"
ok "$(curl -s -o /dev/null -w "%{http_code}" "$IPN?OrderTrackingId=$TRK&OrderMerchantReference=$REF&OrderNotificationType=IPNCHANGE")" "200" "real IPN: 200"
ok "$(row $E1 "CONCAT(status,'|',payment_method)")" "completed|MTN Mobile Money" "real IPN: completed, method recorded"
after=$(calls)
curl -s -o /dev/null "$IPN?OrderTrackingId=$TRK&OrderMerchantReference=$REF&OrderNotificationType=IPNCHANGE"
ok "$(calls)" "$after" "second IPN inside 30 s: no second lookup"
unlock
curl -s -o /dev/null "$IPN?OrderTrackingId=$TRK&OrderMerchantReference=$REF&OrderNotificationType=IPNCHANGE"
ok "$(row $E1 status)" "completed" "repeat IPN after the lock: still completed (idempotent)"

E2=verify-2-$RUN@example.com
give $E2 20000 >/dev/null; unlock
$WP option update pd_mock_amount_delta 1000 >/dev/null
curl -s -o /dev/null "$IPN?OrderTrackingId=$(row $E2 order_tracking_id)&OrderMerchantReference=$(row $E2 merchant_reference)"
ok "$(row $E2 status)" "pending" "amount PesaPal reports differs: ignored"
$WP option delete pd_mock_amount_delta >/dev/null

E3=verify-3-$RUN@example.com
give $E3 30000 >/dev/null; unlock
$WP option update pd_mock_status 2 >/dev/null
curl -s -o /dev/null "$IPN?OrderTrackingId=$(row $E3 order_tracking_id)&OrderMerchantReference=$(row $E3 merchant_reference)"
ok "$(row $E3 status)" "failed" "status 2: failed"
unlock; $WP option update pd_mock_status 1 >/dev/null
curl -s -o /dev/null "$IPN?OrderTrackingId=$(row $E3 order_tracking_id)&OrderMerchantReference=$(row $E3 merchant_reference)"
ok "$(row $E3 status)" "completed" "then status 1: failed -> completed"

E4=verify-4-$RUN@example.com
give $E4 40000 >/dev/null; unlock
$WP option update pd_mock_fail 1 >/dev/null
ok "$(curl -s -o /dev/null -w "%{http_code}" "$IPN?OrderTrackingId=$(row $E4 order_tracking_id)&OrderMerchantReference=$(row $E4 merchant_reference)")" "500" "PesaPal unreachable: IPN answers 500 so it retries"
ok "$(row $E4 status)" "pending" "PesaPal unreachable: nothing changed"
$WP option delete pd_mock_fail >/dev/null

echo "== reconcile"
SQL "UPDATE wp_pd_donations SET created_at = DATE_SUB(NOW(), INTERVAL 20 MINUTE) WHERE donor_email='$E4'"
unlock
$WP eval '( new PesaDonations\Payments\Pesapal\Pesapal_IPN() )->reconcile();' >/dev/null
ok "$(row $E4 status)" "completed" "hourly reconcile completes a pending payment"

echo "== throttle"
unlock; N=$(nonce)
codes=""
for i in 1 2 3 4 5 6 7; do codes="$codes$(give verify-t-$RUN@example.com 5000)."; done
ok "$codes" "200.200.200.200.200.200.429." "7th attempt in 10 minutes: 429"
unlock

echo "== sandbox payments on a live site"
dev=$($WP eval 'PesaDonations\Models\Campaign_Totals::flush(); echo PesaDonations\Models\Campaign::get(11)->get_raised_amount();')
$WP option update pd_pesapal_environment production >/dev/null
live=$($WP eval 'PesaDonations\Models\Campaign_Totals::flush(); echo PesaDonations\Models\Campaign::get(11)->get_raised_amount();')
sand=$(SQL "SELECT COALESCE(SUM(amount),0) FROM wp_pd_donations WHERE campaign_id=11 AND status='completed' AND currency='UGX' AND environment='sandbox'")
ok "$(php -r "echo round($dev - $live, 2);" 2>/dev/null || /d/xampp/php/php.exe -r "echo round($dev - $live, 2);")" "$(/d/xampp/php/php.exe -r "echo round($sand, 2);")" "production totals leave out exactly the sandbox money"
$WP option update pd_pesapal_environment sandbox >/dev/null

echo "== admin"
rm -f "$JAR"
curl -s -o /dev/null -c "$JAR" "$B/wp-login.php"
curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "log=admin" --data-urlencode "pwd=$(cat D:/pdwp/.adminpass)" -d "wp-submit=Log+In&testcookie=1" "$B/wp-login.php"
page=$(curl -s -b "$JAR" "$B/wp-admin/admin.php?page=pd-donation-new")
DN=$(echo "$page" | grep -o 'name="pd_donation_nonce" value="[a-f0-9]*"' | cut -d'"' -f4)
EM=verify-m-$RUN@example.com
post() { curl -s -o D:/pdtest/perf/admin.html -D D:/pdtest/perf/admin.hdr -b "$JAR" "$@"; head -1 D:/pdtest/perf/admin.hdr | awk '{print $2}'; }
ok "$(post "$B/wp-admin/admin.php?page=pd-donation-new" --data-urlencode "pd_donation_nonce=$DN" --data-urlencode "campaign_id=$CID" \
	--data-urlencode "amount=12,500" --data-urlencode "currency=UGX" --data-urlencode "status=completed" --data-urlencode "gateway=manual" \
	--data-urlencode "donor_name=Manual Donor" --data-urlencode "donor_email=$EM" --data-urlencode "_wp_http_referer=/wp-admin/admin.php?page=pd-donation-new")" "302" "A1: manual donation saves and redirects (was: headers already sent)"
has "$(grep -i '^location' D:/pdtest/perf/admin.hdr)" "page=pd-donation-edit" "A1: redirect to the edit screen"
ok "$(SQL "SELECT CONCAT(COUNT(*),'|',MAX(status),'|',MAX(amount)) FROM wp_pd_donations WHERE donor_email='$EM'")" "1|completed|12500.00" "A1: exactly one row, completed, 12500"
ok "$(post "$B/wp-admin/admin.php?page=pd-donation-new" --data-urlencode "pd_donation_nonce=$DN" --data-urlencode "campaign_id=$CID" \
	--data-urlencode "amount=lots" --data-urlencode "currency=UGX" --data-urlencode "status=completed" --data-urlencode "donor_email=$EM" --data-urlencode "_wp_http_referer=/wp-admin/admin.php?page=pd-donation-new")" "200" "invalid amount: the form comes back"
has "$(cat D:/pdtest/perf/admin.html)" 'notice-error' "invalid amount: says why"
ok "$(SQL "SELECT COUNT(*) FROM wp_pd_donations WHERE donor_email='$EM'")" "1" "invalid amount: nothing written"

P1=$(row $E2 id); C1=$(row $E1 id)
list=$(curl -s -b "$JAR" "$B/wp-admin/admin.php?page=pd-donations")
BN=$(echo "$list" | grep -o 'name="_wpnonce" value="[a-f0-9]*"' | head -1 | cut -d'"' -f4)
post -G "$B/wp-admin/admin.php" --data-urlencode "page=pd-donations" --data-urlencode "action=mark_cancelled" \
	--data-urlencode "donation[]=$P1" --data-urlencode "donation[]=$C1" --data-urlencode "_wpnonce=$BN" --data-urlencode "_wp_http_referer=/wp-admin/admin.php?page=pd-donations" >/dev/null
has "$(grep -i '^location' D:/pdtest/perf/admin.hdr)" "pd_moved=1&pd_skipped=1" "bulk (GET form): pending moved, completed refused"
ok "$(row $E2 status)|$(row $E1 status)" "cancelled|completed" "bulk: statuses as the rules allow"

DID=$(SQL "SELECT id FROM wp_pd_donors WHERE email='$EM'")
dl=$(curl -s -b "$JAR" "$B/wp-admin/admin.php?page=pd-donors&s=$EM")
DEL=$(echo "$dl" | grep -o "action=delete&#038;id=$DID&#038;_wpnonce=[a-f0-9]*" | head -1 | sed 's/&#038;/\&/g')
post "$B/wp-admin/admin.php?page=pd-donors&$DEL" >/dev/null
has "$(grep -i '^location' D:/pdtest/perf/admin.hdr)" "pd_kept=1" "donor with donations: kept"
ok "$(SQL "SELECT COUNT(*) FROM wp_pd_donors WHERE id=$DID")" "1" "donor with donations: still there"

sp=$(curl -s -b "$JAR" "$B/wp-admin/admin.php?page=pd-settings&tab=pesapal")
hasnt "$sp" "mocksecret" "settings page source does not carry the stored secret"
has "$sp" 'pd-secret__eye' "secret field has the show/hide eye"
SN=$(echo "$sp" | grep -o 'name="pd_settings_nonce" value="[a-f0-9]*"' | cut -d'"' -f4)
post "$B/wp-admin/admin.php?page=pd-settings&tab=pesapal" --data-urlencode "pd_settings_nonce=$SN" --data-urlencode "pd_pesapal_environment=sandbox" \
	--data-urlencode "pd_pesapal_consumer_key=mockkey" --data-urlencode "pd_pesapal_consumer_secret=" --data-urlencode "_wp_http_referer=/wp-admin/admin.php?page=pd-settings&tab=pesapal" >/dev/null
ok "$($WP option get pd_pesapal_consumer_secret)" "mocksecret" "blank secret box keeps the stored secret"
old_from=$($WP option get pd_email_from_address)
post "$B/wp-admin/admin.php?page=pd-settings&tab=emails" --data-urlencode "pd_settings_nonce=$SN" --data-urlencode "pd_email_from_address=not-an-email" \
	--data-urlencode "_wp_http_referer=/wp-admin/admin.php?page=pd-settings&tab=emails" >/dev/null
ok "$($WP option get pd_email_from_address)" "$old_from" "invalid From email refused, previous kept"
has "$(curl -s -b "$JAR" "$(grep -i '^location' D:/pdtest/perf/admin.hdr | cut -d' ' -f2 | tr -d '\r')")" "not an email address" "…and the page says so"

echo "== campaign editor save"
out=$($WP eval-file D:/pdtest/verify-meta.php)
echo "$out" | sed 's/^/   /'
pass=$((pass + $(echo "$out" | grep -c '^ok'))); fail=$((fail + $(echo "$out" | grep -c '^FAIL')))

echo "== logs"
hasnt "$(cat D:/pdwp/site/wp-content/debug.log 2>/dev/null)" "headers already sent\|Fatal\|Warning" "debug.log: no fatals, warnings or header errors"

echo
echo "passed $pass, failed $fail"
