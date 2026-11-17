<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/TuyaLocalClient.php';
function streamCheck(bool $ok, string $message): void
{
    if (!$ok) { throw new RuntimeException($message); }
}
function streamEncode(TuyaLocalClient $c, int $cmd, string $data): string
{
    return (new ReflectionMethod($c, 'encodePacket'))->invoke($c, $cmd, $data);
}
function streamKey(TuyaLocalClient $c, string $key): void
{
    (new ReflectionProperty($c, 'sk'))->setValue($c, $key);
}
// Real crypto with a simulated peer. No network or actuator connection.
foreach (['3.3', '3.4', '3.5'] as $version) {
    $key = '0123456789abcdef';
    $c = new TuyaLocalClient('unused', 'test-device', $key, $version);
    $peer = new TuyaLocalClient('unused', 'test-device', $key, $version);
    streamKey($peer, $key);
    $start = $c->startStream();
    if ($version !== '3.3') {
        $ln = (new ReflectionProperty($c, 'nonce'))->getValue($c);
        $rn = 'remote-nonce-123';
        $response = streamEncode($peer, 4, pack('N', 0) . $rn . hash_hmac('sha256', $ln, $key, true));
        $part = $c->feedStream(substr($response, 0, 9));
        streamCheck(!$c->streamReady() && $part['packets'] === [], 'Wait for fragmented handshake');
        $part = $c->feedStream(substr($response, 9));
        streamCheck($c->streamReady() && count($part['packets']) === 1, 'Handshake completed');
        if ($version === '3.5') {
            $tag = '';
            $session = substr(openssl_encrypt($ln ^ $rn, 'aes-128-gcm', $key, OPENSSL_RAW_DATA, substr($ln, 0, 12), $tag), 0, 16);
        } else {
            $session = substr(openssl_encrypt($ln ^ $rn, 'aes-128-ecb', $key, OPENSSL_RAW_DATA), 0, 16);
        }
        streamKey($peer, $session);
    }
    streamCheck($c->streamReady(), 'Ready after start/handshake');
    // Restore in a different PHP object, just as a later Symcon invocation does.
    $restored = new TuyaLocalClient('unused', 'test-device', $key, $version);
    $restored->restoreStream($c->streamState());
    $first = pack('N', 0) . json_encode(['dps' => ['20' => true]]);
    $second = pack('N', 0) . json_encode(['protocol' => 4, 'data' => ['dps' => ['61' => 'AAEAFAABJgPoA+g=']]]);
    if ($version === '3.3') {
        $first = pack('N', 0) . '3.3' . str_repeat("\0", 12) . openssl_encrypt(substr($first, 4), 'aes-128-ecb', $key, OPENSSL_RAW_DATA);
        $second = pack('N', 0) . '3.3' . str_repeat("\0", 12) . openssl_encrypt(substr($second, 4), 'aes-128-ecb', $key, OPENSSL_RAW_DATA);
    }
    $frame1 = streamEncode($peer, 8, $first);
    $frame2 = streamEncode($peer, 8, $second);
    $a = $restored->feedStream(substr($frame1, 0, -7));
    streamCheck($a['reports'] === [], 'Partial report is buffered');
    $again = new TuyaLocalClient('unused', 'test-device', $key, $version);
    $again->restoreStream($restored->streamState());
    $b = $again->feedStream(substr($frame1, -7) . $frame2);
    streamCheck(count($b['reports']) === 2 && $b['reports'][0]['20'] === true
        && isset($b['reports'][1]['61']), 'Ordered flat and nested reports survive state restore');
    streamCheck(count($again->streamQuery([61])) === 2, 'Status query plus DP refresh');
    streamCheck(count($again->streamHeartbeat()) === 1, 'Keepalive');
    // Broken authentication must not reach the displayed state.
    $bad = $frame2;
    $bad[strlen($bad) - 8] = chr(ord($bad[strlen($bad) - 8]) ^ 1);
    try { $again->feedStream($bad); throw new RuntimeException('Corrupt frame accepted'); }
    catch (Exception $e) { streamCheck($e->getMessage() !== 'Corrupt frame accepted', 'Reject corrupt frame'); }
}
echo "Persistent stream tests passed for 3.3, 3.4 and 3.5\n";
