<?php

// Process fixture: records the protocol, reads its actual private file, and never contacts Scalar.
$control = json_decode(file_get_contents(__DIR__.'/control.json'), true);
$args = array_slice($argv, 1);
$stage = implode(' ', array_slice($args, 0, 2));
$body = in_array($stage, ['document validate', 'registry publish'], true) ? file_get_contents($args[2]) : null;
file_put_contents(__DIR__.'/calls.jsonl', json_encode([
    'args' => $args,
    'body' => $body,
    'home' => getenv('HOME'),
    'artifactMode' => $body === null ? null : fileperms($args[2]) & 0777,
    'homeMode' => fileperms(getenv('HOME')) & 0777,
    'tokenPresent' => getenv('SCALAR_API_KEY') === 'personal-secret-test-token',
])."\n", FILE_APPEND | LOCK_EX);

if (($control['sleep'] ?? null) === $stage) {
    usleep(500000);
}
if (($control['failure'] ?? null) === $stage) {
    fwrite(STDERR, "personal-secret-test-token upstream-exchanged-access-token Authorization: Bearer not-configured-secret\n".($control['message'] ?? 'Invalid token 401'));
    exit(1);
}
if (($control['excessive'] ?? null) === $stage) {
    echo str_repeat('x', 1100000);
    exit(0);
}
if ($stage === '--version') {
    echo ($control['version'] ?? '2.1.0')."\n";
} elseif ($stage === 'auth login') {
    file_put_contents(getenv('HOME').'/.scalar-config', 'upstream-exchanged-access-token');
    echo "Successfully authenticated to Scalar CLI\n";
} elseif ($stage === 'registry list') {
    echo $control['listing'] ?? "No registry APIs found for namespace \"test-team\".\n";
} elseif ($stage === 'registry publish') {
    $version = $args[array_search('--version', $args, true) + 1];
    echo "Successfully published.\n".($control['url'] ?? 'https://registry.scalar.com/@test-team/apis/test-api@'.$version)."\n";
} else {
    echo "Document is valid.\n";
}
