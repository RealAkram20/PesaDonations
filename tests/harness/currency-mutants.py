import pathlib, subprocess
plug = pathlib.Path(r"D:/pdwp/site/wp-content/plugins/pesa-donations")
bash = r"C:/Program Files/Git/bin/bash.exe"

# Each must turn its check red (all four did on 2026-10-02).
mutants = [
    ("quote check removed", "includes/public/class-ajax-handler.php", "currency-check.sh",
     "if ( $quote->is_converted() ) {", "if ( false ) {"),
    ("silent fallback to the campaign currency", "includes/public/class-ajax-handler.php", "currency-check.sh",
     "if ( ! in_array( $currency, $choices, true ) ) {", "if ( ! in_array( $currency, $choices, true ) ) { $currency = $base_currency; } if ( false ) {"),
    ("stale rates accepted", "includes/utils/class-exchange-rates.php", "currency-check.sh",
     "if ( ! self::$memo || time() - (int) self::$memo['updated'] > self::MAX_AGE ) {", "if ( ! self::$memo ) {"),
    ("upgrade does not mark old gifts", "includes/core/class-installer.php", "upgrade-totals-check.sh",
     "SET base_currency = currency WHERE base_currency IS NULL", "SET base_currency = base_currency WHERE 0"),
]

for name, rel, script, a, b in mutants:
    subprocess.run([bash, "D:/pdtest/sync.sh"], capture_output=True)
    f = plug / rel
    orig = f.read_text(encoding="utf-8")
    assert orig.count(a) == 1, (name, orig.count(a))
    f.write_text(orig.replace(a, b), encoding="utf-8", newline="\n")
    if script == "upgrade-totals-check.sh":
        # The upgrade check syncs the real code itself; let it run the mutant instead.
        s = pathlib.Path(r"D:/pdtest/upgrade-totals-check.sh").read_text(encoding="utf-8")
        s = s.replace("bash D:/pdtest/sync.sh >/dev/null\n$WP eval 'PesaDonations\\Core\\Installer::maybe_upgrade();'",
                      "bash D:/pdtest/sync.sh >/dev/null\npython -c \"import pathlib; f=pathlib.Path(r'" + str(f).replace("\\", "/") + "'); t=f.read_text(encoding='utf-8'); f.write_text(t.replace(" + repr(a) + ", " + repr(b) + "), encoding='utf-8')\"\n$WP eval 'PesaDonations\\Core\\Installer::maybe_upgrade();'")
        pathlib.Path(r"D:/pdtest/upgrade-mutant.sh").write_text(s, encoding="utf-8", newline="\n")
        script = "upgrade-mutant.sh"
    out = subprocess.run([bash, "D:/pdtest/" + script], capture_output=True, text=True, encoding="utf-8", errors="replace").stdout
    fails = [l for l in out.splitlines() if l.startswith("FAIL")]
    print(f"{name}: {len(fails)} failing -> " + " | ".join(l[:90] for l in fails[:3]))
    f.write_text(orig, encoding="utf-8", newline="\n")
subprocess.run([bash, "D:/pdtest/sync.sh"], capture_output=True)
