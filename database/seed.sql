INSERT INTO roles(name,slug) VALUES
('Super Admin','superadmin'),('Owner','owner'),('Manager','manager'),('Cashier','cashier'),('Waiter','waiter'),('Kitchen','kitchen'),('Inventory','inventory'),('Purchasing','purchasing');

INSERT INTO settings(setting_key,setting_value) VALUES
('restaurant_name','Kedai Bara'),('restaurant_tagline','seduh hangat, cerita panjang'),('restaurant_address',''),('restaurant_phone',''),
('tax_rate','0'),('service_rate','0'),('currency','IDR'),('timezone','Asia/Makassar'),('receipt_width','80'),('receipt_footer','Terima kasih telah berkunjung.'),
('loyalty_enabled','1'),('loyalty_spend_per_point','10000'),('reservation_slot_minutes','120'),('base_url','');

INSERT INTO restaurant_tables(table_number,qr_token,capacity,zone,status) VALUES
('1',MD5(CONCAT(UUID(),'1')),2,'Indoor','available'),('2',MD5(CONCAT(UUID(),'2')),4,'Indoor','available'),('3',MD5(CONCAT(UUID(),'3')),2,'Outdoor','available'),('4',MD5(CONCAT(UUID(),'4')),6,'Indoor','available'),
('5',MD5(CONCAT(UUID(),'5')),4,'Outdoor','available'),('6',MD5(CONCAT(UUID(),'6')),2,'VIP','available'),('7',MD5(CONCAT(UUID(),'7')),4,'Indoor','available'),('8',MD5(CONCAT(UUID(),'8')),8,'VIP','available');

INSERT INTO menu_categories(name,sort_order) VALUES
('Kopi',10),('Non-Kopi',20),('Makanan Berat',30),('Snack',40),('Dessert',50);

INSERT INTO menu_items(category_id,sku,name,description,price,cost,preparation_station,is_available) VALUES
(1,'M001','Kopi Susu Gula Aren','Espresso, susu segar, gula aren asli',22000,9500,'bar',1),
(1,'M002','Espresso Tubruk','Racikan biji robusta lokal',18000,6500,'bar',1),
(1,'M003','Cappuccino','Foam lembut, taburan kayu manis',25000,11000,'bar',1),
(1,'M004','V60 Manual Brew','Single origin pilihan minggu ini',28000,12000,'bar',1),
(2,'M005','Matcha Latte','Matcha grade A, susu creamy',27000,12500,'bar',1),
(2,'M006','Cokelat Panas Bara','Dark chocolate 60%, marshmallow',24000,10500,'bar',1),
(2,'M007','Teh Tarik','Manis gurih khas kedai',18000,7000,'bar',1),
(3,'M008','Nasi Goreng Kampung','Bumbu rempah, telur ceplok, kerupuk',32000,15000,'kitchen',1),
(3,'M009','Ayam Geprek Sambal Bara','Ayam crispy, sambal bawang pedas',35000,17500,'kitchen',1),
(3,'M010','Mie Aceh Seafood','Udang, cumi, kuah rempah kental',38000,21000,'kitchen',1),
(3,'M011','Rendang Rice Bowl','Rendang empuk, nasi hangat, sambal ijo',42000,24000,'kitchen',1),
(4,'M012','Roti Bakar Cokelat Keju','Roti tebal, cokelat leleh, keju parut',20000,9000,'kitchen',1),
(4,'M013','Kentang Goreng Sambal Matah','Renyah dengan sambal matah segar',22000,9000,'kitchen',1),
(4,'M014','Pisang Bakar Karamel','Pisang raja, saus karamel, kacang',18000,7500,'kitchen',1),
(5,'M015','Cheese Cake Gula Aren','Lembut dengan sentuhan gula aren',28000,13000,'dessert',1),
(5,'M016','Es Krim Vanila Bourbon','2 scoop, saus karamel asin',20000,8500,'dessert',1);

INSERT INTO modifier_groups(name,min_select,max_select,is_required) VALUES
('Ukuran',1,1,1),('Extra',0,3,0),('Level Pedas',1,1,0);
INSERT INTO modifier_options(group_id,name,price_delta,sort_order) VALUES
(1,'Regular',0,10),(1,'Large',5000,20),(2,'Extra Shot',6000,10),(2,'Extra Cheese',5000,20),(2,'Oat Milk',7000,30),(3,'Tidak Pedas',0,10),(3,'Sedang',0,20),(3,'Pedas',0,30);
INSERT INTO menu_item_modifier_groups(menu_item_id,modifier_group_id) SELECT id,1 FROM menu_items WHERE category_id IN (1,2);
INSERT INTO menu_item_modifier_groups(menu_item_id,modifier_group_id) SELECT id,2 FROM menu_items WHERE category_id IN (1,2,3,4);
INSERT INTO menu_item_modifier_groups(menu_item_id,modifier_group_id) SELECT id,3 FROM menu_items WHERE id IN (8,9,10);

INSERT INTO ingredients(sku,name,unit,current_stock,min_stock,cost_per_unit) VALUES
('I001','Coffee Beans','gram',5000,1000,180),('I002','Fresh Milk','ml',12000,3000,18),('I003','Palm Sugar','ml',5000,1000,16),
('I004','Rice','gram',15000,4000,14),('I005','Chicken','gram',10000,2500,45),('I006','Tea','gram',2500,500,80),
('I007','Bread','slice',180,40,1800),('I008','Potato','gram',10000,2500,22),('I009','Chocolate','gram',4000,800,60);

INSERT INTO recipes(menu_item_id,ingredient_id,quantity) VALUES
(1,1,18),(1,2,150),(1,3,25),(3,1,18),(3,2,180),(7,6,8),(8,4,200),(9,4,180),(9,5,180),(12,7,2),(13,8,180),(6,2,180),(6,9,30);

INSERT INTO suppliers(name,contact_person,phone,email,status) VALUES
('Supplier Utama Kedai Bara','Sales Supplier','081234567890','supplier@example.com','active');

INSERT INTO promotions(code,name,discount_type,discount_value,min_spend,max_discount,is_active) VALUES
('WELCOME10','Welcome 10%','percent',10,50000,30000,1);
