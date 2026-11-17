<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/TuyaLocalClient.php';

/**
 * Tuya-Gerät, lokal gesteuert (ohne Cloud).
 * Unterstützt Leuchten (Kategorie-unabhängig, DP 20–24) und Schalter/Steckdosen (DP 1–9 bool).
 */
class TuyaDevice extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('DeviceID', '');
        $this->RegisterPropertyString('LocalKey', '');
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyString('MAC', '');
        $this->RegisterPropertyString('Version', 'auto');
        $this->RegisterPropertyInteger('Interval', 30);
        $this->RegisterPropertyBoolean('LiveMode', false);
        $this->RegisterPropertyInteger('LiveRefreshInterval', 300);
        $this->RegisterPropertyInteger('CTMin', 2700);
        $this->RegisterPropertyInteger('CTMax', 6500);
        $this->RegisterPropertyBoolean('OfflineAsOff', true);
        $this->RegisterPropertyBoolean('RGBBrightnessDP24', false);
        $this->RegisterPropertyBoolean('SyncPaintDP61', false);

        $this->RegisterAttributeString('DetectedVersion', '');
        $this->RegisterAttributeString('KnownDPs', '[]');
        $this->RegisterAttributeString('LastColourData', '');
        $this->RegisterAttributeString('LastPaintData', '');

        $this->RegisterTimer('Poll', 0, 'TUYA_Update($_IPS["TARGET"]);');
        $this->RegisterTimer('Live', 0, 'TUYA_LiveTick($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetTimerInterval('Live', 0);
        $this->SetTimerInterval('Poll', 0);
        $this->SetBuffer('LiveState', '');
        $this->SetBuffer('LiveRx', '0');
        $this->SetBuffer('LiveStarted', '0');

        if (!IPS_VariableProfileExists('Tuya.ColorTemp')) {
            IPS_CreateVariableProfile('Tuya.ColorTemp', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('Tuya.ColorTemp', '', ' K');
            IPS_SetVariableProfileIcon('Tuya.ColorTemp', 'Bulb');
        }
        IPS_SetVariableProfileValues('Tuya.ColorTemp', $this->ReadPropertyInteger('CTMin'), $this->ReadPropertyInteger('CTMax'), 100);

        $this->WriteAttributeString('DetectedVersion', '');
        $ok = $this->ReadPropertyString('DeviceID') !== '' && strlen($this->ReadPropertyString('LocalKey')) === 16;
        if (!$ok) {
            $this->SetStatus(201);
            $this->SetTimerInterval('Poll', 0);
            return;
        }
        $this->SetSummary($this->host());
        if ($this->ReadPropertyBoolean('LiveMode')) {
            // Determine auto-version before opening the persistent parent connection.
            if ($this->ReadPropertyString('Version') === 'auto') {
                $parent = IPS_GetInstance($this->InstanceID)['ConnectionID'];
                if ($parent > 0 && IPS_GetProperty($parent, 'Open')) {
                    IPS_SetProperty($parent, 'Open', false);
                    IPS_ApplyChanges($parent);
                }
                try {
                    $this->client();
                } catch (Throwable $e) {
                    $this->offline($e->getMessage());
                    $this->SetTimerInterval('Live', 5000);
                    return;
                }
            }
            $this->ConnectParent('{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}');
            $this->SetTimerInterval('Live', 1000);
            if (IPS_GetKernelRunlevel() === KR_READY) {
                $this->LiveTick();
            }
            return;
        }
        $parent = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parent > 0 && IPS_GetProperty($parent, 'Open')) {
            IPS_SetProperty($parent, 'Open', false);
            IPS_ApplyChanges($parent);
        }
        $this->SetTimerInterval('Poll', max(5, $this->ReadPropertyInteger('Interval')) * 1000);
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->Update();
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $version = $this->ReadPropertyString('Version');
        if ($version === 'auto') {
            $version = $this->ReadAttributeString('DetectedVersion') ?: 'noch nicht erkannt (automatisch)';
        }
        $form['actions'][] = ['type' => 'Label', 'caption' => 'Protokoll: ' . $version . '   |   Datenpunkte: ' . $this->ReadAttributeString('KnownDPs')];
        return json_encode($form);
    }

    /** Status vom Gerät holen und Variablen aktualisieren */
    public function Update(): bool
    {
        if ($this->ReadPropertyBoolean('LiveMode')) {
            return $this->liveOperation('query');
        }
        try {
            $dps = $this->client()->query($this->ReadPropertyBoolean('SyncPaintDP61') ? [61] : []);
            $this->apply($dps);
            $this->SetStatus(102);
            return true;
        } catch (Throwable $e) {
            $this->offline($e->getMessage());
            return false;
        }
    }

    /** Rohe Datenpunkte setzen, z. B. TUYA_SetDPs(12345, ['20' => true]); */
    public function SetDPs(array $dps): bool
    {
        if ($this->ReadPropertyBoolean('LiveMode')) {
            // Values change only when confirmed by a received device report.
            return $this->liveOperation('control', $dps);
        }
        try {
            $res = $this->client()->set($dps);
            $this->apply($res + $dps);
            $this->SetStatus(102);
            return true;
        } catch (Throwable $e) {
            $this->offline($e->getMessage());
            return false;
        }
    }

    public function RequestAction($Ident, $Value)
    {
        $min = $this->ReadPropertyInteger('CTMin');
        $max = $this->ReadPropertyInteger('CTMax');
        switch ($Ident) {
            case 'Status':
                $dps = ['20' => (bool) $Value];
                break;
            case 'Helligkeit':
                if ($this->ReadPropertyBoolean('RGBBrightnessDP24')) {
                    if ($Value <= 0) {
                        $dps = ['20' => false];
                    } else {
                        $hsv = $this->ReadAttributeString('LastColourData');
                        if (!preg_match('/^[0-9a-f]{12}$/i', $hsv)) {
                            throw new Exception('Zuerst einen gültigen Farbstatus vom Gerät abfragen');
                        }
                        $dps = $this->colourCommand(substr($hsv, 0, 8) . sprintf('%04x', max(10, min(1000, (int) $Value * 10))));
                    }
                } else {
                    $dps = $Value <= 0 ? ['20' => false] : ['20' => true, '21' => 'white', '22' => max(10, min(1000, (int) $Value * 10))];
                }
                break;
            case 'Farbtemperatur':
                $dps = ['21' => 'white', '23' => (int) round(($Value - $min) / max(1, $max - $min) * 1000)];
                break;
            case 'Farbe':
                $colour = $this->rgbToTuya((int) $Value);
                if ($this->ReadPropertyBoolean('RGBBrightnessDP24')) {
                    $previous = $this->ReadAttributeString('LastColourData');
                    if (!preg_match('/^[0-9a-f]{12}$/i', $previous)) {
                        throw new Exception('Zuerst einen gültigen Farbstatus vom Gerät abfragen');
                    }
                    $colour = substr($colour, 0, 8) . substr($previous, 8, 4);
                }
                $dps = $this->colourCommand($colour);
                break;
            case 'Update':
                $this->Update();
                return;
            default:
                if (preg_match('/^Switch(\d+)$/', $Ident, $m)) {
                    $dps = [$m[1] => (bool) $Value];
                    break;
                }
                throw new Exception('Unbekannter Ident ' . $Ident);
        }
        if (!$this->SetDPs($dps)) {
            throw new Exception('Tuya-Gerät nicht erreichbar');
        }
        if (!$this->ReadPropertyBoolean('LiveMode') && $Ident === 'Helligkeit' && $Value > 0) {
            $this->SetValue('Helligkeit', (int) $Value);
        }
    }

    // ---------- intern ----------

    public function GetConfigurationForParent()
    {
        return json_encode(['Host' => $this->host(), 'Port' => 6668,
            'Open' => $this->ReadPropertyBoolean('LiveMode')
                && $this->host() !== '' && $this->ReadPropertyString('DeviceID') !== ''
                && strlen($this->ReadPropertyString('LocalKey')) === 16]);
    }

    /** One-second watchdog; incoming reports themselves are processed immediately. */
    public function LiveTick(): void
    {
        if (!$this->ReadPropertyBoolean('LiveMode') || IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        if ($this->ReadPropertyString('Version') === 'auto' && $this->ReadAttributeString('DetectedVersion') === '') {
            $this->ApplyChanges();
            return;
        }
        if (time() < (int) $this->GetBuffer('LiveRetry')) {
            return;
        }
        $this->liveOperation('tick');
    }

    public function ReceiveData($JSONString)
    {
        if (!$this->ReadPropertyBoolean('LiveMode')) {
            return;
        }
        $data = json_decode($JSONString, true);
        if (($data['DataID'] ?? '') !== '{018EF6B5-AB94-40C6-AA53-46943E824ACF}' || !isset($data['Buffer'])) {
            return;
        }
        $bytes = mb_convert_encoding($data['Buffer'], 'ISO-8859-1', 'UTF-8');
        $this->liveOperation('receive', [], $bytes);
    }

    private function liveOperation(string $operation, array $dps = [], string $bytes = ''): bool
    {
        $lock = 'TuyaLive' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 2000)) {
            return false;
        }
        try {
            $parent = IPS_GetInstance($this->InstanceID)['ConnectionID'];
            if ($parent === 0) {
                $this->ConnectParent('{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}');
                return false;
            }
            if (IPS_GetInstance($parent)['InstanceStatus'] !== 102) {
                $this->SetBuffer('LiveState', '');
                $this->SetBuffer('LiveStarted', '0');
                $this->offline('Live-Verbindung unterbrochen');
                return false;
            }
            $c = $this->client();
            $state = $this->GetBuffer('LiveState');
            $packets = [];
            if ($state === '') {
                $packets = $c->startStream();
                $this->SetBuffer('LiveStarted', (string) time());
                $this->SetBuffer('LiveReport', '0');
                $this->SetBuffer('LiveRx', (string) time());
                $this->SetStatus(104);
                if ($c->streamReady()) {
                    $packets = array_merge($packets, $c->streamQuery($this->ReadPropertyBoolean('SyncPaintDP61') ? [61] : []));
                    $this->SetBuffer('LiveQuery', (string) time());
                }
            } else {
                $c->restoreStream($state);
            }
            if ($operation === 'receive') {
                $ready = $c->streamReady();
                $result = $c->feedStream($bytes);
                $packets = array_merge($packets, $result['packets']);
                if ($result['received']) {
                    $this->SetBuffer('LiveRx', (string) time());
                }
                if (!$ready && $c->streamReady()) {
                    $packets = array_merge($packets, $c->streamQuery($this->ReadPropertyBoolean('SyncPaintDP61') ? [61] : []));
                    $this->SetBuffer('LiveQuery', (string) time());
                }
                foreach ($result['reports'] as $report) {
                    $this->apply($report);
                    $this->SetBuffer('LiveReport', '1');
                    $this->SetStatus(102);
                }
            } elseif ($c->streamReady() && $operation === 'control') {
                $packets = array_merge($packets, $c->streamControl($dps));
            } elseif ($c->streamReady() && ($operation === 'query'
                || time() - (int) $this->GetBuffer('LiveQuery') >= max(30, $this->ReadPropertyInteger('LiveRefreshInterval')))) {
                $packets = array_merge($packets, $c->streamQuery($this->ReadPropertyBoolean('SyncPaintDP61') ? [61] : []));
                $this->SetBuffer('LiveQuery', (string) time());
            }
            if ($operation === 'tick' && (($c->streamReady() && time() - (int) $this->GetBuffer('LiveRx') > 45)
                || ((!$c->streamReady() || $this->GetBuffer('LiveReport') !== '1')
                    && time() - (int) $this->GetBuffer('LiveStarted') > 10))) {
                throw new Exception('Live-Verbindung: Antwortzeit überschritten');
            }
            if ($c->streamReady() && time() - (int) $this->GetBuffer('LiveHeartbeat') >= 10) {
                $packets = array_merge($packets, $c->streamHeartbeat());
                $this->SetBuffer('LiveHeartbeat', (string) time());
            }
            // Save protocol state before sending: a response can arrive immediately.
            $this->SetBuffer('LiveState', $c->streamState());
            foreach ($packets as $packet) {
                $this->SendDataToParent(json_encode(['DataID' => '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}',
                    'Buffer' => mb_convert_encoding($packet, 'UTF-8', 'ISO-8859-1')]));
            }
            return $operation !== 'control' || $c->streamReady();
        } catch (Throwable $e) {
            $this->SetBuffer('LiveState', '');
            $this->SetBuffer('LiveRetry', (string) (time() + 5));
            $this->offline($e->getMessage());
            $parent = IPS_GetInstance($this->InstanceID)['ConnectionID'];
            if ($parent > 0) {
                IPS_SetProperty($parent, 'Open', false);
                IPS_ApplyChanges($parent);
                IPS_SetProperty($parent, 'Open', true);
                IPS_ApplyChanges($parent);
            }
            return false;
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    private function client(): TuyaLocalClient
    {
        $id = $this->ReadPropertyString('DeviceID');
        $key = $this->ReadPropertyString('LocalKey');
        $host = $this->host();
        if ($host === '') {
            throw new Exception('IP-Adresse unbekannt');
        }
        $v = $this->ReadPropertyString('Version');
        if ($v === 'auto') {
            $v = $this->ReadAttributeString('DetectedVersion');
            if ($v === '') {
                $v = TuyaLocalClient::detect($host, $id, $key);
                $this->WriteAttributeString('DetectedVersion', $v);
            }
        }
        return new TuyaLocalClient($host, $id, $key, $v);
    }

    /** IP aus Eigenschaft, sonst per MAC aus der ARP-Tabelle */
    private function host(): string
    {
        $h = trim($this->ReadPropertyString('Host'));
        if ($h !== '') {
            return $h;
        }
        $mac = strtolower(preg_replace('/[^0-9a-f]/i', '', $this->ReadPropertyString('MAC')));
        if (strlen($mac) !== 12) {
            return '';
        }
        $arp = (string) @shell_exec('arp -a');
        foreach (explode("\n", $arp) as $l) {
            if (preg_match('/(\d+\.\d+\.\d+\.\d+)\s+([0-9a-f]{2}[-:](?:[0-9a-f]{2}[-:]){4}[0-9a-f]{2})/i', $l, $m)
                && strtolower(preg_replace('/[^0-9a-f]/i', '', $m[2])) === $mac) {
                return $m[1];
            }
        }
        return '';
    }

    private function apply(array $dps): void
    {
        $paint = null;
        if ($this->ReadPropertyBoolean('SyncPaintDP61')) {
            if (isset($dps['61']) && is_string($dps['61'])) {
                $paint = $this->decodePaintColour($dps['61']);
                if ($paint !== null) {
                    $this->WriteAttributeString('LastPaintData', $dps['61']);
                }
            }
            // Never overwrite the App's paint selection with the stale DP24 value.
            unset($dps['24']);
            if ($paint !== null) {
                $dps['24'] = $paint['hsv'];
            }
        }
        $known = json_decode($this->ReadAttributeString('KnownDPs'), true) ?: [];
        $new = array_values(array_unique(array_merge($known, array_map('strval', array_keys($dps)))));
        sort($new);
        if ($new !== $known) {
            $this->WriteAttributeString('KnownDPs', json_encode($new));
        }
        $min = $this->ReadPropertyInteger('CTMin');
        $max = $this->ReadPropertyInteger('CTMax');
        $isLight = in_array('20', $new, true);

        if ($isLight) {
            $this->MaintainVariable('Status', 'Status', VARIABLETYPE_BOOLEAN, '~Switch', 1, true);
            $this->EnableAction('Status');
            if (in_array('22', $new, true) || ($this->ReadPropertyBoolean('RGBBrightnessDP24') && in_array('24', $new, true))) {
                $this->MaintainVariable('Helligkeit', 'Helligkeit', VARIABLETYPE_INTEGER, '~Intensity.100', 2, true);
                $this->EnableAction('Helligkeit');
            }
            if (in_array('23', $new, true)) {
                $this->MaintainVariable('Farbtemperatur', 'Farbtemperatur', VARIABLETYPE_INTEGER, 'Tuya.ColorTemp', 3, true);
                $this->EnableAction('Farbtemperatur');
            }
            if (in_array('24', $new, true)) {
                $this->MaintainVariable('Farbe', 'Farbe', VARIABLETYPE_INTEGER, '~HexColor', 4, true);
                $this->EnableAction('Farbe');
            }
            if (isset($dps['20'])) {
                $this->setIfChanged('Status', (bool) $dps['20']);
            }
            if (!$this->ReadPropertyBoolean('RGBBrightnessDP24') && isset($dps['22']) && @$this->GetIDForIdent('Helligkeit')) {
                $this->setIfChanged('Helligkeit', (int) round($dps['22'] / 10));
            }
            if (isset($dps['23']) && @$this->GetIDForIdent('Farbtemperatur')) {
                $this->setIfChanged('Farbtemperatur', (int) (round(($min + $dps['23'] / 1000 * ($max - $min)) / 100) * 100));
            }
            if (isset($dps['24']) && @$this->GetIDForIdent('Farbe') && is_string($dps['24']) && preg_match('/^[0-9a-f]{12}$/i', $dps['24'])) {
                $this->WriteAttributeString('LastColourData', $dps['24']);
                if ($this->ReadPropertyBoolean('RGBBrightnessDP24')) {
                    $this->setIfChanged('Helligkeit', (int) round(hexdec(substr($dps['24'], 8, 4)) / 10));
                }
                $this->setIfChanged('Farbe', $this->tuyaToRgb($dps['24']));
            }
            return;
        }

        // Schalter / Steckdosen: boolesche DPs 1–9
        foreach ($dps as $dp => $val) {
            if ((int) $dp >= 1 && (int) $dp <= 9 && is_bool($val)) {
                $ident = 'Switch' . $dp;
                $this->MaintainVariable($ident, count(array_filter($new, fn ($d) => (int) $d >= 1 && (int) $d <= 9)) > 1 ? 'Schalter ' . $dp : 'Status', VARIABLETYPE_BOOLEAN, '~Switch', (int) $dp, true);
                $this->EnableAction($ident);
                $this->setIfChanged($ident, $val);
            }
        }
    }

    /** Normal colour controls address the entire strip, never just the last segment. */
    private function colourCommand(string $hsv): array
    {
        if (!$this->ReadPropertyBoolean('SyncPaintDP61')) {
            return ['20' => true, '21' => 'colour', '24' => $hsv];
        }
        $encoded = $this->ReadAttributeString('LastPaintData');
        if ($this->decodePaintColour($encoded) === null) {
            throw new Exception('Zuerst einen gültigen DP61-Farbstatus vom Gerät abfragen');
        }
        $raw = base64_decode($encoded, true);
        $paint = pack('C5', 0, 1, 0, ord($raw[3]), 0) . hex2bin($hsv);
        return ['20' => true, '21' => 'colour', '61' => base64_encode($paint)];
    }

    /** Decode Tuya's documented SmearFormatter v0, colour/card modes only. */
    private function decodePaintColour(string $encoded): ?array
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 11 || ord($raw[0]) !== 0
            || !in_array(ord($raw[1]), [1, 2], true) || ord($raw[2]) > 1) {
            return null;
        }
        $segments = ord($raw[3]);
        $action = ord($raw[4]);
        $hsv = unpack('nh/ns/nv', substr($raw, 5, 6));
        if ($segments < 1 || $hsv['h'] > 360 || $hsv['s'] > 1000 || $hsv['v'] > 1000) {
            return null;
        }
        if ($action === 0 && strlen($raw) === 11) {
            $description = 'DP61: alle Segmente';
        } elseif ($action === 1 && strlen($raw) >= 13) {
            $selection = ord($raw[11]);
            $indices = array_values(unpack('C*', substr($raw, 12)));
            if (($selection & 128) !== 0) {
                if (($selection & 127) !== count($indices)) {
                    return null;
                }
            } elseif (count($indices) !== 2) {
                return null;
            }
            foreach ($indices as $index) {
                if ($index < 1 || $index > $segments) {
                    return null;
                }
            }
            $description = 'DP61: letzte Segmentfarbe (' . implode(($selection & 128) ? ', ' : ' bis ', $indices) . ')';
        } else {
            // Eraser, combinations and unknown layouts are not a global RGB colour.
            return null;
        }
        return ['hsv' => sprintf('%04x%04x%04x', $hsv['h'], $hsv['s'], $hsv['v']), 'description' => $description];
    }

    private function offline(string $msg): void
    {
        $this->SendDebug('Fehler', $msg, 0);
        if (preg_match('/HMAC|Entschlüsselung|Protokollversion/', $msg)) {
            $this->WriteAttributeString('DetectedVersion', '');
        }
        if ($this->ReadPropertyBoolean('OfflineAsOff')) {
            foreach (['Status', 'Switch1'] as $i) {
                if (@$this->GetIDForIdent($i)) {
                    $this->setIfChanged($i, false);
                }
            }
        }
        if ($this->GetStatus() !== 200) {
            $this->LogMessage('nicht erreichbar: ' . $msg, KL_WARNING);
        }
        $this->SetStatus(200);
    }

    private function setIfChanged(string $ident, $val): void
    {
        if ($this->GetValue($ident) !== $val) {
            $this->SetValue($ident, $val);
        }
    }

    private function rgbToTuya(int $rgb): string
    {
        $r = (($rgb >> 16) & 255) / 255;
        $g = (($rgb >> 8) & 255) / 255;
        $b = ($rgb & 255) / 255;
        $mx = max($r, $g, $b);
        $mn = min($r, $g, $b);
        $d = $mx - $mn;
        $h = 0;
        if ($d > 0) {
            if ($mx == $r) {
                $h = 60 * fmod((($g - $b) / $d), 6);
            } elseif ($mx == $g) {
                $h = 60 * ((($b - $r) / $d) + 2);
            } else {
                $h = 60 * ((($r - $g) / $d) + 4);
            }
        }
        if ($h < 0) {
            $h += 360;
        }
        $s = $mx == 0 ? 0 : $d / $mx;
        return sprintf('%04x%04x%04x', (int) round($h), (int) round($s * 1000), max(10, (int) round($mx * 1000)));
    }

    private function tuyaToRgb(string $hsv): int
    {
        $h = hexdec(substr($hsv, 0, 4));
        $s = hexdec(substr($hsv, 4, 4)) / 1000;
        $v = hexdec(substr($hsv, 8, 4)) / 1000;
        $c = $v * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $v - $c;
        [$r, $g, $b] = $h < 60 ? [$c, $x, 0] : ($h < 120 ? [$x, $c, 0] : ($h < 180 ? [0, $c, $x] : ($h < 240 ? [0, $x, $c] : ($h < 300 ? [$x, 0, $c] : [$c, 0, $x]))));
        return ((int) round(($r + $m) * 255) << 16) | ((int) round(($g + $m) * 255) << 8) | (int) round(($b + $m) * 255);
    }
}
