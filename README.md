# Wtyczka HikaShop → Fakturownia.pl

> ⚠️ **UWAGA:** Ta wtyczka nie jest przeznaczona do użytku komercyjnego. Nie przeszła wszystkich testów i może zawierać błędy. Używasz na własną odpowiedzialność.

## Opis
Wtyczka umożliwia **automatyczne wystawianie faktur** w systemie [Fakturownia.pl](https://fakturownia.pl) na podstawie zamówień z HikaShop.

---

## Funkcje
- Automatyczne wystawianie faktur VAT lub paragonów w Fakturowni przy zamówieniach z HikaShop.
- Przekazywanie danych klienta (imię, nazwisko, telefon, nazwa firmy, NIP, adres, e-mail).
- Przekazywanie pozycji zamówienia (produkt, ilość, cena, VAT, rabaty, koszty wysyłki, kupony).
- **Pole „Chcę otrzymać fakturę VAT"** (pole adresu rozliczeniowego) — klient sam decyduje o fakturze, nawet bez NIP.
- **Walidacja NIP nabywcy** — czyści formatowanie i sprawdza sumę kontrolną; błędny NIP nie blokuje wystawienia (faktura idzie bez NIP).
- **Konfigurowalny status zamówienia** wyzwalający wystawienie (domyślnie „confirmed").
- **Ochrona przed duplikatami** — to samo zamówienie nie wystawi dwóch faktur.
- Opcjonalne automatyczne wysyłanie faktury e-mailem do klienta.
- **Powiadomienie o konieczności korekty** przy anulowaniu/zwrocie zamówienia po wystawieniu dokumentu, z opcją automatycznego wystawienia korekty do zera.
- **Przycisk „Wystaw ponownie"** w widoku zamówienia w panelu — wymusza ponowne wystawienie dokumentu (np. po poprawieniu danych i usunięciu błędnego dokumentu w Fakturowni).
- **Konfigurowalny termin płatności** (domyślnie 0 dni — zamówienie jest już opłacone).
- Stawki VAT wysyłki i kosztu płatności pobierane **z zamówienia HikaShop** (kategorie podatkowe metod wysyłki/płatności) — dokument odzwierciedla to, co klient faktycznie zapłacił.

---

## Wymagania
- Joomla 5.0+
- PHP 8.1+
- HikaShop (zainstalowany przed instalacją wtyczki)
- Konto w serwisie Fakturownia.pl

---

## Instalacja i konfiguracja
1. Zainstaluj i włącz wtyczkę w Joomla.
2. Wejdź w ustawienia wtyczki i uzupełnij:
   - **API Token** z konta Fakturownia (Ustawienia → Ustawienia konta → Integracja)
   - **Subdomena konta** (np. `mojafirma` — bez `.fakturownia.pl`)
   - **Nazwa firmy sprzedawcy** (zgodna z wpisem w Fakturowni)
   - **NIP sprzedawcy**
   - **Status wyzwalający** — przy jakim statusie zamówienia wystawić dokument (domyślnie `confirmed`; można podać kilka po przecinku, np. `confirmed,shipped`)
   - **Termin płatności (dni)** — data „Termin płatności" na dokumencie, liczona od wystawienia (domyślnie `0`, bo zamówienie przy statusie `confirmed` jest już opłacone)
   - **Typ dokumentu:**
     - **Faktura VAT** — każde zamówienie dostaje fakturę,
     - **Auto** — paragon domyślnie, a faktura gdy klient poda NIP lub zaznaczy „Chcę fakturę VAT" (wymaga modułu paragony.pl).
   - **Automatycznie wysyłaj fakturę e-mailem** — po wystawieniu faktura zostanie wysłana na adres e-mail klienta z poziomu Fakturowni.
3. **(Opcjonalnie) Utwórz pole „Chcę otrzymać fakturę VAT"** — pozwala klientowi bez NIP-u poprosić o fakturę. Zobacz sekcję *„Pole »Chcę otrzymać fakturę VAT«"* poniżej.

   > **Uwaga:** Paragony wymagają modułu paragony.pl zainstalowanego w serwisie Fakturownia.pl.

---

## Pole „Chcę otrzymać fakturę VAT"

Pole pozwala klientowi **bez NIP-u** poprosić o fakturę VAT. Bez niego faktura i tak wystawia się, gdy klient poda NIP. Tworzy się je **ręcznie** w HikaShop: **Wyświetlanie → Pola użytkownika → Utwórz**, i ustawia tak:

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
   - tworzy lub aktualizuje klienta w Fakturowni,
   - wystawia fakturę VAT lub paragon,
   - rejestruje płatność,
   - opcjonalnie wysyła fakturę e-mailem,
   - dodaje produkty do katalogu Fakturowni.

---

## Rabaty i kupony na paragonie fiskalnym

Kupon rabatowy (`order_discount_code`/`order_discount_price` z HikaShop) **nie jest** wysyłany jako osobna pozycja z ujemną ceną. Kasy/drukarki fiskalne (moduł paragony.pl) odrzucają taką pozycję błędem *„Niepoprawna cena (cena musi być dodatnia)"* — to wymóg protokołu fiskalnego: rabat to osobny typ operacji, nie sprzedaż towaru za ujemną kwotę. Zgodnie z [API Fakturowni](https://pomoc.fakturownia.pl/374462-api-dodawanie-faktur-z-rabatem-kwotowym-lub-procentowym) wtyczka rozkłada wartość kuponu **proporcjonalnie jako pole `discount` na już istniejących pozycjach** (produkty, wysyłka, koszt płatności) — Fakturownia sama odejmuje tę kwotę od wartości brutto pozycji przy wyliczaniu dokumentu. Suma rabatów zawsze zgadza się co do grosza z wartością kuponu (reszta zaokrąglenia trafia do ostatniej pozycji).

> Jeśli mimo to rabat na dokumencie wygląda źle, sprawdź: **Ustawienia → Ustawienia konta → Konfiguracja → Sposoby wyliczania → „Jak obliczać rabat"** — wtyczka wysyła `discount_kind: "amount"` (rabat kwotowy) per dokument, co powinno nadpisać ustawienie konta, ale w razie wątpliwości ustaw tam też „kwotowo".

Kod i kwota kuponu (np. „Kupon rabatowy: Kupon1 (-1,00 PLN)" — w walucie zamówienia) można dodatkowo dopisać w polu **„Uwagi"** dokumentu (nie jako pozycja — bezpieczne dla druku fiskalnego) — steruje tym ustawienie wtyczki **„Dopisz kupon w uwagach dokumentu"** (włączone domyślnie, można wyłączyć).

---

## Anulowanie i zwrot zamówienia (korekta)

Gdy zamówienie, które **ma już wystawiony dokument** w Fakturowni, zmieni status na anulowany/zwrócony, wtyczka **zawsze** dopisze wpis w historii zamówienia informujący, że dokument wymaga korekty — niezależnie od ustawień poniżej.

- **Status(y) anulowania/zwrotu** — domyślnie `auto`: wtyczka sama pobiera listę z HikaShop (**System → Statusy zamówień** — wszystkie statusy z zaznaczoną kolumną **„cancelled"**; standardowo `cancelled` i `refunded`, ale obejmuje też Twoje własne statusy z tą flagą, np. `zwrot`). Możesz też wpisać ręcznie klucze statusów (namekey) po przecinku — to nadpisuje tryb auto. Wyczyszczenie pola wyłącza całą funkcję.

  **Po co tryb `auto`?** HikaShop już wie, które statusy oznaczają anulowanie — właśnie po to jest kolumna „cancelled" w Statusach zamówień (używa jej m.in. do zwalniania stanu magazynowego). Bez trybu auto wtyczka trzymałaby **drugą, własną kopię** tej samej listy: tworzysz nowy status zwrotu, zaznaczasz mu ptaszek w HikaShop i… musisz pamiętać, żeby dopisać go też w ustawieniach wtyczki — inaczej korekta cicho nie powstanie. Dwie kopie tej samej informacji zawsze w końcu się rozjadą. `auto` = jedna lista, zarządzana w jednym, natywnym miejscu HikaShop. Ręczną listę wpisuj tylko wtedy, gdy celowo chcesz, żeby wtyczka reagowała na inne statusy niż flagi HikaShop (np. status ma ptaszek „cancelled" dla magazynu, ale nie chcesz dla niego korekt).
- **Automatyczna korekta** (domyślnie **wyłączona**) — gdy włączona, wtyczka sama wystawi w Fakturowni fakturę korygującą **do zera** (pełny zwrot/anulowanie całości — bez obsługi zwrotów częściowych). Bez tego ustawienia korektę trzeba wystawić ręcznie w Fakturowni po otrzymaniu powiadomienia.

> To tworzy prawdziwy dokument księgowy — zanim włączysz automatyczną korektę, przetestuj pełny cykl (zamówienie → faktura → anulowanie → korekta) na koncie testowym Fakturowni.
>
> Korekta zeruje fakturę, ale zarejestrowana wcześniej płatność pozostaje w Fakturowni — zwrot pieniędzy klientowi księgujesz ręcznie.

## Statusy zamówień (w tym własne)

Wtyczka rozpoznaje statusy po **kluczu** (namekey) z **HikaShop → System → Statusy zamówień** — nie po wyświetlanej etykiecie. Działa to też z własnymi statusami, które tam utworzysz:

| Ustawienie wtyczki | Co robi | Domyślnie |
|---|---|---|
| Status wyzwalający wystawienie | przy tym statusie powstaje faktura/paragon | `confirmed` (zamówienie opłacone) |
| Status(y) anulowania/zwrotu | przy tym statusie zamówienie z dokumentem wymaga korekty | `auto` — statusy z flagą „cancelled" w HikaShop |

Pozostałe statusy (`created`, `pending`, `shipped` itd. oraz własne w rodzaju „w przygotowaniu", „oczekuje na odbiór") wtyczka po prostu ignoruje — nic nie wystawia i nie koryguje.

Przykłady z własnymi statusami:

- **„Zapłacono gotówką w sklepie"** (odbiór osobisty) — status oznacza opłacone zamówienie, więc dopisz jego klucz do statusu wyzwalającego, np. `confirmed,zaplacono_gotowka`. Dokument wystawi się przy którymkolwiek z nich (tylko raz — ochrona przed duplikatami).
- **„Zwrot towaru"** — zaznacz mu w HikaShop kolumnę „cancelled", a tryb `auto` wykryje go bez zmian we wtyczce.
- **„Oczekuje na odbiór"** — nic nie rób; status neutralny, wtyczka go ignoruje.

> **Ważne:** do statusu wyzwalającego dopisuj **tylko statusy oznaczające opłacone zamówienie** — wtyczka wraz z fakturą od razu rejestruje w Fakturowni płatność. Status w rodzaju „wysłane za pobraniem" (płatność dopiero przy odbiorze) zarejestrowałby płatność, której jeszcze nie ma.

---

## Przycisk „Wystaw ponownie"

W widoku zamówienia w panelu HikaShop (pod listą produktów) wtyczka pokazuje sekcję **Fakturownia**: aktualny stan (ID wystawionego dokumentu / błąd / brak dokumentu) oraz przycisk **„Wystaw ponownie w Fakturowni"**. Przycisk czyści znaczniki wystawienia dla tego zamówienia i od razu przepuszcza je przez normalną ścieżkę wystawiania (wszystkie walidacje i ochrona przed duplikatami działają jak zwykle).

Kiedy się przydaje:

- dokument wystawił się z błędnymi danymi → poprawiasz dane w zamówieniu, **usuwasz błędny dokument w Fakturowni**, klikasz „Wystaw ponownie";
- ktoś omyłkowo usunął dokument w Fakturowni, a sklep uważa, że faktura już jest;
- próba wystawienia utknęła (np. chwilowa awaria API).

> **Uwaga:** wtyczka niczego nie usuwa w Fakturowni. Jeśli poprzedni dokument nadal tam istnieje, powstanie **duplikat** — dlatego przed ponownym wystawieniem usuń go w Fakturowni (przycisk pokazuje to ostrzeżenie przed potwierdzeniem). Zamówienie musi też mieć status wyzwalający (domyślnie `confirmed`), inaczej wystawienie zostanie pominięte.

---

## KSeF

Wtyczka nie wymaga żadnej konfiguracji pod KSeF — wysyłkę e-faktur włącza się **po stronie Fakturowni**: **Ustawienia → Ustawienia konta → KSeF** (tryb automatycznej wysyłki: wyłączona / tylko polskie firmy / wszystkie firmy / wszystkie dokumenty). Przy włączonym trybie automatycznym każda faktura utworzona przez wtyczkę trafia do KSeF tak samo, jak wystawiona ręcznie w panelu. Instrukcja połączenia konta: [Jak połączyć Fakturownię z KSeF (DEMO)](https://pomoc.fakturownia.pl/197913379-Jak-polaczyc-Fakturownie-z-KSeF-DEMO-autoryzacja-integracji-krok-po-kroku).

> Status wysyłki do KSeF (numer KSeF, ewentualne błędy walidacji) sprawdzisz w panelu Fakturowni przy dokumencie — wtyczka nie raportuje go w sklepie.

---

## Debugowanie
W ustawieniach wtyczki można włączyć:
- **Logowanie zamówień** — zapisuje pełne dane zamówienia do logu,
- **Logowanie API** — zapisuje komunikację z Fakturownią.

Logi znajdują się w: `administrator/logs/hikashop_fakturownia.log.php` — otwórz przez FTP/menedżer plików. Plik ma rozszerzenie `.php` i nagłówek blokujący **celowo**: katalog logów Joomli nie jest chroniony przed pobraniem z przeglądarki, a log zawiera dane osobowe klientów (tak samo zabezpiecza swoje logi rdzeń Joomli).
Kluczowe zdarzenia cyklu życia (start, wybór typu dokumentu, wynik) zapisywane są zawsze — niezależnie od trybu debug.

---

## Uwagi
- Wersja HikaShop Starter nie sprawdza, czy kupon ma pokrycie w towarze. Może to powodować różnice w obliczeniach.
- Przy odinstalowaniu wtyczki dane (kolumny, wpisy) nie są usuwane.

---

## Licencja
Projekt dostępny na licencji **GNU GPL v2**.
