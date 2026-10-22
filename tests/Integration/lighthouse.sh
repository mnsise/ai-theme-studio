#!/usr/bin/env bash
# Builds the fixture site with the real pipeline in WordPress Playground (Elementor from
# wordpress.org, scripted AI provider), publishes it, and runs Lighthouse CI against it.
#
# Scores measured here reflect a PHP-WASM development server without page cache, CDN or
# HTTP/2 — Performance in particular is NOT representative of production hosting.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
CLI="${PLAYGROUND_CLI:-npx -y @wp-playground/cli@3.1.20}"
PORT="${ATS_PORT:-9400}"
URLS_FILE="${ROOT}/tests/Integration/.demo-urls.txt"
LOG="$(mktemp)"

rm -f "${URLS_FILE}"
$CLI server --port="${PORT}" --php=8.2 \
	--blueprint="${ROOT}/tests/Integration/blueprint-demo.json" \
	--mount-before-install="${ROOT}:/wordpress/wp-content/plugins/ai-theme-studio" > "${LOG}" 2>&1 &
SERVER=$!
trap 'kill ${SERVER} 2>/dev/null || true; pkill -f "wp-playground/cli.*--port=${PORT}" 2>/dev/null || true' EXIT

for _ in $(seq 1 120); do
	if [ -s "${URLS_FILE}" ] && [ "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${PORT}/")" = "200" ]; then
		break
	fi
	if ! kill -0 "${SERVER}" 2>/dev/null; then
		cat "${LOG}"
		echo "Playground server exited." >&2
		exit 1
	fi
	sleep 3
done
[ -s "${URLS_FILE}" ] || { cat "${LOG}"; echo "Demo site was not built." >&2; exit 1; }

export ATS_LH_URLS
ATS_LH_URLS="$(cat "${URLS_FILE}")"
echo "URLs: ${ATS_LH_URLS}"
cd "${ROOT}"
STATUS=0
npx -y @lhci/cli@0.15.1 autorun --config=./lighthouserc.cjs || STATUS=1
ATS_LH_PRESET=desktop npx -y @lhci/cli@0.15.1 autorun --config=./lighthouserc.cjs || STATUS=1
exit "${STATUS}"
