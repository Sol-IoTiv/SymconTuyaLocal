<?php
declare(strict_types=1);

// Standalone PHP test: no sockets, credentials or real actuators.
$source = file_get_contents(__DIR__ . '/../TuyaDevice/module.php');
token_get_all($source, TOKEN_PARSE);
$source = substr($source, strpos($source, 'class TuyaDevice extends IPSModule'));
$source = str_replace('class TuyaDevice extends IPSModule', 'class RGBModuleUnderTest extends RGBTestBase', $source);

class RGBTestBase
{
    public array $properties = ['RGBBrightnessDP24' => true, 'SyncPaintDP61' => false];
    public array $attributes = ['LastColourData' => '001303e003e8', 'LastPaintData' => 'AAEAFAEBRAM4A+iBFA==', 'KnownDPs' => '[]'];
    public array $values = [];
    protected function ReadPropertyBoolean($name) { return $this->properties[$name] ?? false; }
    protected function ReadPropertyInteger($name) { return $name === 'CTMin' ? 2700 : 6500; }
    protected function ReadAttributeString($name) { return $this->attributes[$name] ?? ''; }
    protected function WriteAttributeString($name, $value) { $this->attributes[$name] = $value; }
    protected function SetValue($name, $value) { $this->values[$name] = $value; }
    protected function GetValue($name) { return $this->values[$name] ?? null; }
    protected function MaintainVariable(...$args) {}
    protected function EnableAction(...$args) {}
    protected function GetIDForIdent($name) { return 1; }
}
eval($source);
class RGBCapture extends RGBModuleUnderTest
{
    public array $sent = [];
    public function SetDPs(array $dps): bool { $this->sent = $dps; return true; }
}
function rgbAssert(bool $ok, string $message): void
{
    if (!$ok) { throw new RuntimeException($message); }
}
$t = new RGBCapture();
foreach ([1, 50, 100] as $v) {
    $t->RequestAction('Helligkeit', $v);
    rgbAssert($t->sent === ['20' => true, '21' => 'colour', '24' => '001303e0' . sprintf('%04x', $v * 10)], 'DP24 dimming');
}
$t->RequestAction('Helligkeit', 0);
rgbAssert($t->sent === ['20' => false], 'Zero switches off');
$t->attributes['LastColourData'] = '001303e001f4';
$t->RequestAction('Farbe', 0x0000ff);
rgbAssert($t->sent['24'] === '00f003e801f4', 'Colour preserves brightness');
$t->properties['RGBBrightnessDP24'] = false;
$t->RequestAction('Helligkeit', 50);
rgbAssert($t->sent === ['20' => true, '21' => 'white', '22' => 500], 'Legacy mode');
$t->properties['RGBBrightnessDP24'] = true;
$t->properties['SyncPaintDP61'] = true;
$t->attributes['LastColourData'] = '014403380384';
$t->RequestAction('Helligkeit', 90);
rgbAssert(base64_decode($t->sent['61']) === hex2bin('0001001400014403380384'), 'DP61 targets whole strip and preserves segment count and colour');
rgbAssert(!isset($t->sent['24']) && $t->sent['21'] === 'colour', 'No stale DP24 or white command');
$t->RequestAction('Farbe', 0x0000ff);
rgbAssert(bin2hex(base64_decode($t->sent['61'])) === '000100140000f003e80384', 'DP61 colour retains brightness');
$decoder = new ReflectionMethod($t, 'decodePaintColour');
$decoded = $decoder->invoke($t, 'AAEAFAEBRAM4A+iBFA==');
rgbAssert($decoded['hsv'] === '0144033803e8', 'Captured segment payload');
foreach (['invalid', base64_encode('abc'), base64_encode(pack('C5n3', 0, 1, 0, 20, 0, 361, 1000, 900))] as $bad) {
    rgbAssert($decoder->invoke($t, $bad) === null, 'Reject malformed paint data');
}
$t->attributes['LastPaintData'] = '';
$t->sent = [];
try { $t->RequestAction('Helligkeit', 50); throw new RuntimeException('Missing paint data accepted'); }
catch (Exception $e) { rgbAssert($t->sent === [] && str_contains($e->getMessage(), 'DP61'), 'No write without paint status'); }
echo "RGB and DP61 tests passed\n";
