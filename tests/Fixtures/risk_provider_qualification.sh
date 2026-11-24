#!/usr/bin/env bash
set -euo pipefail

# Run only within a newly created, otherwise empty network namespace.
[[ "$(ip -o link show | wc -l)" -eq 1 ]]
[[ -z "$(ip route show default)" ]]
ip link set lo up
ip address add 93.184.216.34/32 dev lo

root="$1"
python3 tests/Fixtures/risk_provider_peer.py "$root/leaf.pem" "$root/leaf.key" > "$root/peer.log" 2>&1 &
peer=$!
cleanup() {
    kill "$peer" 2>/dev/null || :
    wait "$peer" 2>/dev/null || :
}
trap cleanup EXIT
for ((attempt = 0; attempt < 100; ++attempt)); do
    if grep -qx ready "$root/peer.log"; then
        break
    fi
    kill -0 "$peer"
    sleep 0.05
done
grep -qx ready "$root/peer.log"
for mode in accept reject redirect oversize malformed wrong-host timeout cancel; do
    php -d "openssl.cafile=$root/ca.pem" tests/Fixtures/risk_provider_probe.php "$mode"
done
php -d "openssl.cafile=$root/untrusted.pem" tests/Fixtures/risk_provider_probe.php untrusted

# The good TLS peer received each expected application request exactly once.
for mode in accept reject redirect oversize malformed timeout cancel; do
    [[ "$(grep -Fxc "/$mode" "$root/peer.log")" -eq 1 ]]
done
if grep -q 'should-not-follow' "$root/peer.log"; then
    echo "Redirect was followed." >&2
    exit 1
fi
echo "Real TLS provider, scope, redirect, body bound, certificate, deadline and cancellation checks passed."
