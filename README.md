# Wtyczka HikaShop – Fakturownia.pl

## Status projektu  
Wtyczka jest w trakcie rozwoju – może zawierać błędy i nie jest jeszcze gotowa do użycia w środowisku produkcyjnym.  

np. nie oblicza poprawnie kuponów / fakturownia nie akceptuje ujemnych wartości

## Opis
Wtyczka umożliwia **automatyczne wystawianie faktur** w systemie [Fakturownia.pl](https://fakturownia.pl) na podstawie zamówień z HikaShop.  

---

## Funkcje
- Automatyczne wystawianie faktur VAT w Fakturowni przy zamówieniach z HikaShop.  
- Przekazywanie danych klienta (imię, nazwisko, nr.telefonu, nazwa firmy, NIP, adres, e-mail).  
- Przekazywanie pozycji zamówienia (produkt, ilość, cena, VAT, rabaty, koszty wysyłki).  

---

## Instalacja i konfiguracja
1. Zainstaluj i włącz wtyczkę w Joomla.  
2. Wejdź w ustawienia i uzupełnij:  
   - **API Token** z konta Fakturownia,  
   - **subdomenę konta** (np. `mojafirma`),  
   - wybierz rodzaj wystawianionego dokumentu faktura/paragon (do paragonu wymagany moduł paragony.pl instalowany w serwisie fakturownia.pl).
  
![pole użytkownika hikashop](https://github.com/pablop76/plg_fakturownia/blob/main/image-2.png?raw=true)
     
3. Dodatkowa opcja
   Wystawia fakturę zawsze, niezależnie od ustawień administratora, jeśli klient tego zażąda.
   Aby skorzystać z tej funkcji, należy utworzyć pole użytkownika w panelu:
   HikaShop → Wyświetlenie → Pola użytkownika
   Nazwa kolumny: invoice_request
   Tabela: address
   W adresie rozliczeniowym klienta pojawi się pole typu checkbox. Zaznaczenie tego pola spowoduje, że system zawsze wystawi fakturę.
   
![pole użytkownika hikashop](https://github.com/pablop76/plg_fakturownia/blob/main/image.png?raw=true)

## Jak korzystać?
1. Klient składa zamówienie w sklepie.  
2. Wtyczka automatycznie po zmianie statusu zamówienia na "Confirmed" wysyła dane do Fakturowni, w której wystawiany jest paragon lub faktura.   

---

## Licencja
Projekt dostępny na licencji **MIT**.

