<?php

// Tells a Nagios XI installation whose setup wizard has been completed from one
// that is still sitting on it. fullinstall leaves the product installed but not
// activated: the license key and the administrator account are entered in the
// browser, and finishing that wizard is what rewrites ssl.conf with the
// self-signed certificate the product ships. Anything that deploys a
// certificate before that point loses it.
//
// The web interface cannot be probed for this: an installation pending its
// wizard already redirects /nagiosxi/ to login.php, and install.php is compiled
// with SourceGuardian. The database is the only honest source.
//
// Credentials are read from the installation itself, never passed in.
// Prints OK and exits 0 when the wizard is done, PENDING and exits 3 when not.

$config = '/usr/local/nagiosxi/html/config.inc.php';

if (!is_readable($config)) {
    fwrite(STDERR, "cannot read {$config}\n");
    exit(1);
}

require $config;

$info = $cfg['db_info']['nagiosxi'];
$db = @new mysqli($info['dbserver'], $info['user'], $info['pwd'], $info['db']);

if ($db->connect_errno) {
    fwrite(STDERR, "database connection failed: {$db->connect_error}\n");
    exit(1);
}

// install_version is written when the web installer finishes; the key is what
// the activation step stores, trial or subscription.
$options = ['install_version' => '', 'enterprise_key' => '', 'trial_key' => ''];

$read = $db->prepare('SELECT name, value FROM xi_options WHERE name IN (?, ?, ?)');
$read->bind_param('sss', ...array_keys($options));
$read->execute();
$result = $read->get_result();

while ($row = $result->fetch_assoc()) {
    $options[$row['name']] = trim($row['value']);
}

$installed = $options['install_version'] !== '';
$licensed = $options['enterprise_key'] !== '' || $options['trial_key'] !== '';

if ($installed && $licensed) {
    $license = $options['enterprise_key'] !== '' ? 'subscription' : 'trial';
    echo "OK setup wizard completed, version {$options['install_version']}, {$license} license\n";
    exit(0);
}

$missing = [];
if (!$installed) {
    $missing[] = 'the web installer has not finished (no install_version)';
}
if (!$licensed) {
    $missing[] = 'the product has not been activated (no license key)';
}

echo 'PENDING ' . implode('; ', $missing) . "\n";
exit(3);
