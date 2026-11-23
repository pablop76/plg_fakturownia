# Wtyczka HikaShop → Fakturownia.pl

> ⚠️ **UWAGA:** Ta wtyczka nie jest przeznaczona do użytku komercyjnego. Nie przeszła wszystkich testów i może zawierać błędy. Używasz na własną odpowiedzialność.

## Opis
Wtyczka umożliwia **automatyczne wystawianie faktur** w systemie [Fakturownia.pl](https://fakturownia.pl) na podstawie zamówień z HikaShop.

---

## Funkcje
- Automatyczne wystawianie faktur VAT lub paragonów w Fakturowni przy zamówieniach z HikaShop.
- Przekazywanie danych nabywcy wprost na dokument (firma lub osoba prywatna, imię, nazwisko, NIP, adres, kod kraju, e-mail) — kartę klienta Fakturownia dopasowuje lub zakłada sama.
- Przekazywanie pozycji zamówienia (produkt, ilość, cena, VAT, rabaty, koszty wysyłki, kupony).
- **Pole „Chcę otrzymać fakturę VAT"** (pole adresu rozliczeniowego) — klient sam decyduje o fakturze, nawet bez NIP.
- **Walidacja NIP nabywcy** — czyści formatowanie i sprawdza sumę kontrolną polskiego NIP; błędny NIP nie blokuje wystawienia (faktura idzie bez NIP). Numery VAT nabywców spoza Polski (np. `DE123456789`) przechodzą bez zmian.
- **Konfigurowalny status zamówienia** wyzwalający wystawienie (domyślnie „confirmed").
- **Ochrona przed duplikatami** — w sklepie (blokada zamówienia) i po stronie Fakturowni: dokument dostaje numer zamówienia (`oid`) z wymogiem unikalności, więc Fakturownia sama odrzuci drugi dokument dla tego samego zamówienia, nawet po zerwanym połączeniu.
- **Zgodność z KSeF** — rodzaj nabywcy (firma / osoba prywatna), kod kraju, limity długości pól, powiązanie korekty z fakturą; status KSeF dokumentu widoczny w panelu zamówienia.
- Opcjonalne automatyczne wysyłanie faktury e-mailem do klienta.
- **Powiadomienie o konieczności korekty** przy anulowaniu/zwrocie zamówienia po wystawieniu dokumentu, z opcją automatycznego wystawienia korekty do zera.
- **Przycisk „Wystaw ponownie"** w widoku zamówienia w panelu — wymusza ponowne wystawienie dokumentu (np. po poprawieniu danych i usunięciu błędnego dokumentu w Fakturowni).
- **Płatność zależna od statusu zamówienia** — dokument wystawiony przy statusie opłaconym od razu ma płatność; wystawiony przed zapłatą (pobranie, przelew B2B) pozostaje nieopłacony, a płatność rejestruje się automatycznie, gdy zamówienie przejdzie na status opłacony.
- **Konfigurowalny termin płatności** (domyślnie 0 dni — zamówienie jest już opłacone).
- Stawki VAT wysyłki i kosztu płatności pobierane **z zamówienia HikaShop** (kategorie podatkowe metod wysyłki/płatności) — dokument odzwierciedla to, co klient faktycznie zapłacił.

---

## Wymagania
- Joomla 5.0+ (działa też na 6.x)
- PHP 8.1+
- HikaShop (zainstalowany przed instalacją wtyczki)
- Konto w serwisie Fakturownia.pl

---

## Instalacja i konfiguracja
1. Zainstaluj i włącz wtyczkę w Joomla.
2. Wejdź w ustawienia wtyczki i uzupełnij:
   - **API Token** z konta Fakturownia (Ustawienia → Ustawienia konta → Integracja)
   - **Subdomena konta** (np. `mojafirma` — bez `.fakturownia.pl`)
   - Danych sprzedawcy **nie wpisuje się** — Fakturownia bierze je z **Ustawienia → Dane firmy** (od wersji 2.3.0; przy KSeF wymagany jest tam pełny adres firmy).
   - **Status wyzwalający** — przy jakim statusie zamówienia wystawić dokument (domyślnie `confirmed`; można podać kilka po przecinku, np. `confirmed,shipped`)
   - **Termin płatności (dni)** — data „Termin płatności" na dokumencie, liczona od wystawienia (domyślnie `0`, bo zamówienie przy statusie `confirmed` jest już opłacone)
   - **Statusy opłacone** — które statusy oznaczają opłacone zamówienie (domyślnie `auto` — status z kolumny „confirmed" w HikaShop). Dokument wystawiony przy statusie opłaconym od razu ma płatność; wystawiony przy nieopłaconym pozostaje nieopłacony, a płatność dorejestruje się automatycznie po przejściu zamówienia na status opłacony
   - **Typ dokumentu:**
     - **Faktura VAT** — każde zamówienie dostaje fakturę,
     - **Auto** — paragon domyślnie, a faktura gdy klient poda NIP lub zaznaczy „Chcę fakturę VAT" (wymaga modułu paragony.pl).
   - **Automatycznie wysyłaj fakturę e-mailem** — po wystawieniu faktura zostanie wysłana na adres e-mail klienta z poziomu Fakturowni. Przy aktywnym KSeF fakturę dla firmy Fakturownia wyśle dopiero po nadaniu numeru KSeF — gdy odmówi, w historii zamówienia pojawi się informacja, żeby wysłać ją z Fakturowni.
   - **Katalog produktów w Fakturowni** — kto zapisuje produkty z zamówień w katalogu produktów Fakturowni. Na samą fakturę nie ma to wpływu: nazwy, ceny i stawki VAT pozycji zawsze pochodzą z zamówienia.
     - **Prowadzi wtyczka** (domyślnie): przed wystawieniem faktury wtyczka szuka produktu w katalogu po kodzie z HikaShopa, a gdy go nie znajdzie, po nazwie. Brakujący produkt dodaje, a znalezionemu uaktualnia kod, nazwę, cenę netto i stawkę VAT. Pozycja faktury dostaje odnośnik do produktu (`product_id`), więc Fakturownia nie tworzy drugiego wpisu. Każdy produkt to od 1 do 3 dodatkowych żądań w trakcie zapisu zamówienia, więc przy dużym koszyku klient dłużej czeka na potwierdzenie.
     - **Prowadzi Fakturownia**: wtyczka nie wysyła nic do katalogu. Fakturownia sama dopisuje do niego pozycje wystawionych faktur i rozpoznaje je po nazwie. Wpisy nie mają kodów z HikaShopa, a produkt po zmianie nazwy trafia do katalogu jako nowy.
     - W obu trybach wysyłkę i koszt płatności (np. „Wysyłka: InPost Paczkomaty 24/7”) dopisuje do katalogu sama Fakturownia.
3. **(Tylko w trybie Auto) Utwórz pole „Chcę otrzymać fakturę VAT"** — pozwala klientowi bez NIP-u poprosić o fakturę. W ustawieniach wtyczki wystarczy kliknąć **„Utwórz pole w HikaShopie”**. Zobacz sekcję *„Pole »Chcę otrzymać fakturę VAT«"* poniżej.

   > **Uwaga:** Paragony wymagają modułu paragony.pl zainstalowanego w serwisie Fakturownia.pl.

---

## Pole „Chcę otrzymać fakturę VAT"

Pole pozwala klientowi **bez NIP-u** poprosić o fakturę VAT. Bez niego faktura i tak wystawia się, gdy klient poda NIP. Ma znaczenie tylko w trybie **Auto** — w trybie **Faktura VAT** każde zamówienie i tak dostaje fakturę, więc pole tylko myliłoby klientów.

**Od wersji 2.3.3 pole tworzy wtyczka.** W ustawieniach wtyczki, po wybraniu trybu **Auto** i zapisaniu ustawień, pod typem dokumentu widać stan pola. Jeśli pola nie ma, przycisk **„Utwórz pole w HikaShopie”** zakłada je funkcją HikaShopa (tą samą, której używa jego panel): w adresie płatności, jako pole do zaznaczenia, opublikowane i widoczne w sklepie. Istniejącego pola wtyczka nie zmienia. Wygląd i treść zmienisz później w HikaShop → Wyświetlanie → Pola użytkownika.

Gdyby przycisk zgłosił błąd, pole można utworzyć **ręcznie** w HikaShop: **Wyświetlanie → Pola użytkownika → Utwórz**, i ustawić tak:

| Pole w formularzu HikaShop | Wartość |
|---|---|
| Etykieta | `Chcę otrzymać fakturę VAT` (dowolna — nazwa dla admina) |
| **Nazwa kolumny** | **`invoice_request`** — dokładnie tak; wtyczka odczytuje tę kolumnę |
| Tabela | Adres (`address`) |
| Typ pola | Przycisk zaznaczenia (Checkbox) |
| Typ | **Adres do Płatności** — dzięki temu pole pokaże się tylko w adresie rozliczeniowym (bez dublowania w adresie wysyłki) |
| Opublikowany | Tak |
| **Formularz na froncie** | **Tak** — bez tego pole nie pojawi się na checkoutcie |

W sekcji **Dane pola → „Dodaj nową wartość"** dodaj jedną wartość:

- **Wartość:** `1`
- **Tytuł:** `Chcę otrzymać fakturę VAT`

Zapisz. Checkbox pojawi się w adresie rozliczeniowym na checkoutcie — zaznaczony (nawet bez NIP) wymusza fakturę VAT.

> HikaShop **Starter** nie pozwala umieścić pola *zamówienia* w przepływie checkoutu (to funkcja **Business**), dlatego używamy pola *adresu* z typem „Adres do Płatności".

---

## Jak ustalany jest typ dokumentu (tryb Auto)

| Sytuacja | Dokument |
|---|---|
| Klient zaznaczy „Chcę otrzymać fakturę VAT" | faktura VAT (nawet bez NIP) |
| Klient poda poprawny NIP | faktura VAT z NIP |
| Klient poda błędny NIP | faktura VAT bez NIP |
| Brak NIP i brak zaznaczenia | paragon |

W trybie **Faktura VAT** każde zamówienie otrzymuje fakturę, niezależnie od powyższego.

---

## Działanie
1. Klient składa zamówienie w sklepie (opcjonalnie zaznacza „Chcę otrzymać fakturę VAT").
2. Po zmianie statusu zamówienia na wyzwalający (domyślnie **confirmed**) wtyczka automatycznie:
   - wystawia fakturę VAT lub paragon z danymi nabywcy (kartę klienta Fakturownia dopasowuje lub zakłada sama),
   - rejestruje płatność (gdy status zamówienia jest na liście „Statusów opłaconych"; inaczej dokument czeka nieopłacony i płatność dorejestruje się przy przejściu na status opłacony),
   - opcjonalnie wysyła fakturę e-mailem,
   - w trybie „Prowadzi wtyczka” przed wystawieniem dokumentu dodaje lub uaktualnia produkty w katalogu Fakturowni.

Każde żądanie do API ma limit czasu (20 s), a token API jest przekazywany w nagłówku `Authorization` (zalecenie Fakturowni od listopada 2026). Kwoty pozycji pochodzą wprost z zamówienia HikaShop (cena i podatek jednostkowy policzone przez HikaShop), więc suma dokumentu zgadza się z kwotą zapłaconą przez klienta także przy włączonej w HikaShopie opcji zaokrąglania obliczeń.

---

## Rabaty i kupony na paragonie fiskalnym

Kupon rabatowy (`order_discount_code`/`order_discount_price` z HikaShop) **nie jest** wysyłany jako osobna pozycja z ujemną ceną. Kasy/drukarki fiskalne (moduł paragony.pl) odrzucają taką pozycję błędem *„Niepoprawna cena (cena musi być dodatnia)"* — to wymóg protokołu fiskalnego: rabat to osobny typ operacji, nie sprzedaż towaru za ujemną kwotę. Zgodnie z [API Fakturowni](https://pomoc.fakturownia.pl/374462-api-dodawanie-faktur-z-rabatem-kwotowym-lub-procentowym) wtyczka rozkłada wartość kuponu **proporcjonalnie jako pole `discount` na już istniejących pozycjach** — Fakturownia sama odejmuje tę kwotę od wartości brutto pozycji przy wyliczaniu dokumentu. Suma rabatów zawsze zgadza się co do grosza z wartością kuponu (reszta zaokrąglenia trafia do ostatniej pozycji).

Rabat trafia na te pozycje, które obniżył HikaShop (od wersji 2.3.2): **na produkty**, a na wysyłkę tylko wtedy, gdy kupon ma w HikaShopie ustawiony **„Procent wysyłki”** — i dokładnie w tej części. Kosztu płatności kupon nie obniża. Dzięki temu podział kwot i VAT na dokumencie zgadza się z zamówieniem także przy różnych stawkach VAT (np. produkty 5%, wysyłka 23%). Do wersji 2.3.1 kupon był rozkładany na wszystkie pozycje, także wysyłkę i koszt płatności.

> Jeśli mimo to rabat na dokumencie wygląda źle, sprawdź: **Ustawienia → Ustawienia konta → Konfiguracja → Sposoby wyliczania → „Jak obliczać rabat"** — wtyczka wysyła `discount_kind: "amount"` (rabat kwotowy) per dokument, co powinno nadpisać ustawienie konta, ale w razie wątpliwości ustaw tam też „kwotowo".

Kod i kwota kuponu (np. „Kupon rabatowy: Kupon1 (-1,00 PLN)" — w walucie zamówienia) można dodatkowo dopisać w polu **„Uwagi"** dokumentu (nie jako pozycja — bezpieczne dla druku fiskalnego) — steruje tym ustawienie wtyczki **„Dopisz kupon w uwagach dokumentu"** (włączone domyślnie, można wyłączyć).

---

## Anulowanie i zwrot zamówienia (korekta)

Gdy zamówienie, które **ma już wystawiony dokument** w Fakturowni, zmieni status na anulowany/zwrócony, wtyczka **zawsze** dopisze wpis w historii zamówienia informujący, że dokument wymaga korekty — niezależnie od ustawień poniżej.

- **Status(y) anulowania/zwrotu** — domyślnie `auto`: wtyczka sama pobiera listę z HikaShop (**System → Statusy zamówień** — wszystkie statusy z zaznaczoną kolumną **„cancelled"**; standardowo `cancelled` i `refunded`, ale obejmuje też Twoje własne statusy z tą flagą, np. `zwrot`). Możesz też wpisać ręcznie klucze statusów (namekey) po przecinku — to nadpisuje tryb auto. Wyczyszczenie pola wyłącza całą funkcję.

  **Po co tryb `auto`?** HikaShop już wie, które statusy oznaczają anulowanie — właśnie po to jest kolumna „cancelled" w Statusach zamówień (używa jej m.in. do zwalniania stanu magazynowego). Bez trybu auto wtyczka trzymałaby **drugą, własną kopię** tej samej listy: tworzysz nowy status zwrotu, zaznaczasz mu ptaszek w HikaShop i… musisz pamiętać, żeby dopisać go też w ustawieniach wtyczki — inaczej korekta cicho nie powstanie. Dwie kopie tej samej informacji zawsze w końcu się rozjadą. `auto` = jedna lista, zarządzana w jednym, natywnym miejscu HikaShop. Ręczną listę wpisuj tylko wtedy, gdy celowo chcesz, żeby wtyczka reagowała na inne statusy niż flagi HikaShop (np. status ma ptaszek „cancelled" dla magazynu, ale nie chcesz dla niego korekt).
- **Automatyczna korekta** (domyślnie **wyłączona**) — gdy włączona, wtyczka sama wystawi w Fakturowni fakturę korygującą **do zera**. Pozycje i dane nabywcy korekta bierze z **dokumentu pobranego z Fakturowni**, a nie z bieżącego zamówienia — dzięki temu zgadza się z fakturą, nawet jeśli zamówienie zmieniono po jej wystawieniu, a KSeF dostaje niezmienione dane nabywcy i powiązanie z korygowaną fakturą. Bez tego ustawienia korektę trzeba wystawić ręcznie w Fakturowni po otrzymaniu powiadomienia.

> **Zwroty częściowe nie są obsługiwane.** Każdy status z listy anulowania/zwrotu oznacza dla wtyczki zwrot **całości** — przy włączonej automatycznej korekcie powstanie korekta do zera. Jeśli zwracasz klientowi tylko część zamówienia, nie ustawiaj takiego statusu (albo wyłącz automatyczną korektę) i wystaw korektę częściową ręcznie w Fakturowni.

> To tworzy prawdziwy dokument księgowy — zanim włączysz automatyczną korektę, przetestuj pełny cykl (zamówienie → faktura → anulowanie → korekta) na koncie testowym Fakturowni.
>
> Korekta zeruje fakturę, ale zarejestrowana wcześniej płatność pozostaje w Fakturowni — zwrot pieniędzy klientowi księgujesz ręcznie.

## Statusy zamówień (w tym własne)

Wtyczka rozpoznaje statusy po **kluczu** (namekey) z **HikaShop → System → Statusy zamówień** — nie po wyświetlanej etykiecie. Działa to też z własnymi statusami, które tam utworzysz:

| Ustawienie wtyczki | Co robi | Domyślnie |
|---|---|---|
| Status wyzwalający wystawienie | przy tym statusie powstaje faktura/paragon | `confirmed` (zamówienie opłacone) |
| Statusy opłacone | decydują, czy dokument dostaje płatność od razu, czy dopiero po przejściu zamówienia na taki status | `auto` — status z flagą „confirmed" w HikaShop |
| Status(y) anulowania/zwrotu | przy tym statusie zamówienie z dokumentem wymaga korekty | `auto` — statusy z flagą „cancelled" w HikaShop |

Pozostałe statusy (`created`, `pending`, `shipped` itd. oraz własne w rodzaju „w przygotowaniu", „oczekuje na odbiór") wtyczka po prostu ignoruje — nic nie wystawia i nie koryguje.

Przykłady z własnymi statusami:

- **„Zapłacono gotówką w sklepie"** (odbiór osobisty) — status oznacza opłacone zamówienie, więc dopisz jego klucz do statusu wyzwalającego, np. `confirmed,zaplacono_gotowka`. Dokument wystawi się przy którymkolwiek z nich (tylko raz — ochrona przed duplikatami).
- **„Zwrot towaru"** — zaznacz mu w HikaShop kolumnę „cancelled", a tryb `auto` wykryje go bez zmian we wtyczce.
- **„Oczekuje na odbiór"** — nic nie rób; status neutralny, wtyczka go ignoruje.

> **Faktury przed zapłatą** (pobranie, przelew B2B): utwórz w HikaShop własny status oznaczający „czeka na płatność" (np. „Nie opłacone" z kluczem `unpaid`) i **dopisz jego klucz** do statusu wyzwalającego, np. `confirmed,unpaid`, oraz ustaw „Termin płatności (dni)" > 0. Dokument wystawi się dla tego statusu **nieopłacony**, a wtyczka **sama dorejestruje płatność**, gdy zamówienie przejdzie na status z listy „Statusów opłaconych" (np. `confirmed`).
>
> **Status wbudowany `pending` zamiast własnego:** HikaShop instaluje domyślnie status `pending` (widoczny np. jako „oczekuje na potwierdzenie") — możesz użyć go zamiast tworzenia własnego, np. `confirmed,pending` w statusie wyzwalającym. Dla wtyczki to bez różnicy — dopasowuje po kluczu, niezależnie czy status jest wbudowany, czy własny.
>
> Jest jednak różnica **po stronie samego HikaShop, nie wtyczki**: przycisk „Zapłać" przy zamówieniu (i możliwość samodzielnego anulowania go przez klienta) pokazuje się tylko dla statusów z zaznaczoną kolumną **„Nie zapłacone"** w System → Statusy zamówień. Domyślnie ma ją zaznaczoną wyłącznie `created` — status `pending` **nie ma** jej zaznaczonej z instalacji, więc klient nie zobaczy przycisku „Zapłać" dla zamówienia w tym statusie, dopóki sam nie zaznaczysz mu tej flagi. Jeśli zamiast tego stworzysz własny status (jak „Nie opłacone" powyżej) i zaznaczysz mu tę kolumnę ręcznie, klient dostanie przycisk „Zapłać" od razu. Wybór między `pending` a własnym statusem sprowadza się więc do tego, czy klient ma móc dopłacić online samodzielnie.
>
> **Uwaga — to funkcja płatnej edycji HikaShop:** przycisk „Zapłać" jest w rdzeniu HikaShop owinięty warunkiem `hikashop_level(1)` (`components/com_hikashop/views/order/view.html.php`) — wymaga edycji **Essential lub wyższej**. Na darmowym **Starterze przycisk nie pojawi się w ogóle**, niezależnie od tego, jak ustawisz kolumnę „Nie zapłacone" ani jaki status wybierzesz. Na Starterze klient nie ma więc żadnego sposobu, by samodzielnie dopłacić za zamówienie z poziomu konta — musisz poinformować go inaczej (e-mail, kontakt) albo ręcznie zmienić status zamówienia po otrzymaniu płatności, żeby wtyczka zarejestrowała ją automatycznie (patrz „Statusy opłacone" wyżej).
>
> Nie wpisuj tu statusu `created` ani nie opieraj się na kolumnie „Unpaid" HikaShopa — `created` to każde świeżo złożone zamówienie (dokument powstawałby dla każdego koszyka), a ta kolumna steruje w HikaShop przyciskiem „Zapłać" i anulowaniem zamówienia na froncie. Wskaż **osobny, własny status**, na który przełączasz zamówienia do rozliczenia po fakcie.

---

## Przycisk „Wystaw ponownie"

W widoku zamówienia w panelu HikaShop (pod listą produktów) wtyczka pokazuje sekcję **Fakturownia**: aktualny stan (ID wystawionego dokumentu i jego status w KSeF / duplikat / błąd / brak dokumentu) oraz przycisk **„Wystaw ponownie w Fakturowni"**. Przycisk czyści znaczniki wystawienia dla tego zamówienia i od razu przepuszcza je przez normalną ścieżkę wystawiania (wszystkie walidacje i ochrona przed duplikatami działają jak zwykle).

Kiedy się przydaje:

- dokument wystawił się z błędnymi danymi → poprawiasz dane w zamówieniu, **usuwasz błędny dokument w Fakturowni**, klikasz „Wystaw ponownie";
- ktoś omyłkowo usunął dokument w Fakturowni, a sklep uważa, że faktura już jest;
- próba wystawienia utknęła (np. chwilowa awaria API).

> **Uwaga:** wtyczka niczego nie usuwa w Fakturowni. Jeśli poprzedni dokument dla tego zamówienia nadal tam istnieje, Fakturownia **odrzuci** drugi (numer zamówienia musi być unikalny), a sekcja pokaże stan „duplikat” — żeby wystawić nowy, najpierw usuń poprzedni w Fakturowni. Zamówienie musi też mieć status wyzwalający (domyślnie `confirmed`), inaczej wystawienie zostanie pominięte.

---

## KSeF

Wtyczka nie wymaga żadnej konfiguracji pod KSeF — wysyłkę e-faktur włącza się **po stronie Fakturowni**: **Ustawienia → Ustawienia konta → KSeF** (tryb automatycznej wysyłki: wyłączona / tylko polskie firmy / wszystkie firmy / wszystkie dokumenty). Przy włączonym trybie automatycznym każda faktura utworzona przez wtyczkę trafia do KSeF tak samo, jak wystawiona ręcznie w panelu. Instrukcja połączenia konta: [Jak połączyć Fakturownię z KSeF (DEMO)](https://pomoc.fakturownia.pl/197913379-Jak-polaczyc-Fakturownie-z-KSeF-DEMO-autoryzacja-integracji-krok-po-kroku).

Co wtyczka robi pod KSeF (od wersji 2.3.0):

- wysyła rodzaj nabywcy (`buyer_company`) — od niego Fakturownia zależy, czy faktura trafi do KSeF automatycznie; osoba prywatna ma imię i nazwisko, firma bez NIP — oznaczenie „brak identyfikatora”;
- wysyła kod kraju nabywcy (np. `PL`) — tryb „Tylko polskie firmy” wysyła faktury z krajem `PL`;
- nie wysyła danych sprzedawcy — Fakturownia bierze je z **Ustawienia → Dane firmy** (KSeF wymaga tam pełnego adresu);
- przycina nazwy pozycji do 256 znaków, a adres nabywcy do 255 (dłuższe kończyły się odrzuceniem dokumentu);
- korektę wiąże z korygowaną fakturą i nie zmienia w niej danych nabywcy (wymóg KSeF).

Status KSeF dokumentu (np. `processing`, `ok`, numer KSeF, błędy walidacji) wtyczka pokazuje w sekcji **Fakturownia** w widoku zamówienia — odczytuje go z Fakturowni przy otwarciu zamówienia.

---

## Debugowanie
W ustawieniach wtyczki można włączyć:
- **Logowanie zamówień** — zapisuje pełne dane zamówienia do logu,
- **Logowanie API** — zapisuje komunikację z Fakturownią.

Od wersji 2.3.0 wtyczka pisze do **dziennika HikaShopa**: **HikaShop → System → Logi**, źródło `fakturownia` — wpisy można filtrować po zamówieniu, a HikaShop sam maskuje wrażliwe dane i usuwa stare wpisy. W HikaShopie starszym niż 6.8.1 te same wpisy trafiają do pliku logu HikaShopa.
Kluczowe zdarzenia cyklu życia (start, wybór typu dokumentu, wynik) zapisywane są zawsze — niezależnie od trybu debug. Błędy, które wymagają reakcji, trafiają też do historii zamówienia.

> Dawny plik `administrator/logs/hikashop_fakturownia.log.php` nie jest już uzupełniany — możesz go usunąć, gdy nie będzie potrzebny. Niechroniony plik z wersji sprzed 2.1.0 (`hikashop_fakturownia.log`) aktualizacja przenosi do chronionego i usuwa.

---

## Aktualizacje

Wtyczka ma wbudowany mechanizm aktualizacji przez Joomla — **Extensions → Manage → Update** → przycisk „Znajdź aktualizacje” pokaże nową wersję, gdy zostanie opublikowana, i pozwoli zainstalować ją jednym kliknięciem (bez ręcznego wgrywania zipa).

Mechanizm działa w oparciu o standardowy update server Joomla (`<updateservers>` w `fakturownia.xml`), który wskazuje na plik manifestu hostowany w tym repozytorium na GitHubie:

```text
https://raw.githubusercontent.com/pablop76/plg_fakturownia/release/updates.xml
```

Gałąź **`release`** jest oddzielona od `main` — `main` to bieżąca praca/rozwój, `release` trzyma wyłącznie przetestowany, opublikowany stan (`updates.xml` + odnośniki do paczek na GitHub Releases). Nic nie trafia tam automatycznie.

### Jak opublikować nową wersję

1. Praca i testy na `main`.
2. Podbij numer `<version>` w `fakturownia.xml`, zaktualizuj `<creationDate>` na datę wydania (Joomla NIE robi tego automatycznie — pole w Extensions → Manage → Manage pokazuje dosłownie to, co jest w XML) oraz numer w `PLG_HIKASHOP_FAKTUROWNIA_CREDIT_LABEL` w obu `language/*/plg_hikashop_fakturownia.ini` (notka w panelu admina), zacommituj.
3. Zbuduj paczkę instalacyjną (zip zawierający pliki z sekcji `<files>` manifestu: `fakturownia.php`, `index.html`, `language/`, `services/`, `src/`, oraz sam `fakturownia.xml` i `script.php`) — **ścieżki w zipie muszą używać `/`, nie `\`** (typowy błąd przy budowaniu zipa w Windows/PowerShell `Compress-Archive` — Joomla odrzuci taki plik komunikatem „Nie udało się rozpakować pliku”).
4. Utwórz **GitHub Release** z tagiem `vX.Y.Z` i załącz zip jako asset.
5. Na gałęzi `release` zaktualizuj `updates.xml`: nowa `<version>`, `<downloadurl>` wskazujący na asset z Release, opcjonalnie `<sha256>` (suma kontrolna paczki — Joomla zweryfikuje integralność pobranego pliku).
6. Commit + push na `release`.
7. W Joomla: Extensions → Manage → Update → „Znajdź aktualizacje” wykryje nową wersję (może być potrzebna chwila na propagację cache CDN GitHuba, zwykle do kilku minut).

> Joomla pokazuje aktualizację tylko wtedy, gdy wersja w `updates.xml` jest **wyższa** niż aktualnie zainstalowana — samo dodanie mechanizmu (bez podbicia wersji) niczego nie pokaże.

---

## Testy

Katalog `tests/` (nie trafia do paczki instalacyjnej):

- `php tests/run.php` — testy bez Joomli i bez sieci: atrapy klas rdzenia, SQLite w pamięci, udawane API Fakturowni odwzorowujące dokumentację (w tym walidację KSeF);
- `php tests/integration.php <katalog lokalnej kopii Joomli>` — test na prawdziwej Joomli, HikaShopie i bazie **lokalnej** kopii sklepu, z udawanym API Fakturowni. Skrypt odmawia pracy, gdy baza nie jest na localhost, odcina inne wtyczki HikaShopa, wyłącza pocztę w pamięci i po przebiegu usuwa wszystkie swoje rekordy.

---

## Uwagi
- Wersja HikaShop Starter nie sprawdza, czy kupon ma pokrycie w towarze. Może to powodować różnice w obliczeniach.
- Przy odinstalowaniu wtyczki dane (kolumny, wpisy) nie są usuwane.

---

## Licencja
Projekt dostępny na licencji **GNU GPL v2**.
