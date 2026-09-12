# Kedai Bara Pro — Restaurant POS & Management System

Versi profesional dari prototype Kedai Bara. Aplikasi menggunakan **PHP 8.1+**, **MySQL 8+/MariaDB modern**, jQuery/AJAX, session authentication, CSRF protection, prepared statements, database transactions, dan role-based access.

## Modul utama

- Dashboard operasional dan KPI
- User & role: Super Admin, Owner, Manager, Cashier, Waiter, Kitchen, Inventory, Purchasing
- Manajemen meja + status real-time + QR ordering unik per meja
- Reservasi, assignment meja, check-in, no-show, deposit
- Customer/CRM ringan + loyalty points + total spend + blacklist
- POS dine-in, takeaway, delivery
- Modifier/topping/variant dan catatan item
- Promo/voucher percent/fixed
- Kitchen Display System per item dan per station (Kitchen/Bar/Dessert/Other)
- Menu, kategori, recipe/BOM, station produksi
- Inventory, minimum stock, stock in/out, adjustment, waste, automatic recipe consumption
- Supplier & Purchase Order, receiving otomatis menambah stok dan update cost
- Cashier shift: opening cash, cash in/out, expected cash, closing cash, selisih
- Partial payment & multi metode pembayaran
- Split bill berdasarkan item sebelum pembayaran
- Void order/item dan refund melalui Manager Approval
- Thermal receipt 58/80 mm
- Laporan net sales, refund, discount, COGS, gross profit, payment method, hourly sales, top menu
- CSV export laporan
- Audit log untuk operasi penting
- System settings: identitas restoran, tax, service, loyalty, receipt, base URL QR

## Instalasi fresh

Aplikasi ini dibuat sebagai **fresh install final schema**. Tidak perlu menjalankan migration dari versi sebelumnya.

1. Extract ZIP ke server.
2. Buat database MySQL kosong.
3. Edit `config/database.php`.
4. Arahkan document root domain/subdomain ke folder `public/`.
5. Pastikan folder `storage/` writable oleh PHP.
6. Buka `https://domain-anda/setup.php`.
7. Isi akun Super Admin dan klik **Install Database Fresh**.
8. Installer membuat `storage/install.lock`, sehingga setup tidak bisa dijalankan ulang secara tidak sengaja.
9. Login dari `index.php?page=login`.
10. Buka **Pengaturan Restoran** dan isi `Base URL` untuk QR ordering, misalnya `https://pos.domainanda.com`.

## QR Ordering

Di **Meja & QR**, klik tombol **QR** pada meja. Print QR tersebut. Customer membuka `order.php?t=TOKEN`, memilih menu/modifier dan mengirim order. Order masuk langsung ke KDS dengan source `customer`.

## Alur operasional

Reservasi / walk-in → meja occupied → order dibuat → recipe mengurangi stok → KDS memproses item → served → pembayaran → meja cleaning → selesai cleaning → available.

Transaksi sensitif mengikuti alur: cashier/waiter mengajukan request → Manager/Super Admin approve/reject → sistem mengeksekusi void/refund dan menyimpan audit trail.

## Catatan produksi

- Gunakan HTTPS.
- Ganti akun/password default setelah instalasi.
- Batasi akses `setup.php` di web server bila memungkinkan; install lock sudah mencegah reset biasa.
- Lakukan backup database terjadwal dari panel hosting/server.
- QR page menggunakan `qrcodejs` dari CDN; jika ingin full-offline, simpan library tersebut secara lokal di `public/assets/vendor/`.
- jQuery dan Google Fonts juga masih menggunakan CDN.

## Struktur

- `app/Core` — database/auth/csrf/response
- `app/Controllers` — API dispatcher
- `app/Services` — business logic restoran
- `app/Views` — UI per modul
- `database/schema.sql` — schema final fresh install
- `database/seed.sql` — seed dasar
- `public/` — web root
- `storage/` — install lock dan area runtime
- `docs/prototype-original.html` — prototype awal

## Scope versi 2.0

Versi 2.0 adalah **versi operasional lengkap untuk satu outlet**. Integrasi yang membutuhkan akun/credential pihak ketiga—misalnya payment gateway QRIS otomatis, WhatsApp Business API, email transactional, marketplace delivery, cloud backup, atau sinkronisasi multi-outlet—tidak di-hardcode. Fondasi data/API sudah dibuat agar integrasi tersebut dapat ditambahkan tanpa mengubah alur POS utama.
