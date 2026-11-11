#!/bin/sh
# Run on a staging Docker host from a synthetic, source-only checkout.
# No host ports, mail credentials, shared configuration, or external network.
set -eu
: "${AUTOMATED_EMAIL_TEST_IMAGE:?Set a trusted PHP test image with matching composer.lock}"
root=$(pwd -P)
test -f "$root/tests/Integration/AutomatedEmailSmtpTest.php"
test -f "$root/src/services/EmailPresentation.php"
name="pa-automated-email-$(date -u +%Y%m%d%H%M%S)-$$"
id=''
cleanup() {
    if [ -n "$id" ]; then
        actual=$(docker inspect "$id" --format '{{.Id}} {{.Name}} {{index .Config.Labels "task"}}')
        test "$actual" = "$id /$name $name"
        docker rm -f -v "$id" >/dev/null
    fi
}
trap cleanup EXIT HUP INT TERM
sha256sum composer.lock src/services/EmailPresentation.php src/services/EmailProviders.php \
    src/services/EmailProviderManager.php src/services/EmailService.php src/utils/mailer.php \
    tests/Integration/AutomatedEmailSmtpTest.php
id=$(docker create --name "$name" --label "task=$name" --network none --memory 512m --cpus 1 --pids-limit 64 \
    --mount "type=bind,source=$root,target=/source,readonly" \
    -e AUTOMATED_EMAIL_SMTP_ALLOW=isolated-loopback-only -e APP_SKIP_DB_CONFIG=1 \
    --entrypoint sh "$AUTOMATED_EMAIL_TEST_IMAGE" -c '
set -eu
cmp /source/composer.lock /var/www/composer.lock
test_root=$(mktemp -d /tmp/pa-automated-email.XXXXXXXX)
cp -a /source/src /source/tests /source/phpunit.xml "$test_root/"
cp -a /var/www/vendor "$test_root/vendor"
cd "$test_root"
exec php vendor/bin/phpunit tests/Integration/AutomatedEmailSmtpTest.php --do-not-cache-result --colors=never --fail-on-skipped --display-deprecations
')
docker start -a "$id"
test "$(docker inspect "$id" --format '{{.State.ExitCode}}')" = 0
docker inspect "$id" --format 'Image={{.Image}} Network={{.HostConfig.NetworkMode}} Memory={{.HostConfig.Memory}} Cpu={{.HostConfig.NanoCpus}} Pids={{.HostConfig.PidsLimit}}'
