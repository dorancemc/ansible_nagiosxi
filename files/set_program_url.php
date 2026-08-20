<?php

// Sets the Nagios XI program url, the value the web interface builds its
// absolute redirects from. The installer leaves it as http://localhost/nagiosxi/
// when it runs unattended, which sends every browser to localhost.
//
// Credentials are read from the installation itself, never passed in.
// Prints CHANGED when it writes and OK when the value already matches.

$url = $argv[1] ?? '';

if ($url === '') {
    fwrite(STDERR, "usage: set_program_url.php <url>\n");
    exit(2);
}

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

$read = $db->prepare('SELECT value FROM xi_options WHERE name = ?');
$name = 'url';
$read->bind_param('s', $name);
$read->execute();
$current = $read->get_result()->fetch_assoc()['value'] ?? null;

if ($current === $url) {
    echo "OK {$url}\n";
    exit(0);
}

if ($current === null) {
    $write = $db->prepare('INSERT INTO xi_options (name, value) VALUES (?, ?)');
    $write->bind_param('ss', $name, $url);
} else {
    $write = $db->prepare('UPDATE xi_options SET value = ? WHERE name = ?');
    $write->bind_param('ss', $url, $name);
}

if (!$write->execute()) {
    fwrite(STDERR, "could not write the program url: {$db->error}\n");
    exit(1);
}

echo "CHANGED {$current} -> {$url}\n";
