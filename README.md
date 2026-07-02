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

Kod i kwota kuponu (np. „Kupon rabatowy: Kupon1 (-1,00 zł)") można dodatkowo dopisać w polu **„Uwagi"** dokumentu (nie jako pozycja — bezpieczne dla druku fiskalnego) — steruje tym ustawienie wtyczki **„Dopisz kupon w uwagach dokumentu"** (włączone domyślnie, można wyłączyć).

---

## Debugowanie
W ustawieniach wtyczki można włączyć:
- **Logowanie zamówień** — zapisuje pełne dane zamówienia do logu,
- **Logowanie API** — zapisuje komunikację z Fakturownią.

Logi znajdują się w: `administrator/logs/hikashop_fakturownia.log`.
Kluczowe zdarzenia cyklu życia (start, wybór typu dokumentu, wynik) zapisywane są zawsze — niezależnie od trybu debug.

---

## Uwagi
- Wersja HikaShop Starter nie sprawdza, czy kupon ma pokrycie w towarze. Może to powodować różnice w obliczeniach.
- Przy odinstalowaniu wtyczki dane (kolumny, wpisy) nie są usuwane.

---

## Licencja
Projekt dostępny na licencji **GNU GPL v2**.
