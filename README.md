# Tuya Local RGB für IP-Symcon

Fork von [menzeljo-stack/SymconTuyaLocal](https://github.com/menzeljo-stack/SymconTuyaLocal)
mit gezielten Erweiterungen für RGB-Controller mit DP24 und Dreamcolor-Bänder mit DP61.
Der Betrieb erfolgt lokal. Der optionale Cloud-Import benötigt einen gültigen Tuya-Cloud-Zugang.

## Installation

In IP-Symcon ab Version6.0 die Bibliothek über die Modulverwaltung hinzufügen:

```text
https://github.com/Sol-IoTiv/SymconTuyaLocal.git
```

Branch: `main`. Anschließend eine Instanz **Tuya Gerät (lokal)** anlegen.
Geräte-ID, Local Key und lokale IP-Adresse eintragen.
Der Local Key ist der Geräteschlüssel, nicht das Cloud-Access-Secret.
„Status abfragen“ liest nur Werte und schaltet das Licht nicht.

### Wechsel vom Originalmodul

Dieser Fork behält Bibliotheks-ID, Modul-IDs und Funktionspräfixe des Originals bei.
Original und Fork dürfen deshalb **nicht parallel installiert** werden.
Vor einem Wechsel die Symcon-Konfiguration und vorhandene lokale Moduländerungen sichern.
Die bestehende Bibliothek muss auf das Fork-Repository umgestellt werden; bestehende
Geräteinstanzen nicht löschen. Ein Update über das Originalrepository überschreibt
die Erweiterungen wieder.

## Einstellungen der erprobten Controller

| Einstellung | RGB-Controller „RGB“ | MAGIC SMART LED Stripe 5m |
|---|---|---|
| Erprobtes Protokoll | 3.3 | 3.5 |
| RGB-Helligkeit über DP24 | Ein | Ein |
| Dreamcolor DP61 verwenden | Aus | Ein |
| Farbstatus | DP24: HSV-Hexwert | DP61: Base64-Paint-Format |

Dies sind die Einstellungen der getesteten Geräte, keine automatische Erkennung anhand
eines Produktnamens. Die Protokollversion kann auch automatisch ermittelt werden.
Unterhalb des Statusknopfs steht die konfigurierte oder automatisch erkannte Version.

### RGB über DP24

Dimmen passt den V-Anteil des HSV-Werts an und erhält Farbton und Sättigung.
Ein Farbwechsel erhält die zuletzt gelesene Helligkeit. Es erfolgt kein Wechsel
in den Weißmodus. Bei 0 Prozent wird ausgeschaltet; positive Helligkeits- und
Farbbefehle schalten ein. Zuvor muss ein gültiger Farbstatus eingelesen sein.

### Dreamcolor über DP61

Zusätzlich wird DP61 lesend per `UPDATEDPS` (Befehl18) angefordert. Der Client sammelt
die weiteren Antworten einschließlich verschachtelter `data.dps` bis zu 1,5 Sekunden lang.
Der App-Farbstatus aus DP61 hat Vorrang vor einem möglicherweise älteren DP24-Wert.

Die normalen Farb- und Helligkeitsbedienelemente senden im DP61-Modus eine einheitliche
Farbe für **das gesamte Band**, unabhängig von der letzten App-Segmentauswahl.
Die zuletzt gemeldete Anzahl der App-Segmente bleibt erhalten.

Unterstützt wird SmearFormatter Version0 im RGB-/Farbkarten-Modus. Bei App-Einzelwahl
zeigt Symcon die zuletzt gemeldete Segmentfarbe und deren Helligkeit, keinen vollständigen
Zustand aller Segmente. Kombinationen, Radierer, Szenen und Musik werden nicht als
einheitliche RGB-Farbe ausgewertet. Bei fehlenden oder nicht unterstützten Farbdaten
bleibt der letzte gültige Anzeigewert erhalten.

## Weitere Funktionen des Originals

- Leuchten mit DP20–24 sowie boolesche Schalter/Steckdosen auf DP1–9.
- `TUYA_Update($id)` liest den Status.
- `TUYA_SetDPs($id, ['20' => true])` sendet Steuerwerte; dieses Beispiel schaltet ein.
- **Tuya Konfigurator** (`TUYACFG`) übernimmt Geräte und Local Keys aus der Tuya-Cloud.
- `TUYACFG_UpdateKeys($id)` aktualisiert Local Keys nach erneutem Anlernen.

Die neuen Optionen sind standardmäßig ausgeschaltet. Es gibt keinen allgemeinen
Netzwerkscanner und keine universelle Erkennung beliebiger Tuya-Datenpunkte.
Andere Geräte können andere Datenpunktnummern oder Formate benötigen.

## Prüfung und Grenzen

Lokale Abfragen wurden mit den genannten Geräten unter Protokoll3.3 und 3.5 erprobt.
Die RGB-Befehlsbildung und DP61-Auswertung werden isoliert ohne Geräteverbindung geprüft.
Protokoll3.4 und weitere Gerätetypen wurden für diese Erweiterungen nicht live getestet.

Die ursprüngliche Client-Implementierung übernimmt bei fehlender Statusantwort die
angeforderten Steuerwerte. Eine erfolgreiche Befehlsrückgabe allein bestätigt daher
nicht den Gerätezustand. Diesen anschließend mit einer neuen Statusabfrage prüfen.

Formatreferenz: [Tuya SmearFormatter](https://github.com/Tuya-Community/tuya-ray-materials/blob/main/template/PublicPanelStripLamp/src/devices/protocols/parsers/SmearFormatter.ts).
