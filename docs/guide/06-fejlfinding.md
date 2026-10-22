# Fejlfinding

Her er de problemer, butikker oftest møder, og hvad du skal kigge efter. Kommer du ikke videre, så send Økoskabet ordrenummeret og en beskrivelse af, hvad du ser.

## Økoskabets fragtmetoder kan ikke vælges

Står Økoskabet, Hjemmelevering eller Butiksafhentning ikke på listen, når du klikker **Tilføj forsendelsesmetode** i en zone, eller vises de ikke i kassen:

- Er der en **API Nøgle** i pluginets indstillinger? Uden den kan ingen af metoderne vælges.
- Er det den rigtige nøgle? Den skal komme fra Økoskabets backoffice under **Indstillinger → API & webhooks**, ikke fra WooCommerce.
- Har din konto hos Økoskabet leveringstypen slået til? En metode, du ikke har hos Økoskabet, står ikke på listen.
- Har Økoskabet lige ændret noget, så gem pluginets indstillinger. Pluginet husker svaret fra Økoskabet i fem minutter, og en gemning spørger igen med det samme.
- Ligger metoden i den zone, kundens adresse hører til? Prøv med en adresse, du ved ligger i zonen.

## Kassen viser fragtmetoden, men ingen datovælger

Kunden kan vælge Økoskabet, men der kommer ingen liste over skabe eller datoer.

- **Ingen API-nøgle**: uden nøgle tegner pluginet slet ikke vælgeren.
- **Kassen er bygget med en sidebygger eller er WooCommerces blok-kasse**: vælgeren hænger normalt på WooCommerces egen kasse. En kasse bygget med for eksempel Bricks eller Elementor, eller blok-kassen, giver den ikke en plads. Så står der øverst i kontrolpanelet: "Økoskabet: leveringsvælgeren vises ikke i din kasse". Løsningen er at placere vælgeren selv, der hvor leveringen skal stå:

```
[okoskabet_levering]
```

I Bricks bruger du et tekstelement med:

```
{do_action:okoskabet_levering}
```

Læg det ikke inde i blokken **Send til anden adresse?**. Den er skjult, indtil kunden sætter flueben, så pluginet springer den over og lægger leveringsrækkerne under fragtvalgene i stedet. Beskeden i kontrolpanelet forsvinder af sig selv, når vælgeren er tegnet i en rigtig kasse.

- **Datovælgeren mangler kun for nye kunder, og kommer frem, når siden genindlæses**: det var en fejl i versioner før 1.5.2. Opdatér pluginet.

## Mærkelige felter står frit i kassen

Ser kunden felter som **Afhentningssted**, **Leveringssted**, **Besked til chaufføren (valgfrit)** eller **Forudbestilling** som almindelige tekstfelter i adresseformularen, så er WooCommerces indstilling **Skjul forsendelsesomkostninger indtil en adresse er indtastet** slået til. Den findes under **WooCommerce → Indstillinger → Forsendelse → Forsendelsesindstillinger**.

Felterne er pluginets egne og skal være skjulte. I version 1.5.2 og ældre stod de synlige, indtil kunden havde skrevet en adresse. Det er rettet i version 1.5.3. Indtil du har den, kan du slå WooCommerces indstilling fra.

Med indstillingen slået til viser kassen først fragtvalg og datovælger, når kunden har skrevet en adresse. Det er WooCommerce, der gør det, og det er i orden.

## "Ingen tilgængelige datoer."

Kassen viser ingen dage for kurven.

- Står der en forklaring med varer og regler under, er det dine **Leveringsundtagelser**. Varerne i kurven har ingen fælles dag. Ret reglerne, eller slå **Tillad opdelt checkout** til, så kunden kan dele ordren.
- Bruger du regler om faste ugedage, så tjek at **Standard visningsvindue (dage)** rækker mindst en uge frem.
- Kører Økoskabet ikke til postnummeret, er der ingen dage. Til ø-levering kan du bruge **Postnumre uden leveringsdage** på Hjemmelevering.
- Er bestillingsfristen for de nærmeste dage nået, forsvinder de dage. Det gælder også dine egne **Regler for tidligere cutoff**.

## En ordre er ikke kommet til Økoskabet

Åbn ordren i WooCommerce og kig i noterne i højre side.

- **Står der "Sendt til Økoskabet"**, er ordren sendt. Søg på ordrenummeret i Økoskabets backoffice. Uden leveringsdato ligger den blandt de ubehandlede ordrer.
- **Har ordren en anden fragtmetode end Økoskabets**, sendes den ikke. Det er med vilje.
- **Står ordren På hold uden note**, venter den på betaling. Det gælder for eksempel bankoverførsel. Sæt den til **Behandler**, når pengene er kommet, så sendes den.
- **Står ordren Afventer betaling**, er den ikke betalt endnu. Den sendes, når den bliver Behandler eller På hold.
- **Står ordren Mislykket med en note fra Økoskabet**, har Økoskabet afvist den. Noten siger hvorfor, ofte en adresse der ikke kan findes. Ret det og sæt ordren til **Behandler** igen.
- **Er det et tillæg, der står På hold**, så læs noten. Varerne kunne ikke komme med den første levering, og du skal selv vælge: sæt til Behandler for at prøve igen, eller send eller refundér varerne på anden vis.
- Har butikken flere Økoskabet-konti, så tjek at den konto, ordren gik til, har en API-nøgle.

![En ordre med noterne fra Økoskabet i højre side](images/ordre-noter.png)

## Ordrer bliver ikke gennemført, eller betalingen bliver ikke hævet

Det er statusbeskederne fra Økoskabet, der gør det. Tjek i den rækkefølge:

1. Står der flueben i **Webhook Status**?
2. Hvad siger **Kontakt fra Økoskabet**? Står der **aldrig**, når beskederne ikke frem. Se næste afsnit.
3. Er der flueben ved de rigtige trin under **Capture-events** og **Completion-events**? Uden flueben under Completion-events bliver ordrer aldrig gennemført.
4. Er ordren betalt? En ubetalt, annulleret, refunderet eller mislykket ordre bliver ikke gennemført.
5. Kig i ordrens noter. Pluginet skriver en note, hver gang det prøver at hæve eller gennemføre.

Bruger butikken Nets Easy eller en anden løsning, der først trækker pengene, når ordren er gennemført, er der ingen Capture-events. Så er det Completion-events, der trækker pengene.

## Statusbeskeder bliver afvist

Afviser butikken en statusbesked, svarer den med et tal. Økoskabet kan fortælle dig, hvilket tal butikken svarede. Det betyder:

- **401**: underskriften passer ikke. **Webhook Secret** i pluginet er ikke den samme som i backoffice. Kopiér den igen fra backoffice, sæt den ind og gem.
- **403**: enten er **Webhook Status** slået fra, eller ordren hører til en anden af butikkens Økoskabet-konti end den, adressen peger på. Er Webhook Status slået til, og står der **aldrig** under **Kontakt fra Økoskabet**, så er det ikke pluginet, der svarer. Så er det en firewall foran hjemmesiden, typisk Cloudflare eller et sikkerhedsplugin, der stopper Økoskabet. Bed den, der passer hjemmesiden, om at lukke Økoskabets kald til adresser, der begynder med `/wp-json/wp/v2/okoskabet/`, igennem.
- **404**: ordren findes ikke i butikken, eller adressen indeholder et kontonavn, pluginet ikke kender. Tjek at webhook-adressen i backoffice er den, pluginet viser under **Webhook URL**.
- **409**: flere ordrer i butikken har samme Økoskabet-reference. Ingen af dem bliver rørt. Kig efter en ordre, der er kopieret fra en anden.
- **500**: der er ingen **Webhook Secret** i pluginet. Sæt den ind og gem.

## Forkerte eller gamle data i kassen efter en ændring

Kassen må ikke ligge i en sidecache. Gemmer et cache-plugin eller webhotellet kassen, kan kunden se gamle datoer, skabe eller knapper, eller slet ingen vælger.

- Undtag kassen og kurven fra cachen i dit cache-plugin. De fleste cache-plugins gør det selv for WooCommerce, men tjek det.
- Slå sammenlægning og udskydelse af JavaScript fra for kassen, hvis vælgeren opfører sig mærkeligt.
- Tøm cachen efter hver opdatering af pluginet.

## Adresseforslagene kommer ikke

- Er **Adresseforslag i kassen** slået til? Det er slået fra fra start.
- Forslagene virker kun i WooCommerces klassiske kasse, ikke i blok-kassen.
- Funktionen kommer i version 1.5.3.
- Kan adresseregistret ikke svare, viser kassen bare ingen forslag. Kunden kan stadig skrive adressen selv.

## Tillægget fik ikke gratis fragt

- Kunden skal skrive den samme mailadresse som på den første ordre.
- Den første ordre skal være en levering med Økoskabet og stadig være åben. Er bestillingsfristen nået, eller er Økoskabet gået i gang med forsendelsen, er det for sent.
- Kunden skal vælge en af Økoskabets fragtmetoder. Butikkens egen fragt bliver ikke gratis.

## WordPress tilbyder ikke den nye version

- Klik **Tjek igen** under **Kontrolpanel → Opdateringer**.
- Er pluginet engang installeret fra GitHubs "Source code" eller fra `main`, ligger det i en mappe med et forkert navn og får aldrig opdateringer. Følg **Skift fra en gammel installation** i delen Installation.
