<?php
declare(strict_types=1);
namespace TuyaLiveTests;

require_once __DIR__ . '/../libs/TuyaLocalClient.php';
const KR_READY = 10103;
const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_STRING = 3;
const KL_WARNING = 10203;
$runtime = ['parentStatus' => 102, 'parent' => 2, 'created' => 0, 'properties' => ['Host' => 'unused', 'Port' => 6668, 'Open' => false], 'applies' => 0, 'switches' => [], 'locked' => false];
function IPS_GetKernelRunlevel() { return KR_READY; }
function IPS_GetInstance($id) { global $runtime; return $id === 1 ? ['ConnectionID' => $runtime['parent']] : ['InstanceStatus' => $runtime['parentStatus'], 'ModuleInfo' => ['ModuleID' => '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}']]; }
function IPS_GetName($id) { return 'Test device'; }
function IPS_CreateInstance($guid) { global $runtime; $runtime['created']++; return 2; }
function IPS_SetName($id, $name) {}
function IPS_ConnectInstance($child, $parent) { global $runtime; $runtime['parent'] = $parent; }
function IPS_SemaphoreEnter($name, $timeout) { global $runtime; if ($runtime['locked']) { return false; } return $runtime['locked'] = true; }
function IPS_SemaphoreLeave($name) { global $runtime; $runtime['locked'] = false; }
function IPS_GetProperty($id, $name) { global $runtime; return $runtime['properties'][$name]; }
function IPS_SetProperty($id, $name, $value) { global $runtime; $runtime['switches'][] = [$name, $value]; $runtime['properties'][$name] = $value; }
function IPS_ApplyChanges($id) { global $runtime; $runtime['applies']++; }

class ModuleBase
{
    public int $InstanceID = 1;
    public array $properties = ['LiveMode' => true, 'Version' => '3.3', 'Host' => 'unused', 'DeviceID' => 'test', 'LocalKey' => '0123456789abcdef', 'LiveRefreshInterval' => 300, 'RGBBrightnessDP24' => true];
    public array $attributes = ['KnownDPs' => '[]', 'LastColourData' => '000003e803e8'];
    public array $buffers = [];
    public array $values = [];
    public array $packets = [];
    public int $status = 104;
    public array $debug = [];
    protected function ReadPropertyString($n) { return $this->properties[$n] ?? ''; }
    protected function ReadPropertyInteger($n) { return $this->properties[$n] ?? 0; }
    protected function ReadPropertyBoolean($n) { return $this->properties[$n] ?? false; }
    protected function ReadAttributeString($n) { return $this->attributes[$n] ?? ''; }
    protected function WriteAttributeString($n, $v) { $this->attributes[$n] = $v; }
    protected function GetBuffer($n) { return $this->buffers[$n] ?? ''; }
    protected function SetBuffer($n, $v) { $this->buffers[$n] = $v; }
    protected function SetStatus($s) { $this->status = $s; }
    protected function GetStatus() { return $this->status; }
    protected function SetValue($n, $v) { $this->values[$n] = $v; }
    protected function GetValue($n) { return $this->values[$n] ?? null; }
    protected function GetIDForIdent($n) { return 1; }
    protected function MaintainVariable(...$a) {}
    protected function EnableAction(...$a) {}
    protected function SendDataToParent($json) { $this->packets[] = json_decode($json, true); }
    protected function SendDebug(...$a) { $this->debug[] = $a; }
    protected function LogMessage(...$a) {}
}
$source = file_get_contents(__DIR__ . '/../TuyaDevice/module.php');
$source = substr($source, strpos($source, 'class TuyaDevice extends IPSModule'));
$source = str_replace('class TuyaDevice extends IPSModule', 'class ModuleUnderTest extends ModuleBase', $source);
eval('namespace TuyaLiveTests; use \\TuyaLocalClient; use \\Throwable; use \\Exception; ' . $source);
function checkLive($ok, $message) { if (!$ok) { throw new \RuntimeException($message); } }
function reportFrame(array $dps): string
{
    $json = json_encode(['dps' => $dps]);
    $payload = pack('N', 0) . '3.3' . str_repeat("\0", 12) . openssl_encrypt($json, 'aes-128-ecb', '0123456789abcdef', OPENSSL_RAW_DATA);
    $body = pack('NNNN', 0x55AA, 1, 8, strlen($payload) + 8) . $payload;
    return $body . pack('N', crc32($body)) . pack('N', 0xAA55);
}
$m = new ModuleUnderTest();
checkLive((new \ReflectionMethod($m, 'ensureClientSocket'))->invoke($m) === 2, 'Existing native socket is reused');
$runtime['parent'] = 0;
(new \ReflectionMethod($m, 'ensureClientSocket'))->invoke($m);
checkLive($runtime['parent'] === 2 && $runtime['created'] === 1
    && $runtime['switches'] === [['Open', false], ['Host', 'unused'], ['Port', 6668]], 'Legacy instance receives its own closed socket');
(new \ReflectionMethod($m, 'ensureClientSocket'))->invoke($m);
checkLive($runtime['created'] === 1, 'Apply does not duplicate native sockets');
$runtime['switches'] = [];
$configure = new \ReflectionMethod($m, 'configureClientSocket');
$configure->invoke($m, true);
checkLive($runtime['properties']['Open'] === true, 'Enabling live opens the existing native socket');
$applies = $runtime['applies'];
$configure->invoke($m, true);
checkLive($runtime['applies'] === $applies, 'Stable configuration does not reconnect');
$configure->invoke($m, false);
checkLive($runtime['properties']['Open'] === false, 'Disabling live closes the existing native socket');
$runtime['switches'] = [];
$parentConfig = json_decode($m->GetConfigurationForParent(), true);
checkLive($parentConfig['Open'] === true && $parentConfig['Port'] === 6668, 'Native socket configuration in live mode');
$m->properties['LiveMode'] = false;
checkLive(json_decode($m->GetConfigurationForParent(), true)['Open'] === false, 'Polling keeps native socket closed');
$m->properties['LiveMode'] = true;
$m->LiveTick();
checkLive($m->values['Available'] === false, 'An open socket without a device report is not available');
checkLive(count($m->packets) === 2, 'Initial query and heartbeat: ' . json_encode($m->debug));
$frame = reportFrame(['20' => true, '24' => '007803e80384']);
$m->ReceiveData(json_encode(['DataID' => '{018EF6B5-AB94-40C6-AA53-46943E824ACF}', 'Buffer' => mb_convert_encoding($frame, 'UTF-8', 'ISO-8859-1')]));
checkLive($m->values['Available'] === true, 'A confirmed report marks the device available');
checkLive($m->status === 102 && $m->values['Status'] === true && $m->values['Helligkeit'] === 90, 'Incoming report updates variables');
$count = count($m->packets);
$m->LiveTick();
checkLive(count($m->packets) === $count, 'No polling every tick');
$m->RequestAction('Helligkeit', 50);
checkLive($m->values['Helligkeit'] === 90 && count($m->packets) === $count + 1, 'Command uses same connection without optimistic values');
$m->buffers['LiveRx'] = (string) (time() - 60);
$m->LiveTick();
checkLive($m->values['Available'] === false, 'Response timeout clears availability');
checkLive($m->buffers['LiveState'] === '' && $runtime['switches'] === [['Open', false], ['Open', true]], 'Timeout resets native connection');
$count = count($m->packets);
$m->LiveTick();
checkLive(count($m->packets) === $count, 'Retry backoff');
$runtime['parentStatus'] = 200;
$m->buffers['LiveRetry'] = '0';
$m->LiveTick();
checkLive($m->values['Available'] === false, 'Disconnected parent remains unavailable');
$runtime['parentStatus'] = 102;
$m->buffers['LiveRetry'] = '0';
$m->LiveTick();
$m->ReceiveData(json_encode(['DataID' => '{018EF6B5-AB94-40C6-AA53-46943E824ACF}', 'Buffer' => mb_convert_encoding(reportFrame(['20' => true]), 'UTF-8', 'ISO-8859-1')]));
checkLive($m->values['Available'] === true && $m->status === 102, 'Device report restores availability after reconnect');
$runtime['parentStatus'] = 200;
$m->LiveTick();
checkLive($m->status === 200, 'Disconnected parent is not reported as connected');
echo "Live module adapter tests passed\n";
