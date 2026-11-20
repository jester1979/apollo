# Apollo AGC in PHP

Een selectieve port van de Apollo 11-boordcomputersoftware (Comanche055 en Luminary099) naar PHP 8.3, met een webpagina die de DSKY en het ruimtevaartuig laat zien.

## Missie

**Hoofdstuk**:
Eén speelbare missiefase met eigen AGC-geleiding, die start vanuit een vaste historische begintoestand.
_Avoid_: level, scène, stage

**Tussenstuk**:
Een korte overgang tussen twee hoofdstukken zonder eigen geleiding of DSKY-invoer, zoals de reis naar de maan of de rendez-vous.
_Avoid_: cutscene, intermezzo

**Apollo 11-scenario**:
De schakelbare variant van het landingshoofdstuk waarin extra radarwerk de Executive overbelast, zodat de alarmen 1201 en 1202 ontstaan zoals tijdens de echte vlucht.
_Avoid_: alarmmodus, historische modus

## Boordcomputer

**AGC**:
De Apollo Guidance Computer. Er zijn er twee: één in de commandomodule (programma Comanche055) en één in de maanlander (programma Luminary099).
_Avoid_: boordcomputer, computer (zonder nadere aanduiding)

**DSKY**:
Het invoer- en uitvoerpaneel van de AGC met Verb-, Noun- en registerweergaven. Het scherm toont er één, van de AGC die in het hoofdstuk actief is.
_Avoid_: toetsenbord, display

**Port**:
De PHP-versie van een AGC-routine: leesbare PHP die hetzelfde doet als de routine in de originele `.agc`-bron en daarnaar verwijst.
_Avoid_: vertaling, herschrijving, emulatie
