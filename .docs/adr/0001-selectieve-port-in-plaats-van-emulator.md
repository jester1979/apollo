# Selectieve port met floats in plaats van een AGC-emulator

De Apollo 11-software (Comanche055 en Luminary099, samen ongeveer 130.000 regels AGC-assembly) wordt niet geëmuleerd, maar selectief geport naar leesbare PHP. Alleen de routines die de zes hoofdstukken nodig hebben worden geport: DSKY, Executive, landingsgeleiding, opstijgen, P40-branding en terugkeer. Die rekenen met PHP-floats in SI-eenheden in plaats van met de 15-bit ones'-complement vaste komma van de AGC. Elke port verwijst met `@see` naar het originele bestand en label. We kozen hiervoor omdat een emulator een yaYUL-assembler en een volledige simulatie van het ruimtevaartuig (IMU, radar, PIPA-tellers) vraagt voordat er ook maar één landing vliegt, en omdat vaste-kommacode met schaalfactoren per variabele slecht te lezen en te testen is.

## Considered options

- Een AGC-emulator in PHP die de originele assembly draait: het meest trouw aan het origineel, maar vele malen groter werk.
- Een letterlijke vertaling, regel voor regel: zeer groot werk en slecht leesbare code.
- Een port met een `AgcWord`-type voor vaste komma: trouw aan het rekengedrag en de overflows, maar elke formule wordt moeilijk te lezen.

## Consequences

- Het rekengedrag van 1969 (overflow, afronding, schaling) zit niet in de simulatie. Alleen de DSKY rekent terug naar de originele eenheden en formaten.
- Waarden die in het origineel pas vlak voor de vlucht werden ingeladen (pad-loads), zoals de landingsdoelen en de ROD-klikschaal, staan niet in de bron. Ze worden gekozen en per scenario vastgelegd.
- De 1201- en 1202-alarmen ontstaan wel uit de geporte Executive (7 core sets, 5 VAC-gebieden), maar de job-kosten zijn geschat en niet gemeten.
