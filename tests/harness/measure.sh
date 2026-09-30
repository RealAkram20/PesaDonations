#!/bin/bash
# Usage: measure.sh <label>   -> D:\pdtest\perf\<label>.tsv and a printed table
# Hits each page cold (PesaDonations transients cleared) then warm, on the throwaway site.
LABEL="$1"
B=http://127.0.0.1:8099
LOG=/d/pdtest/perf/requests.log
MY="/d/xampp/mysql/bin/mysql.exe -u root -h 127.0.0.1 -P 3399 pdwp_test"
JAR=/d/pdtest/perf/cookies.txt
UUID=$($MY -N -e "SELECT uuid FROM wp_pd_donations WHERE status='completed' LIMIT 1")
CID=$($MY -N -e "SELECT ID FROM wp_posts WHERE post_type='pd_campaign' AND post_status='publish' ORDER BY ID LIMIT 20,1")

rm -f "$JAR"
curl -s -o /dev/null -c "$JAR" "$B/wp-login.php"
curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "log=admin" --data-urlencode "pwd=$(cat /d/pdwp/.adminpass)" -d "wp-submit=Log+In&testcookie=1" "$B/wp-login.php"

pages=(
  "public|/"
  "public|/give/"
  "public|/sponsor/"
  "public|/home-sliders/"
  "public|/donate/"
  "public|/donation-checkout/?pd_cid=$CID"
  "public|/donation-thank-you/?pd_d=$UUID"
  "admin|/dashboard/"
  "admin|/wp-admin/admin-ajax.php?action=pd_dashboard_data&range=12m&nonce=NONCE"
  "admin|/wp-admin/admin.php?page=pd-donations"
  "admin|/wp-admin/edit.php?post_type=pd_campaign"
  "admin|/wp-admin/admin.php?page=pd-donors"
)
NONCE=$(curl -s -b "$JAR" "$B/dashboard/" | grep -o '"nonce":"[a-f0-9]*"' | head -1 | cut -d'"' -f4)

out=/d/pdtest/perf/$LABEL.tsv
printf "page\tcold_ms\tcold_q\tcold_pd_q\twarm_ms\twarm_q\twarm_pd_q\thtml_kb\n" > "$out"
for entry in "${pages[@]}"; do
  kind=${entry%%|*}; path=${entry#*|}; path=${path/NONCE/$NONCE}
  auth=(); [ "$kind" = admin ] && auth=(-b "$JAR")
  $MY -e "DELETE FROM wp_options WHERE option_name LIKE '\_transient\_pd\_%' OR option_name LIKE '\_transient\_timeout\_pd\_%'"
  : > "$LOG"
  size=$(curl -s "${auth[@]}" -o /dev/null -w "%{size_download}" "$B$path")
  cold=$(tail -1 "$LOG")
  : > "$LOG"
  curl -s "${auth[@]}" -o /dev/null "$B$path"
  warm=$(tail -1 "$LOG")
  PAGE="$path" COLD="$cold" WARM="$warm" SIZE="$size" python - >> "$out" <<'PY'
import os, json
path, c, w, size = os.environ["PAGE"], json.loads(os.environ["COLD"]), json.loads(os.environ["WARM"]), int(os.environ["SIZE"])
short = path.split('&nonce')[0][:60]
print("\t".join(map(str, [short, c["ms"], c["queries"], c["pd_q"], w["ms"], w["queries"], w["pd_q"], round(size/1024, 1)])))
PY
done
column -t -s $'\t' "$out"
