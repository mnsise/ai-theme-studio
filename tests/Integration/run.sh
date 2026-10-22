#!/usr/bin/env bash
# Runs the integration suite in WordPress Playground (real WordPress + SQLite),
# once with Elementor installed from wordpress.org and once without it.
#   ATS_PHP=8.0 ./tests/Integration/run.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
CLI="${PLAYGROUND_CLI:-npx -y @wp-playground/cli@3.1.20}"
PHP_VERSION="${ATS_PHP:-8.2}"
TMP="$(mktemp -d)"
trap 'rm -rf "${TMP}"' EXIT
STATUS=0

for MODE in elementor no-elementor; do
	echo "################ ${MODE} (PHP ${PHP_VERSION})"
	# The blueprint's preferredVersions wins over --php, so substitute the version there.
	sed "s/\"php\": \"[0-9.]*\"/\"php\": \"${PHP_VERSION}\"/" "${ROOT}/tests/Integration/blueprint-${MODE}.json" > "${TMP}/${MODE}.json"
	$CLI php \
		--php="${PHP_VERSION}" \
		--blueprint="${TMP}/${MODE}.json" \
		--mount-before-install="${ROOT}:/wordpress/wp-content/plugins/ai-theme-studio" \
		-- /wordpress/wp-content/plugins/ai-theme-studio/tests/Integration/run.php || STATUS=1
done

exit "${STATUS}"
