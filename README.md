# Wtyczka HikaShop – Fakturownia.pl

## Status projektu  
Wtyczka jest w trakcie rozwoju – może zawierać błędy i nie jest jeszcze gotowa do użycia w środowisku produkcyjnym.  

## Opis
Wtyczka umożliwia **automatyczne wystawianie faktur** w systemie [Fakturownia.pl](https://fakturownia.pl) na podstawie zamówień z HikaShop.  

---

## Funkcje
- Automatyczne wystawianie faktur VAT w Fakturowni przy zamówieniach z HikaShop.  
- Przekazywanie danych klienta (imię, nazwisko, firma, NIP, adres, e-mail).  
- Przekazywanie pozycji zamówienia (produkt, ilość, cena, VAT, rabaty, koszty wysyłki, kupony).  

---

## Instalacja i konfiguracja
1. Zainstaluj i włącz wtyczkę w Joomla.  
2. Wejdź w ustawienia i uzupełnij:  
   - **API Token** z konta Fakturownia,  
   - **subdomenę konta** (np. `mojafirma`),  
   - wybierz rodzaj wystawianionego dokumentu faktura/paragon (do paragonu wymagany moduł paragony.pl instalowany w serwisie fakturownia.pl).
3. Dodatkowa opcja 
   - Wystawia zawsze fakturę niezależeni od ustawień administratora na życzenie klienta.
     Należy utwórzyć pole użytkownika (hikashop->Wyświetlenie->Pola użytkownika)
     nazwa kolumny 'invoice_request',
     tabela 'address' w HikaShop.
     W adresie rozliczeniowym klienta pojawi się checkbox którego zaznaczenie spowoduje, że zawsze zostanie wystawiona faktura.
     ![pole użytkownika hikashop](https://github.com/pablop76/plg_fakturownia/blob/main/image.jpg?raw=true)


---

## Jak korzystać?
1. Klient składa zamówienie w sklepie.  
2. Wtyczka automatycznie po zmianie statusu zamówienia na "Confirmed" wysyła dane do Fakturowni, w której wystawiany jest paragon lub faktura.   

---

## Licencja
Projekt dostępny na licencji **MIT**.

