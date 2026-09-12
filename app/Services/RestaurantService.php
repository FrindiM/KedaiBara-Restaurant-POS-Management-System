<?php
namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use PDO;
use RuntimeException;

final class RestaurantService
{
    private function settingsMap(PDO $pdo): array
    {
        $rows=$pdo->query('SELECT setting_key,setting_value FROM settings')->fetchAll();
        $out=[]; foreach($rows as $r)$out[$r['setting_key']]=$r['setting_value'];
        return $out;
    }

    private function no(string $prefix): string
    {
        return $prefix.'-'.date('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)),0,6));
    }

    private function activeShiftId(PDO $pdo, ?int $userId=null): ?int
    {
        $userId=$userId ?: Auth::id(); if(!$userId)return null;
        $s=$pdo->prepare("SELECT id FROM cashier_shifts WHERE user_id=? AND status='open' ORDER BY id DESC LIMIT 1");
        $s->execute([$userId]); $id=$s->fetchColumn(); return $id?(int)$id:null;
    }

    public function dashboard(): array
    {
        $pdo=Database::connection();
        $payments=(float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE DATE(paid_at)=CURDATE()")->fetchColumn();
        $refunds=(float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM refunds WHERE DATE(processed_at)=CURDATE()")->fetchColumn();
        $stats=[
            'revenue_today'=>$payments-$refunds,
            'orders_today'=>(int)$pdo->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE() AND status NOT IN ('cancelled','voided')")->fetchColumn(),
            'active_orders'=>(int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='open' AND kitchen_status NOT IN ('completed','cancelled')")->fetchColumn(),
            'pending_reservations'=>(int)$pdo->query("SELECT COUNT(*) FROM reservations WHERE reservation_date>=CURDATE() AND status IN ('pending','confirmed')")->fetchColumn(),
            'occupied_tables'=>(int)$pdo->query("SELECT COUNT(*) FROM restaurant_tables WHERE status='occupied'")->fetchColumn(),
            'low_stock'=>(int)$pdo->query("SELECT COUNT(*) FROM ingredients WHERE status='active' AND current_stock<=min_stock")->fetchColumn(),
            'pending_approvals'=>(int)$pdo->query("SELECT COUNT(*) FROM approval_requests WHERE status='pending'")->fetchColumn(),
        ];
        $sales=$pdo->query("SELECT d, SUM(net) total FROM (SELECT DATE(paid_at)d,amount net FROM payments WHERE paid_at>=DATE_SUB(CURDATE(),INTERVAL 6 DAY) UNION ALL SELECT DATE(processed_at)d,-amount net FROM refunds WHERE processed_at>=DATE_SUB(CURDATE(),INTERVAL 6 DAY))x GROUP BY d ORDER BY d")->fetchAll();
        $top=$pdo->query("SELECT oi.item_name,SUM(oi.quantity) qty,SUM(oi.subtotal) sales FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.payment_status IN ('paid','partially_refunded') AND o.status<>'voided' AND oi.status NOT IN ('cancelled','voided') AND o.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) GROUP BY oi.item_name ORDER BY qty DESC LIMIT 5")->fetchAll();
        $recent=$pdo->query("SELECT o.id,o.order_no,o.customer_name,o.order_type,o.kitchen_status,o.payment_status,o.total,o.created_at,t.table_number FROM orders o LEFT JOIN restaurant_tables t ON t.id=o.table_id ORDER BY o.id DESC LIMIT 10")->fetchAll();
        return compact('stats','sales','top','recent');
    }

    public function tables(): array
    {
        return Database::connection()->query('SELECT * FROM restaurant_tables ORDER BY CAST(table_number AS UNSIGNED),table_number')->fetchAll();
    }

    public function saveTable(array $d): array
    {
        $pdo=Database::connection(); $number=trim((string)($d['table_number']??'')); if($number==='')throw new RuntimeException('Nomor meja wajib diisi.');
        $capacity=max(1,(int)($d['capacity']??2)); $zone=trim((string)($d['zone']??'Indoor'))?:'Indoor'; $status=$d['status']??'available';
        if(!in_array($status,['available','reserved','occupied','cleaning','unavailable'],true))$status='available';
        if(!empty($d['id'])){$id=(int)$d['id'];$pdo->prepare('UPDATE restaurant_tables SET table_number=?,capacity=?,zone=?,status=?,notes=? WHERE id=?')->execute([$number,$capacity,$zone,$status,$d['notes']??null,$id]);}
        else{$pdo->prepare('INSERT INTO restaurant_tables(table_number,qr_token,capacity,zone,status,notes) VALUES(?,?,?,?,?,?)')->execute([$number,md5(bin2hex(random_bytes(16))),$capacity,$zone,$status,$d['notes']??null]);$id=(int)$pdo->lastInsertId();}
        $this->audit('table.save','restaurant_table',$id,$d); return ['id'=>$id];
    }

    public function setTableStatus(int $id,string $status): void
    {
        if(!in_array($status,['available','reserved','occupied','cleaning','unavailable'],true))throw new RuntimeException('Status meja tidak valid.');
        Database::connection()->prepare('UPDATE restaurant_tables SET status=? WHERE id=?')->execute([$status,$id]); $this->audit('table.status','restaurant_table',$id,['status'=>$status]);
    }

    public function customers(): array
    {
        return Database::connection()->query('SELECT * FROM customers ORDER BY id DESC LIMIT 500')->fetchAll();
    }

    public function saveCustomer(array $d): array
    {
        $pdo=Database::connection(); $name=trim((string)($d['name']??'')); if($name==='')throw new RuntimeException('Nama pelanggan wajib diisi.');
        if(!empty($d['id'])){$id=(int)$d['id'];$pdo->prepare('UPDATE customers SET name=?,phone=?,email=?,birthday=?,notes=?,status=? WHERE id=?')->execute([$name,$d['phone']?:null,$d['email']?:null,$d['birthday']?:null,$d['notes']?:null,$d['status']??'active',$id]);}
        else{$code=$this->no('CUS');$pdo->prepare('INSERT INTO customers(customer_code,name,phone,email,birthday,notes,status) VALUES(?,?,?,?,?,?,?)')->execute([$code,$name,$d['phone']?:null,$d['email']?:null,$d['birthday']?:null,$d['notes']?:null,$d['status']??'active']);$id=(int)$pdo->lastInsertId();}
        $this->audit('customer.save','customer',$id,['name'=>$name]); return ['id'=>$id];
    }

    public function reservations(): array
    {
        return Database::connection()->query("SELECT r.*,t.table_number,c.customer_code FROM reservations r LEFT JOIN restaurant_tables t ON t.id=r.table_id LEFT JOIN customers c ON c.id=r.customer_id ORDER BY r.reservation_date DESC,r.reservation_time DESC,r.id DESC LIMIT 300")->fetchAll();
    }

    public function saveReservation(array $d): array
    {
        $name=trim((string)($d['customer_name']??''));$phone=trim((string)($d['phone']??''));$date=(string)($d['reservation_date']??'');$time=(string)($d['reservation_time']??'');
        if($name===''||$phone===''||$date===''||$time==='')throw new RuntimeException('Data reservasi belum lengkap.');
        $guests=max(1,(int)($d['guests']??1));$tableId=!empty($d['table_id'])?(int)$d['table_id']:null;$duration=max(30,(int)($d['duration_minutes']??120));
        return Database::transaction(function(PDO $pdo)use($d,$name,$phone,$date,$time,$guests,$tableId,$duration){
            if($tableId){$c=$pdo->prepare("SELECT COUNT(*) FROM reservations WHERE table_id=? AND reservation_date=? AND status IN ('pending','confirmed','checked_in') AND ABS(TIME_TO_SEC(TIMEDIFF(reservation_time,?))) < GREATEST(duration_minutes,?)*60");$c->execute([$tableId,$date,$time,$duration]);if((int)$c->fetchColumn()>0)throw new RuntimeException('Meja sudah memiliki reservasi pada rentang waktu tersebut.');}
            $customerId=!empty($d['customer_id'])?(int)$d['customer_id']:null;$no=$this->no('RSV');$status=$d['status']??'pending';
            $pdo->prepare('INSERT INTO reservations(reservation_no,customer_id,customer_name,phone,email,reservation_date,reservation_time,duration_minutes,guests,table_id,deposit_amount,status,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$no,$customerId,$name,$phone,$d['email']?:null,$date,$time,$duration,$guests,$tableId,(float)($d['deposit_amount']??0),$status,$d['notes']?:null,Auth::id()]);
            $id=(int)$pdo->lastInsertId();
            if($tableId&&$status==='checked_in')$pdo->prepare("UPDATE restaurant_tables SET status='occupied' WHERE id=?")->execute([$tableId]);
            elseif($tableId&&$status==='confirmed'&&$date===date('Y-m-d'))$pdo->prepare("UPDATE restaurant_tables SET status='reserved' WHERE id=? AND status='available'")->execute([$tableId]);
            $this->audit('reservation.create','reservation',$id,['reservation_no'=>$no]);return ['id'=>$id,'reservation_no'=>$no];
        });
    }

    public function reservationStatus(int $id,string $status): void
    {
        if(!in_array($status,['pending','confirmed','checked_in','completed','cancelled','no_show'],true))throw new RuntimeException('Status reservasi tidak valid.');
        Database::transaction(function(PDO $pdo)use($id,$status){$s=$pdo->prepare('SELECT table_id,reservation_date FROM reservations WHERE id=?');$s->execute([$id]);$r=$s->fetch();if(!$r)throw new RuntimeException('Reservasi tidak ditemukan.');$pdo->prepare('UPDATE reservations SET status=? WHERE id=?')->execute([$status,$id]);if($r['table_id']){if($status==='checked_in')$pdo->prepare("UPDATE restaurant_tables SET status='occupied' WHERE id=?")->execute([$r['table_id']]);elseif(in_array($status,['cancelled','completed','no_show'],true))$pdo->prepare("UPDATE restaurant_tables SET status='available' WHERE id=? AND status IN ('reserved','occupied')")->execute([$r['table_id']]);elseif($status==='confirmed'&&$r['reservation_date']===date('Y-m-d'))$pdo->prepare("UPDATE restaurant_tables SET status='reserved' WHERE id=? AND status='available'")->execute([$r['table_id']]);}$this->audit('reservation.status','reservation',$id,['status'=>$status]);});
    }

    public function menu(): array
    {
        $pdo=Database::connection();
        $items=$pdo->query('SELECT m.*,c.name category_name FROM menu_items m JOIN menu_categories c ON c.id=m.category_id ORDER BY c.sort_order,m.name')->fetchAll();
        $categories=$pdo->query('SELECT * FROM menu_categories WHERE is_active=1 ORDER BY sort_order,name')->fetchAll();
        $groups=$pdo->query('SELECT * FROM modifier_groups ORDER BY name')->fetchAll();
        $options=$pdo->query('SELECT * FROM modifier_options ORDER BY group_id,sort_order,name')->fetchAll();
        $links=$pdo->query('SELECT * FROM menu_item_modifier_groups')->fetchAll();
        return compact('items','categories','groups','options','links');
    }

    public function saveMenu(array $d): array
    {
        $pdo=Database::connection();$name=trim((string)($d['name']??''));$sku=trim((string)($d['sku']??''));$category=(int)($d['category_id']??0);$price=(float)($d['price']??0);
        if($name===''||$sku===''||$category<1||$price<0)throw new RuntimeException('Data menu belum lengkap.');$station=$d['preparation_station']??'kitchen';if(!in_array($station,['kitchen','bar','dessert','other'],true))$station='kitchen';$available=(int)($d['is_available']??1);
        if(!empty($d['id'])){$id=(int)$d['id'];$pdo->prepare('UPDATE menu_items SET category_id=?,sku=?,name=?,description=?,price=?,cost=?,tax_inclusive=?,preparation_station=?,image_url=?,is_available=? WHERE id=?')->execute([$category,$sku,$name,$d['description']?:null,$price,(float)($d['cost']??0),(int)($d['tax_inclusive']??0),$station,$d['image_url']?:null,$available,$id]);}
        else{$pdo->prepare('INSERT INTO menu_items(category_id,sku,name,description,price,cost,tax_inclusive,preparation_station,image_url,is_available) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$category,$sku,$name,$d['description']?:null,$price,(float)($d['cost']??0),(int)($d['tax_inclusive']??0),$station,$d['image_url']?:null,$available]);$id=(int)$pdo->lastInsertId();}
        $this->audit('menu.save','menu_item',$id,['name'=>$name,'price'=>$price]);return ['id'=>$id];
    }

    public function saveModifierGroup(array $d): array
    {
        return Database::transaction(function(PDO $pdo)use($d){$name=trim((string)($d['name']??''));if($name==='')throw new RuntimeException('Nama modifier wajib diisi.');$id=(int)($d['id']??0);if($id)$pdo->prepare('UPDATE modifier_groups SET name=?,min_select=?,max_select=?,is_required=?,is_active=? WHERE id=?')->execute([$name,max(0,(int)($d['min_select']??0)),max(1,(int)($d['max_select']??1)),(int)($d['is_required']??0),(int)($d['is_active']??1),$id]);else{$pdo->prepare('INSERT INTO modifier_groups(name,min_select,max_select,is_required,is_active) VALUES(?,?,?,?,?)')->execute([$name,max(0,(int)($d['min_select']??0)),max(1,(int)($d['max_select']??1)),(int)($d['is_required']??0),(int)($d['is_active']??1)]);$id=(int)$pdo->lastInsertId();}
            $options=json_decode((string)($d['options']??'[]'),true);if(!is_array($options))$options=[];$pdo->prepare('DELETE FROM modifier_options WHERE group_id=?')->execute([$id]);$st=$pdo->prepare('INSERT INTO modifier_options(group_id,name,price_delta,sort_order,is_active) VALUES(?,?,?,?,1)');$sort=10;foreach($options as $o){$on=trim((string)($o['name']??''));if($on==='')continue;$st->execute([$id,$on,(float)($o['price_delta']??0),$sort]);$sort+=10;}$this->audit('modifier.save','modifier_group',$id,['name'=>$name]);return ['id'=>$id];});
    }

    public function recipe(int $menuId): array
    {
        $pdo=Database::connection();$s=$pdo->prepare('SELECT r.*,i.name ingredient_name,i.unit FROM recipes r JOIN ingredients i ON i.id=r.ingredient_id WHERE r.menu_item_id=? ORDER BY i.name');$s->execute([$menuId]);return ['recipe'=>$s->fetchAll(),'ingredients'=>$pdo->query("SELECT id,name,unit,current_stock FROM ingredients WHERE status='active' ORDER BY name")->fetchAll(),'links'=>$pdo->prepare('SELECT modifier_group_id FROM menu_item_modifier_groups WHERE menu_item_id=?')];
    }

    public function saveRecipe(array $d): array
    {
        $menuId=(int)($d['menu_item_id']??0);if($menuId<1)throw new RuntimeException('Menu tidak valid.');$rows=json_decode((string)($d['recipe']??'[]'),true);$groups=json_decode((string)($d['modifier_group_ids']??'[]'),true);if(!is_array($rows))$rows=[];if(!is_array($groups))$groups=[];
        return Database::transaction(function(PDO $pdo)use($menuId,$rows,$groups){$pdo->prepare('DELETE FROM recipes WHERE menu_item_id=?')->execute([$menuId]);$st=$pdo->prepare('INSERT INTO recipes(menu_item_id,ingredient_id,quantity) VALUES(?,?,?)');foreach($rows as $r){$iid=(int)($r['ingredient_id']??0);$q=(float)($r['quantity']??0);if($iid>0&&$q>0)$st->execute([$menuId,$iid,$q]);}$pdo->prepare('DELETE FROM menu_item_modifier_groups WHERE menu_item_id=?')->execute([$menuId]);$lk=$pdo->prepare('INSERT INTO menu_item_modifier_groups(menu_item_id,modifier_group_id) VALUES(?,?)');foreach(array_unique(array_map('intval',$groups)) as $gid)if($gid>0)$lk->execute([$menuId,$gid]);$this->audit('recipe.save','menu_item',$menuId,['rows'=>count($rows),'modifier_groups'=>$groups]);return ['id'=>$menuId];});
    }

    public function recipeDetail(int $menuId): array
    {
        $pdo=Database::connection();$s=$pdo->prepare('SELECT r.ingredient_id,r.quantity,i.name ingredient_name,i.unit FROM recipes r JOIN ingredients i ON i.id=r.ingredient_id WHERE r.menu_item_id=? ORDER BY i.name');$s->execute([$menuId]);$recipe=$s->fetchAll();$s=$pdo->prepare('SELECT modifier_group_id FROM menu_item_modifier_groups WHERE menu_item_id=?');$s->execute([$menuId]);return ['recipe'=>$recipe,'modifier_group_ids'=>array_map('intval',array_column($s->fetchAll(),'modifier_group_id')),'ingredients'=>$pdo->query("SELECT id,name,unit,current_stock FROM ingredients WHERE status='active' ORDER BY name")->fetchAll(),'groups'=>$pdo->query("SELECT * FROM modifier_groups WHERE is_active=1 ORDER BY name")->fetchAll()];
    }

    public function inventory(): array
    {
        $pdo=Database::connection();$items=$pdo->query('SELECT *,current_stock<=min_stock low_stock FROM ingredients ORDER BY name')->fetchAll();$movements=$pdo->query('SELECT im.*,i.name ingredient_name,i.unit,u.name user_name FROM inventory_movements im JOIN ingredients i ON i.id=im.ingredient_id LEFT JOIN users u ON u.id=im.user_id ORDER BY im.id DESC LIMIT 100')->fetchAll();return compact('items','movements');
    }

    public function saveIngredient(array $d): array
    {
        $pdo=Database::connection();$name=trim((string)($d['name']??''));$sku=trim((string)($d['sku']??''));$unit=trim((string)($d['unit']??''));if($name===''||$sku===''||$unit==='')throw new RuntimeException('SKU, nama, dan unit wajib diisi.');if(!empty($d['id'])){$id=(int)$d['id'];$pdo->prepare('UPDATE ingredients SET sku=?,name=?,unit=?,min_stock=?,cost_per_unit=?,status=? WHERE id=?')->execute([$sku,$name,$unit,(float)($d['min_stock']??0),(float)($d['cost_per_unit']??0),$d['status']??'active',$id]);}else{$pdo->prepare('INSERT INTO ingredients(sku,name,unit,current_stock,min_stock,cost_per_unit,status) VALUES(?,?,?,?,?,?,?)')->execute([$sku,$name,$unit,(float)($d['current_stock']??0),(float)($d['min_stock']??0),(float)($d['cost_per_unit']??0),$d['status']??'active']);$id=(int)$pdo->lastInsertId();}$this->audit('ingredient.save','ingredient',$id,['name'=>$name]);return ['id'=>$id];
    }

    public function adjustInventory(array $d): array
    {
        $id=(int)($d['ingredient_id']??0);$type=$d['movement_type']??'in';$qty=(float)($d['quantity']??0);if($id<1||$qty<=0)throw new RuntimeException('Bahan dan jumlah wajib valid.');if(!in_array($type,['in','out','waste','adjustment'],true))throw new RuntimeException('Jenis pergerakan tidak valid.');
        return Database::transaction(function(PDO $pdo)use($id,$type,$qty,$d){$s=$pdo->prepare('SELECT * FROM ingredients WHERE id=? FOR UPDATE');$s->execute([$id]);$i=$s->fetch();if(!$i)throw new RuntimeException('Bahan tidak ditemukan.');$current=(float)$i['current_stock'];$new=$type==='adjustment'?$qty:($type==='in'?$current+$qty:$current-$qty);if($new<0)throw new RuntimeException('Stok tidak boleh negatif.');$pdo->prepare('UPDATE ingredients SET current_stock=? WHERE id=?')->execute([$new,$id]);$pdo->prepare('INSERT INTO inventory_movements(ingredient_id,movement_type,quantity,balance_after,reference_type,notes,user_id) VALUES(?,?,?,?,?,?,?)')->execute([$id,$type,$qty,$new,'manual',$d['notes']?:null,Auth::id()]);$this->audit('inventory.adjust','ingredient',$id,['type'=>$type,'quantity'=>$qty,'balance'=>$new]);return ['balance'=>$new];});
    }

    public function suppliers(): array
    {
        return Database::connection()->query('SELECT * FROM suppliers ORDER BY name')->fetchAll();
    }

    public function saveSupplier(array $d): array
    {
        $pdo=Database::connection();$name=trim((string)($d['name']??''));if($name==='')throw new RuntimeException('Nama supplier wajib diisi.');if(!empty($d['id'])){$id=(int)$d['id'];$pdo->prepare('UPDATE suppliers SET name=?,contact_person=?,phone=?,email=?,address=?,status=? WHERE id=?')->execute([$name,$d['contact_person']?:null,$d['phone']?:null,$d['email']?:null,$d['address']?:null,$d['status']??'active',$id]);}else{$pdo->prepare('INSERT INTO suppliers(name,contact_person,phone,email,address,status) VALUES(?,?,?,?,?,?)')->execute([$name,$d['contact_person']?:null,$d['phone']?:null,$d['email']?:null,$d['address']?:null,$d['status']??'active']);$id=(int)$pdo->lastInsertId();}$this->audit('supplier.save','supplier',$id,['name'=>$name]);return ['id'=>$id];
    }

    public function purchasing(): array
    {
        $pdo=Database::connection();$orders=$pdo->query('SELECT po.*,s.name supplier_name,u.name created_by_name FROM purchase_orders po JOIN suppliers s ON s.id=po.supplier_id LEFT JOIN users u ON u.id=po.created_by ORDER BY po.id DESC LIMIT 200')->fetchAll();return ['orders'=>$orders,'suppliers'=>$this->suppliers(),'ingredients'=>$pdo->query("SELECT id,name,unit,cost_per_unit FROM ingredients WHERE status='active' ORDER BY name")->fetchAll()];
    }

    public function savePurchaseOrder(array $d): array
    {
        $supplier=(int)($d['supplier_id']??0);$items=json_decode((string)($d['items']??'[]'),true);if($supplier<1||!is_array($items)||!$items)throw new RuntimeException('Supplier dan item PO wajib diisi.');
        return Database::transaction(function(PDO $pdo)use($d,$supplier,$items){$no=$this->no('PO');$total=0;$valid=[];foreach($items as $r){$iid=(int)($r['ingredient_id']??0);$q=(float)($r['quantity']??0);$cost=(float)($r['unit_cost']??0);if($iid>0&&$q>0){$sub=$q*$cost;$total+=$sub;$valid[]=[$iid,$q,$cost,$sub];}}if(!$valid)throw new RuntimeException('Tidak ada item PO yang valid.');$pdo->prepare('INSERT INTO purchase_orders(po_no,supplier_id,status,order_date,expected_date,subtotal,notes,created_by) VALUES(?,?,?,?,?,?,?,?)')->execute([$no,$supplier,'ordered',$d['order_date']?:date('Y-m-d'),$d['expected_date']?:null,$total,$d['notes']?:null,Auth::id()]);$id=(int)$pdo->lastInsertId();$st=$pdo->prepare('INSERT INTO purchase_order_items(purchase_order_id,ingredient_id,quantity,unit_cost,subtotal) VALUES(?,?,?,?,?)');foreach($valid as $r)$st->execute([$id,...$r]);$this->audit('purchase_order.create','purchase_order',$id,['po_no'=>$no,'total'=>$total]);return ['id'=>$id,'po_no'=>$no];});
    }

    public function receivePurchaseOrder(int $id): array
    {
        return Database::transaction(function(PDO $pdo)use($id){$s=$pdo->prepare('SELECT * FROM purchase_orders WHERE id=? FOR UPDATE');$s->execute([$id]);$po=$s->fetch();if(!$po)throw new RuntimeException('PO tidak ditemukan.');if(in_array($po['status'],['received','cancelled'],true))throw new RuntimeException('PO tidak dapat diterima.');$s=$pdo->prepare('SELECT poi.*,i.current_stock FROM purchase_order_items poi JOIN ingredients i ON i.id=poi.ingredient_id WHERE poi.purchase_order_id=? FOR UPDATE');$s->execute([$id]);$rows=$s->fetchAll();foreach($rows as $r){$outstanding=(float)$r['quantity']-(float)$r['received_quantity'];if($outstanding<=0)continue;$new=(float)$r['current_stock']+$outstanding;$pdo->prepare('UPDATE ingredients SET current_stock=?,cost_per_unit=? WHERE id=?')->execute([$new,$r['unit_cost'],$r['ingredient_id']]);$pdo->prepare('UPDATE purchase_order_items SET received_quantity=quantity WHERE id=?')->execute([$r['id']]);$pdo->prepare("INSERT INTO inventory_movements(ingredient_id,movement_type,quantity,balance_after,unit_cost,reference_type,reference_id,notes,user_id) VALUES(?,'purchase',?,?,?,?,?,?,?)")->execute([$r['ingredient_id'],$outstanding,$new,$r['unit_cost'],'purchase_order',$id,'Penerimaan '.$po['po_no'],Auth::id()]);}$pdo->prepare("UPDATE purchase_orders SET status='received',received_by=?,received_at=NOW() WHERE id=?")->execute([Auth::id(),$id]);$this->audit('purchase_order.receive','purchase_order',$id,['po_no'=>$po['po_no']]);return ['id'=>$id];});
    }

    public function shifts(): array
    {
        $pdo=Database::connection();$uid=Auth::id();$s=$pdo->prepare("SELECT * FROM cashier_shifts WHERE user_id=? AND status='open' ORDER BY id DESC LIMIT 1");$s->execute([$uid]);$current=$s->fetch()?:null;$history=$pdo->query('SELECT cs.*,u.name user_name FROM cashier_shifts cs JOIN users u ON u.id=cs.user_id ORDER BY cs.id DESC LIMIT 100')->fetchAll();$movements=[];if($current){$s=$pdo->prepare('SELECT * FROM cash_movements WHERE shift_id=? ORDER BY id DESC');$s->execute([$current['id']]);$movements=$s->fetchAll();}$summary=$current?$this->shiftSummary($pdo,(int)$current['id']):null;return compact('current','history','movements','summary');
    }

    private function shiftSummary(PDO $pdo,int $id): array
    {
        $s=$pdo->prepare('SELECT * FROM cashier_shifts WHERE id=?');$s->execute([$id]);$shift=$s->fetch();if(!$shift)return [];$s=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE shift_id=? AND method='cash'");$s->execute([$id]);$cashSales=(float)$s->fetchColumn();$s=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM cash_movements WHERE shift_id=? AND type='in'");$s->execute([$id]);$cashIn=(float)$s->fetchColumn();$s=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM cash_movements WHERE shift_id=? AND type='out'");$s->execute([$id]);$cashOut=(float)$s->fetchColumn();$s=$pdo->prepare("SELECT COALESCE(SUM(r.amount),0) FROM refunds r JOIN payments p ON p.id=r.payment_id WHERE p.shift_id=? AND r.method='cash'");$s->execute([$id]);$cashRefund=(float)$s->fetchColumn();$expected=(float)$shift['opening_cash']+$cashSales+$cashIn-$cashOut-$cashRefund;return compact('cashSales','cashIn','cashOut','cashRefund','expected');
    }

    public function openShift(array $d): array
    {
        $pdo=Database::connection();if($this->activeShiftId($pdo))throw new RuntimeException('Anda masih memiliki shift terbuka.');$pdo->prepare('INSERT INTO cashier_shifts(user_id,opening_cash,notes) VALUES(?,?,?)')->execute([Auth::id(),max(0,(float)($d['opening_cash']??0)),$d['notes']?:null]);$id=(int)$pdo->lastInsertId();$this->audit('shift.open','cashier_shift',$id,[]);return ['id'=>$id];
    }

    public function cashMovement(array $d): array
    {
        $pdo=Database::connection();$shift=$this->activeShiftId($pdo);if(!$shift)throw new RuntimeException('Buka shift kasir terlebih dahulu.');$type=$d['type']??'out';$amount=(float)($d['amount']??0);if(!in_array($type,['in','out'],true)||$amount<=0)throw new RuntimeException('Data cash movement tidak valid.');$pdo->prepare('INSERT INTO cash_movements(shift_id,type,amount,category,notes,created_by) VALUES(?,?,?,?,?,?)')->execute([$shift,$type,$amount,$d['category']?:null,$d['notes']?:null,Auth::id()]);$id=(int)$pdo->lastInsertId();$this->audit('cash.movement','cash_movement',$id,['type'=>$type,'amount'=>$amount]);return ['id'=>$id];
    }

    public function closeShift(array $d): array
    {
        return Database::transaction(function(PDO $pdo)use($d){$id=$this->activeShiftId($pdo);if(!$id)throw new RuntimeException('Tidak ada shift aktif.');$summary=$this->shiftSummary($pdo,$id);$actual=max(0,(float)($d['closing_cash_actual']??0));$diff=$actual-(float)$summary['expected'];$pdo->prepare("UPDATE cashier_shifts SET status='closed',closed_at=NOW(),closing_cash_actual=?,expected_cash=?,difference_amount=?,notes=CONCAT(COALESCE(notes,''),?) WHERE id=?")->execute([$actual,$summary['expected'],$diff,"\n".($d['notes']??''),$id]);$this->audit('shift.close','cashier_shift',$id,['actual'=>$actual,'expected'=>$summary['expected'],'difference'=>$diff]);return ['id'=>$id,'expected'=>$summary['expected'],'difference'=>$diff];});
    }

    public function orders(string $scope='all'): array
    {
        $pdo=Database::connection();$where='1=1';if($scope==='active')$where="o.status='open' AND o.kitchen_status NOT IN ('completed','cancelled')";elseif($scope==='unpaid')$where="o.payment_status IN ('unpaid','partial') AND o.status NOT IN ('cancelled','voided')";
        $sql="SELECT o.*,t.table_number,c.customer_code,COALESCE((SELECT SUM(amount) FROM payments p WHERE p.order_id=o.id),0) paid_amount,COALESCE((SELECT SUM(amount) FROM refunds r WHERE r.order_id=o.id),0) refunded_amount,(o.total-COALESCE((SELECT SUM(amount) FROM payments p2 WHERE p2.order_id=o.id),0)+COALESCE((SELECT SUM(amount) FROM refunds r2 WHERE r2.order_id=o.id),0)) remaining_amount FROM orders o LEFT JOIN restaurant_tables t ON t.id=o.table_id LEFT JOIN customers c ON c.id=o.customer_id WHERE $where ORDER BY o.id DESC LIMIT 300";return $pdo->query($sql)->fetchAll();
    }

    private function modifierData(PDO $pdo,int $menuId,array $selectedIds): array
    {
        $s=$pdo->prepare('SELECT mg.id group_id,mg.name group_name,mg.min_select,mg.max_select,mg.is_required,mo.id option_id,mo.name option_name,mo.price_delta FROM menu_item_modifier_groups l JOIN modifier_groups mg ON mg.id=l.modifier_group_id AND mg.is_active=1 JOIN modifier_options mo ON mo.group_id=mg.id AND mo.is_active=1 WHERE l.menu_item_id=? ORDER BY mg.id,mo.sort_order,mo.id');$s->execute([$menuId]);$rows=$s->fetchAll();$by=[];foreach($rows as $r){$by[$r['group_id']]['meta']=$r;$by[$r['group_id']]['options'][]=$r;}$selected=array_flip(array_map('intval',$selectedIds));$picked=[];$extra=0;foreach($by as $gid=>$g){$groupPicked=[];foreach($g['options'] as $o)if(isset($selected[(int)$o['option_id']]))$groupPicked[]=$o;$count=count($groupPicked);$min=(int)$g['meta']['min_select'];$max=(int)$g['meta']['max_select'];if($count<$min)throw new RuntimeException('Modifier "'.$g['meta']['group_name'].'" minimal '.$min.' pilihan.');if($count>$max)throw new RuntimeException('Modifier "'.$g['meta']['group_name'].'" maksimal '.$max.' pilihan.');foreach($groupPicked as $o){$picked[]=$o;$extra+=(float)$o['price_delta'];}}return ['picked'=>$picked,'extra'=>$extra];
    }

    public function createOrder(array $d): array
    {
        $items=is_array($d['items']??null)?$d['items']:json_decode((string)($d['items']??'[]'),true);if(!is_array($items)||!$items)throw new RuntimeException('Order belum memiliki item.');$type=$d['order_type']??'dine_in';if(!in_array($type,['dine_in','takeaway','delivery'],true))throw new RuntimeException('Tipe order tidak valid.');$tableId=!empty($d['table_id'])?(int)$d['table_id']:null;if($type==='dine_in'&&!$tableId)throw new RuntimeException('Pilih meja untuk Dine In.');
        return Database::transaction(function(PDO $pdo)use($d,$items,$type,$tableId){$normalized=[];$subtotal=0;$q=$pdo->prepare('SELECT * FROM menu_items WHERE id=? AND is_available=1');foreach($items as $it){$mid=(int)($it['menu_id']??$it['menuId']??0);$qty=max(0,(float)($it['qty']??0));if($mid<1||$qty<=0)continue;$q->execute([$mid]);$m=$q->fetch();if(!$m)throw new RuntimeException('Ada menu yang tidak tersedia.');$mods=$this->modifierData($pdo,$mid,(array)($it['modifier_option_ids']??[]));$unit=(float)$m['price']+(float)$mods['extra'];$line=$unit*$qty;$subtotal+=$line;$normalized[]=['menu'=>$m,'qty'=>$qty,'notes'=>$it['notes']??null,'mods'=>$mods['picked'],'unit'=>$unit,'subtotal'=>$line];}if(!$normalized)throw new RuntimeException('Tidak ada item valid.');
            $settings=$this->settingsMap($pdo);$discount=max(0,(float)($d['discount_amount']??0));$promoId=null;$promoCode=trim((string)($d['promo_code']??''));if($promoCode!==''){$s=$pdo->prepare("SELECT * FROM promotions WHERE code=? AND is_active=1 AND (starts_at IS NULL OR starts_at<=NOW()) AND (ends_at IS NULL OR ends_at>=NOW()) LIMIT 1");$s->execute([$promoCode]);$p=$s->fetch();if(!$p)throw new RuntimeException('Kode promo tidak valid/aktif.');if($subtotal<(float)$p['min_spend'])throw new RuntimeException('Minimum transaksi promo belum terpenuhi.');$promoDiscount=$p['discount_type']==='percent'?$subtotal*((float)$p['discount_value']/100):(float)$p['discount_value'];if($p['max_discount']!==null)$promoDiscount=min($promoDiscount,(float)$p['max_discount']);$discount=max($discount,$promoDiscount);$promoId=(int)$p['id'];}
            $taxRate=((float)($settings['tax_rate']??0))/100;$serviceRate=((float)($settings['service_rate']??0))/100;$tax=max(0,($subtotal-$discount)*$taxRate);$service=max(0,($subtotal-$discount)*$serviceRate);$total=max(0,$subtotal-$discount+$tax+$service);$no=$this->no('ORD');$source=$d['source']??(Auth::role()==='waiter'?'waiter':'cashier');$customerId=!empty($d['customer_id'])?(int)$d['customer_id']:null;$shift=$this->activeShiftId($pdo);$waiterId=$source==='waiter'?Auth::id():null;
            $pdo->prepare('INSERT INTO orders(order_no,table_id,customer_id,customer_name,customer_phone,delivery_address,guest_count,order_type,status,kitchen_status,payment_status,subtotal,discount_amount,promotion_id,tax_amount,service_amount,total,notes,source,waiter_id,shift_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$no,$tableId,$customerId,trim((string)($d['customer_name']??''))?:'Guest',$d['customer_phone']?:null,$d['delivery_address']?:null,max(1,(int)($d['guest_count']??1)),$type,'open','new','unpaid',$subtotal,$discount,$promoId,$tax,$service,$total,$d['notes']?:null,$source,$waiterId,$shift]);$orderId=(int)$pdo->lastInsertId();
            $itemSt=$pdo->prepare('INSERT INTO order_items(order_id,menu_item_id,item_name,preparation_station,unit_price,modifier_total,quantity,subtotal,notes,status) VALUES(?,?,?,?,?,?,?,?,?,?)');$modSt=$pdo->prepare('INSERT INTO order_item_modifiers(order_item_id,modifier_option_id,group_name,option_name,price_delta) VALUES(?,?,?,?,?)');foreach($normalized as $n){$modTotal=array_sum(array_map(fn($x)=>(float)$x['price_delta'],$n['mods']));$itemSt->execute([$orderId,$n['menu']['id'],$n['menu']['name'],$n['menu']['preparation_station'],$n['unit'],$modTotal,$n['qty'],$n['subtotal'],$n['notes'],'new']);$oi=(int)$pdo->lastInsertId();foreach($n['mods'] as $m)$modSt->execute([$oi,$m['option_id'],$m['group_name'],$m['option_name'],$m['price_delta']]);}
            if($tableId)$pdo->prepare("UPDATE restaurant_tables SET status='occupied' WHERE id=?")->execute([$tableId]);$this->consumeRecipes($pdo,$orderId,$normalized);$this->audit('order.create','order',$orderId,['order_no'=>$no,'total'=>$total]);return ['id'=>$orderId,'order_no'=>$no,'total'=>$total];});
    }

    private function consumeRecipes(PDO $pdo,int $orderId,array $normalized): void
    {
        $recipe=$pdo->prepare('SELECT r.ingredient_id,r.quantity,i.current_stock,i.name FROM recipes r JOIN ingredients i ON i.id=r.ingredient_id WHERE r.menu_item_id=? FOR UPDATE');$upd=$pdo->prepare('UPDATE ingredients SET current_stock=? WHERE id=?');$mov=$pdo->prepare("INSERT INTO inventory_movements(ingredient_id,movement_type,quantity,balance_after,reference_type,reference_id,notes,user_id) VALUES(?,'out',?,?,?,?,?,?)");foreach($normalized as $n){$recipe->execute([$n['menu']['id']]);foreach($recipe->fetchAll() as $r){$need=(float)$r['quantity']*(float)$n['qty'];$new=(float)$r['current_stock']-$need;if($new<0)throw new RuntimeException('Stok bahan tidak cukup: '.$r['name']);$upd->execute([$new,$r['ingredient_id']]);$mov->execute([$r['ingredient_id'],$need,$new,'order',$orderId,'Pemakaian otomatis '.$n['menu']['name'],Auth::id()]);}}
    }

    private function restoreOrderItemStock(PDO $pdo,array $item,string $reason): void
    {
        if(empty($item['menu_item_id']))return;$s=$pdo->prepare('SELECT r.ingredient_id,r.quantity,i.current_stock FROM recipes r JOIN ingredients i ON i.id=r.ingredient_id WHERE r.menu_item_id=? FOR UPDATE');$s->execute([$item['menu_item_id']]);foreach($s->fetchAll() as $r){$qty=(float)$r['quantity']*(float)$item['quantity'];$new=(float)$r['current_stock']+$qty;$pdo->prepare('UPDATE ingredients SET current_stock=? WHERE id=?')->execute([$new,$r['ingredient_id']]);$pdo->prepare("INSERT INTO inventory_movements(ingredient_id,movement_type,quantity,balance_after,reference_type,reference_id,notes,user_id) VALUES(?,'sale_restore',?,?,?,?,?,?)")->execute([$r['ingredient_id'],$qty,$new,'order_item',$item['id'],$reason,Auth::id()]);}
    }

    public function orderDetail(int $id): array
    {
        $pdo=Database::connection();$s=$pdo->prepare('SELECT o.*,t.table_number,c.customer_code FROM orders o LEFT JOIN restaurant_tables t ON t.id=o.table_id LEFT JOIN customers c ON c.id=o.customer_id WHERE o.id=?');$s->execute([$id]);$order=$s->fetch();if(!$order)throw new RuntimeException('Order tidak ditemukan.');$s=$pdo->prepare('SELECT * FROM order_items WHERE order_id=? ORDER BY id');$s->execute([$id]);$items=$s->fetchAll();$ms=$pdo->prepare('SELECT * FROM order_item_modifiers WHERE order_item_id=? ORDER BY id');foreach($items as &$it){$ms->execute([$it['id']]);$it['modifiers']=$ms->fetchAll();}unset($it);$s=$pdo->prepare('SELECT * FROM payments WHERE order_id=? ORDER BY id');$s->execute([$id]);$payments=$s->fetchAll();$s=$pdo->prepare('SELECT * FROM refunds WHERE order_id=? ORDER BY id');$s->execute([$id]);$refunds=$s->fetchAll();return compact('order','items','payments','refunds');
    }

    private function recomputeOrder(PDO $pdo,int $orderId): void
    {
        $s=$pdo->prepare("SELECT COALESCE(SUM(subtotal),0) FROM order_items WHERE order_id=? AND status NOT IN ('cancelled','voided')");$s->execute([$orderId]);$sub=(float)$s->fetchColumn();$s=$pdo->prepare('SELECT discount_amount FROM orders WHERE id=?');$s->execute([$orderId]);$discount=min($sub,(float)$s->fetchColumn());$settings=$this->settingsMap($pdo);$tax=max(0,($sub-$discount)*((float)($settings['tax_rate']??0)/100));$service=max(0,($sub-$discount)*((float)($settings['service_rate']??0)/100));$total=max(0,$sub-$discount+$tax+$service);$pdo->prepare('UPDATE orders SET subtotal=?,discount_amount=?,tax_amount=?,service_amount=?,total=? WHERE id=?')->execute([$sub,$discount,$tax,$service,$total,$orderId]);
    }

    public function splitOrder(array $d): array
    {
        $orderId=(int)($d['order_id']??0);$ids=json_decode((string)($d['item_ids']??'[]'),true);if(!is_array($ids)||!$ids)throw new RuntimeException('Pilih item yang akan dipisah.');$ids=array_values(array_unique(array_map('intval',$ids)));
        return Database::transaction(function(PDO $pdo)use($orderId,$ids){$s=$pdo->prepare('SELECT * FROM orders WHERE id=? FOR UPDATE');$s->execute([$orderId]);$o=$s->fetch();if(!$o)throw new RuntimeException('Order tidak ditemukan.');$s=$pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE order_id=?');$s->execute([$orderId]);if((float)$s->fetchColumn()>0)throw new RuntimeException('Split bill harus dilakukan sebelum pembayaran.');$ph=implode(',',array_fill(0,count($ids),'?'));$params=array_merge([$orderId],$ids);$s=$pdo->prepare("SELECT id FROM order_items WHERE order_id=? AND id IN ($ph) AND status NOT IN ('voided','cancelled')");$s->execute($params);$valid=array_map('intval',array_column($s->fetchAll(),'id'));if(count($valid)!==count($ids))throw new RuntimeException('Ada item split yang tidak valid.');$s=$pdo->prepare("SELECT COUNT(*) FROM order_items WHERE order_id=? AND status NOT IN ('voided','cancelled')");$s->execute([$orderId]);if(count($ids)>=(int)$s->fetchColumn())throw new RuntimeException('Sisakan minimal satu item pada bill asal.');$no=$this->no('ORD');$pdo->prepare('INSERT INTO orders(order_no,table_id,customer_id,customer_name,customer_phone,delivery_address,guest_count,order_type,status,kitchen_status,payment_status,subtotal,total,notes,source,waiter_id,shift_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$no,$o['table_id'],$o['customer_id'],$o['customer_name'],$o['customer_phone'],$o['delivery_address'],$o['guest_count'],$o['order_type'],'open',$o['kitchen_status'],'unpaid',0,0,'Split dari '.$o['order_no'],$o['source'],$o['waiter_id'],$o['shift_id']]);$newId=(int)$pdo->lastInsertId();$ph=implode(',',array_fill(0,count($ids),'?'));$pdo->prepare("UPDATE order_items SET order_id=? WHERE id IN ($ph)")->execute(array_merge([$newId],$ids));$this->recomputeOrder($pdo,$orderId);$this->recomputeOrder($pdo,$newId);$this->audit('order.split','order',$orderId,['new_order_id'=>$newId,'items'=>$ids]);return ['id'=>$newId,'order_no'=>$no];});
    }

    public function kitchen(string $station='all'): array
    {
        $pdo=Database::connection();$where="o.status='open' AND oi.status IN ('new','preparing','ready','served')";$params=[];if(in_array($station,['kitchen','bar','dessert','other'],true)){$where.=' AND oi.preparation_station=?';$params[]=$station;}$s=$pdo->prepare("SELECT o.id order_id,o.order_no,o.table_id,o.customer_name,o.created_at,t.table_number,oi.id item_id,oi.item_name,oi.preparation_station,oi.quantity,oi.notes,oi.status FROM order_items oi JOIN orders o ON o.id=oi.order_id LEFT JOIN restaurant_tables t ON t.id=o.table_id WHERE $where ORDER BY o.created_at,oi.id");$s->execute($params);$rows=$s->fetchAll();$orders=[];foreach($rows as $r){$oid=$r['order_id'];if(!isset($orders[$oid]))$orders[$oid]=['id'=>$oid,'order_no'=>$r['order_no'],'table_number'=>$r['table_number'],'customer_name'=>$r['customer_name'],'created_at'=>$r['created_at'],'items'=>[]];$orders[$oid]['items'][]=$r;}return array_values($orders);
    }

    public function kitchenItemStatus(int $id,string $status): void
    {
        if(!in_array($status,['new','preparing','ready','served','cancelled'],true))throw new RuntimeException('Status kitchen tidak valid.');$pdo=Database::connection();$s=$pdo->prepare('SELECT order_id FROM order_items WHERE id=?');$s->execute([$id]);$orderId=(int)$s->fetchColumn();if(!$orderId)throw new RuntimeException('Item tidak ditemukan.');$pdo->prepare('UPDATE order_items SET status=? WHERE id=?')->execute([$status,$id]);$s=$pdo->prepare("SELECT status,COUNT(*) c FROM order_items WHERE order_id=? AND status NOT IN ('cancelled','voided') GROUP BY status");$s->execute([$orderId]);$counts=array_column($s->fetchAll(),'c','status');$overall=!empty($counts['new'])?'new':(!empty($counts['preparing'])?'preparing':(!empty($counts['ready'])?'ready':(!empty($counts['served'])?'served':'completed')));$pdo->prepare('UPDATE orders SET kitchen_status=? WHERE id=?')->execute([$overall,$orderId]);$this->audit('kitchen.item_status','order_item',$id,['status'=>$status]);
    }

    public function kitchenStatus(int $id,string $status): void
    {
        if(!in_array($status,['new','preparing','ready','served','completed','cancelled'],true))throw new RuntimeException('Status kitchen tidak valid.');$pdo=Database::connection();$itemStatus=$status==='completed'?'served':$status;$pdo->prepare("UPDATE order_items SET status=? WHERE order_id=? AND status NOT IN ('cancelled','voided')")->execute([$itemStatus,$id]);$pdo->prepare("UPDATE orders SET kitchen_status=?,status=IF(?='completed' AND payment_status='paid','completed',status),completed_at=IF(?='completed' AND payment_status='paid',NOW(),completed_at) WHERE id=?")->execute([$status,$status,$status,$id]);$this->audit('kitchen.status','order',$id,['status'=>$status]);
    }

    public function payments(): array
    {
        $pdo=Database::connection();$unpaid=$this->orders('unpaid');$history=$pdo->query('SELECT p.*,o.order_no,o.customer_name,u.name cashier_name FROM payments p JOIN orders o ON o.id=p.order_id LEFT JOIN users u ON u.id=p.received_by ORDER BY p.id DESC LIMIT 200')->fetchAll();$refunds=$pdo->query('SELECT r.*,o.order_no,u.name processed_by_name FROM refunds r JOIN orders o ON o.id=r.order_id LEFT JOIN users u ON u.id=r.processed_by ORDER BY r.id DESC LIMIT 100')->fetchAll();return compact('unpaid','history','refunds');
    }

    public function addPayment(array $d): array
    {
        $orderId=(int)($d['order_id']??0);$amount=(float)($d['amount']??0);$method=$d['method']??'cash';if($orderId<1||$amount<=0)throw new RuntimeException('Order dan jumlah pembayaran wajib valid.');if(!in_array($method,['cash','qris','transfer','card','other'],true))throw new RuntimeException('Metode pembayaran tidak valid.');
        return Database::transaction(function(PDO $pdo)use($orderId,$amount,$method,$d){$s=$pdo->prepare('SELECT * FROM orders WHERE id=? FOR UPDATE');$s->execute([$orderId]);$o=$s->fetch();if(!$o||in_array($o['status'],['voided','cancelled'],true))throw new RuntimeException('Order tidak dapat dibayar.');$s=$pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE order_id=?');$s->execute([$orderId]);$grossPaid=(float)$s->fetchColumn();$s=$pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM refunds WHERE order_id=?');$s->execute([$orderId]);$refunded=(float)$s->fetchColumn();$paid=$grossPaid-$refunded;$remaining=max(0,(float)$o['total']-$paid);if($amount>$remaining+0.01)throw new RuntimeException('Pembayaran melebihi sisa tagihan Rp '.number_format($remaining,0,',','.'));$shift=$this->activeShiftId($pdo);if(Auth::role()==='cashier'&&!$shift)throw new RuntimeException('Buka shift kasir sebelum menerima pembayaran.');$no=$this->no('PAY');$pdo->prepare('INSERT INTO payments(payment_no,order_id,shift_id,method,amount,reference_no,notes,received_by) VALUES(?,?,?,?,?,?,?,?)')->execute([$no,$orderId,$shift,$method,$amount,$d['reference_no']?:null,$d['notes']?:null,Auth::id()]);$newPaid=$paid+$amount;$status=$newPaid+0.01>=(float)$o['total']?'paid':'partial';$pdo->prepare("UPDATE orders SET payment_status=?,cashier_id=?,status=IF(?='paid' AND kitchen_status IN ('served','completed'),'completed',status),completed_at=IF(?='paid' AND kitchen_status IN ('served','completed'),NOW(),completed_at) WHERE id=?")->execute([$status,Auth::id(),$status,$status,$orderId]);if($status==='paid'){if($o['table_id'])$pdo->prepare("UPDATE restaurant_tables SET status='cleaning' WHERE id=?")->execute([$o['table_id']]);if($o['customer_id']&&$o['payment_status']!=='paid'){$settings=$this->settingsMap($pdo);$points=!empty($settings['loyalty_enabled'])?floor((float)$o['total']/max(1,(float)($settings['loyalty_spend_per_point']??10000))):0;$pdo->prepare('UPDATE customers SET total_spend=total_spend+?,visits=visits+1,loyalty_points=loyalty_points+? WHERE id=?')->execute([$o['total'],$points,$o['customer_id']]);}}$this->audit('payment.create','order',$orderId,['payment_no'=>$no,'amount'=>$amount,'method'=>$method]);return ['payment_no'=>$no,'payment_status'=>$status,'remaining'=>max(0,(float)$o['total']-$newPaid)];});
    }

    public function approvals(): array
    {
        return Database::connection()->query('SELECT ar.*,u.name requested_by_name,d.name decided_by_name FROM approval_requests ar JOIN users u ON u.id=ar.requested_by LEFT JOIN users d ON d.id=ar.decided_by ORDER BY ar.id DESC LIMIT 200')->fetchAll();
    }

    public function requestApproval(array $d): array
    {
        $type=$d['request_type']??'';if(!in_array($type,['void_order','void_item','refund','discount_override'],true))throw new RuntimeException('Jenis approval tidak valid.');$entityId=(int)($d['entity_id']??0);$reason=trim((string)($d['reason']??''));if($entityId<1||$reason==='')throw new RuntimeException('Entity dan alasan wajib diisi.');$entityType=$type==='void_item'?'order_item':'order';$no=$this->no('APR');$payload=$d['payload_json']??null;if(is_array($payload))$payload=json_encode($payload,JSON_UNESCAPED_UNICODE);Database::connection()->prepare('INSERT INTO approval_requests(request_no,request_type,entity_type,entity_id,requested_by,reason,payload_json) VALUES(?,?,?,?,?,?,?)')->execute([$no,$type,$entityType,$entityId,Auth::id(),$reason,$payload]);$id=(int)Database::connection()->lastInsertId();$this->audit('approval.request','approval_request',$id,['type'=>$type]);return ['id'=>$id,'request_no'=>$no];
    }

    public function decideApproval(array $d): array
    {
        if(!in_array(Auth::role(),['superadmin','manager'],true))throw new RuntimeException('Hanya Manager/Super Admin yang dapat memutus approval.');$id=(int)($d['id']??0);$decision=$d['decision']??'';if(!in_array($decision,['approved','rejected'],true))throw new RuntimeException('Keputusan tidak valid.');
        return Database::transaction(function(PDO $pdo)use($id,$decision,$d){$s=$pdo->prepare('SELECT * FROM approval_requests WHERE id=? FOR UPDATE');$s->execute([$id]);$a=$s->fetch();if(!$a||$a['status']!=='pending')throw new RuntimeException('Approval tidak tersedia.');if($decision==='approved')$this->executeApproval($pdo,$a);$pdo->prepare('UPDATE approval_requests SET status=?,decided_by=?,decision_notes=?,decided_at=NOW() WHERE id=?')->execute([$decision,Auth::id(),$d['decision_notes']?:null,$id]);$this->audit('approval.decide','approval_request',$id,['decision'=>$decision]);return ['id'=>$id,'status'=>$decision];});
    }

    private function executeApproval(PDO $pdo,array $a): void
    {
        $payload=json_decode((string)($a['payload_json']??'{}'),true)?:[];
        if($a['request_type']==='void_order'){$s=$pdo->prepare('SELECT * FROM orders WHERE id=? FOR UPDATE');$s->execute([$a['entity_id']]);$o=$s->fetch();if(!$o)throw new RuntimeException('Order tidak ditemukan.');$s=$pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE order_id=?');$s->execute([$o['id']]);$paid=(float)$s->fetchColumn();$s=$pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM refunds WHERE order_id=?');$s->execute([$o['id']]);if($paid-(float)$s->fetchColumn()>0.01)throw new RuntimeException('Order sudah dibayar. Refund dulu sebelum void.');$s=$pdo->prepare("SELECT * FROM order_items WHERE order_id=? AND status NOT IN ('voided','cancelled') FOR UPDATE");$s->execute([$o['id']]);foreach($s->fetchAll() as $it)$this->restoreOrderItemStock($pdo,$it,'Restore karena void order');$pdo->prepare("UPDATE order_items SET status='voided',void_reason=? WHERE order_id=? AND status NOT IN ('voided','cancelled')")->execute([$a['reason'],$o['id']]);$pdo->prepare("UPDATE orders SET status='voided',kitchen_status='cancelled' WHERE id=?")->execute([$o['id']]);if($o['table_id'])$pdo->prepare("UPDATE restaurant_tables SET status='cleaning' WHERE id=?")->execute([$o['table_id']]);}
        elseif($a['request_type']==='void_item'){$s=$pdo->prepare('SELECT oi.*,o.payment_status FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE oi.id=? FOR UPDATE');$s->execute([$a['entity_id']]);$it=$s->fetch();if(!$it)throw new RuntimeException('Item tidak ditemukan.');if($it['payment_status']!=='unpaid')throw new RuntimeException('Item pada bill yang sudah dibayar/partial tidak dapat di-void.');$this->restoreOrderItemStock($pdo,$it,'Restore karena void item');$pdo->prepare("UPDATE order_items SET status='voided',void_reason=? WHERE id=?")->execute([$a['reason'],$it['id']]);$this->recomputeOrder($pdo,(int)$it['order_id']);}
        elseif($a['request_type']==='refund'){$orderId=(int)$a['entity_id'];$amount=(float)($payload['amount']??0);$method=$payload['method']??'cash';$paymentId=!empty($payload['payment_id'])?(int)$payload['payment_id']:null;if($amount<=0||!in_array($method,['cash','qris','transfer','card','other'],true))throw new RuntimeException('Payload refund tidak valid.');if($paymentId){$pv=$pdo->prepare('SELECT COUNT(*) FROM payments WHERE id=? AND order_id=?');$pv->execute([$paymentId,$orderId]);if(!(int)$pv->fetchColumn())throw new RuntimeException('Payment refund tidak sesuai dengan order.');}$s=$pdo->prepare('SELECT * FROM orders WHERE id=? FOR UPDATE');$s->execute([$orderId]);$o=$s->fetch();if(!$o)throw new RuntimeException('Order tidak ditemukan.');$s=$pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE order_id=?');$s->execute([$orderId]);$paid=(float)$s->fetchColumn();$s=$pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM refunds WHERE order_id=?');$s->execute([$orderId]);$done=(float)$s->fetchColumn();if($amount>$paid-$done+0.01)throw new RuntimeException('Refund melebihi pembayaran bersih.');$no=$this->no('REF');$pdo->prepare('INSERT INTO refunds(refund_no,order_id,payment_id,amount,method,reason,processed_by) VALUES(?,?,?,?,?,?,?)')->execute([$no,$orderId,$paymentId,$amount,$method,$a['reason'],Auth::id()]);$net=$paid-$done-$amount;$status=$net<=0.01?'refunded':'partially_refunded';$pdo->prepare('UPDATE orders SET payment_status=? WHERE id=?')->execute([$status,$orderId]);if($o['customer_id']){$settings=$this->settingsMap($pdo);$points=floor($amount/max(1,(float)($settings['loyalty_spend_per_point']??10000)));$pdo->prepare('UPDATE customers SET total_spend=GREATEST(0,total_spend-?),loyalty_points=GREATEST(0,loyalty_points-?) WHERE id=?')->execute([$amount,$points,$o['customer_id']]);}}
    }

    public function reports(string $from,string $to): array
    {
        $pdo=Database::connection();if(!$from)$from=date('Y-m-01');if(!$to)$to=date('Y-m-d');$s=$pdo->prepare("SELECT d,SUM(net) revenue FROM (SELECT DATE(paid_at)d,amount net FROM payments WHERE DATE(paid_at) BETWEEN ? AND ? UNION ALL SELECT DATE(processed_at)d,-amount net FROM refunds WHERE DATE(processed_at) BETWEEN ? AND ?)x GROUP BY d ORDER BY d");$s->execute([$from,$to,$from,$to]);$daily=$s->fetchAll();$s=$pdo->prepare('SELECT method,SUM(amount) total,COUNT(*) count FROM payments WHERE DATE(paid_at) BETWEEN ? AND ? GROUP BY method ORDER BY total DESC');$s->execute([$from,$to]);$byMethod=$s->fetchAll();$s=$pdo->prepare("SELECT oi.item_name,SUM(oi.quantity) qty,SUM(oi.subtotal) sales FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE DATE(o.created_at) BETWEEN ? AND ? AND o.payment_status IN ('paid','partially_refunded') AND o.status<>'voided' AND oi.status NOT IN ('voided','cancelled') GROUP BY oi.item_name ORDER BY qty DESC LIMIT 25");$s->execute([$from,$to]);$topItems=$s->fetchAll();$s=$pdo->prepare("SELECT HOUR(created_at) hour,COUNT(*) orders,SUM(total) sales FROM orders WHERE DATE(created_at) BETWEEN ? AND ? AND status NOT IN ('cancelled','voided') GROUP BY HOUR(created_at) ORDER BY hour");$s->execute([$from,$to]);$hourly=$s->fetchAll();$revenue=array_sum(array_map(fn($x)=>(float)$x['revenue'],$daily));$s=$pdo->prepare("SELECT COUNT(*) FROM orders WHERE DATE(created_at) BETWEEN ? AND ? AND status NOT IN ('cancelled','voided')");$s->execute([$from,$to]);$orders=(int)$s->fetchColumn();$s=$pdo->prepare("SELECT COALESCE(SUM(discount_amount),0) FROM orders WHERE DATE(created_at) BETWEEN ? AND ? AND status NOT IN ('cancelled','voided')");$s->execute([$from,$to]);$discounts=(float)$s->fetchColumn();$s=$pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM refunds WHERE DATE(processed_at) BETWEEN ? AND ?');$s->execute([$from,$to]);$refunds=(float)$s->fetchColumn();$s=$pdo->prepare("SELECT COALESCE(SUM(mi.cost*oi.quantity),0) FROM order_items oi JOIN menu_items mi ON mi.id=oi.menu_item_id JOIN orders o ON o.id=oi.order_id WHERE DATE(o.created_at) BETWEEN ? AND ? AND o.payment_status IN ('paid','partially_refunded') AND oi.status NOT IN ('voided','cancelled')");$s->execute([$from,$to]);$cogs=(float)$s->fetchColumn();$s=$pdo->prepare("SELECT COALESCE(SUM(quantity),0) qty FROM inventory_movements WHERE movement_type='waste' AND DATE(created_at) BETWEEN ? AND ?");$s->execute([$from,$to]);$waste=(float)$s->fetchColumn();return compact('from','to','daily','byMethod','topItems','hourly','revenue','orders','discounts','refunds','cogs','waste');
    }

    public function promotions(): array{return Database::connection()->query('SELECT * FROM promotions ORDER BY id DESC')->fetchAll();}
    public function savePromotion(array $d): array{$pdo=Database::connection();$code=strtoupper(trim((string)($d['code']??'')));$name=trim((string)($d['name']??''));if($code===''||$name==='')throw new RuntimeException('Kode dan nama promo wajib diisi.');if(!empty($d['id'])){$id=(int)$d['id'];$pdo->prepare('UPDATE promotions SET code=?,name=?,discount_type=?,discount_value=?,min_spend=?,max_discount=?,starts_at=?,ends_at=?,is_active=? WHERE id=?')->execute([$code,$name,$d['discount_type']??'percent',(float)($d['discount_value']??0),(float)($d['min_spend']??0),$d['max_discount']!==''?(float)$d['max_discount']:null,$d['starts_at']?:null,$d['ends_at']?:null,(int)($d['is_active']??1),$id]);}else{$pdo->prepare('INSERT INTO promotions(code,name,discount_type,discount_value,min_spend,max_discount,starts_at,ends_at,is_active) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$code,$name,$d['discount_type']??'percent',(float)($d['discount_value']??0),(float)($d['min_spend']??0),$d['max_discount']!==''?(float)$d['max_discount']:null,$d['starts_at']?:null,$d['ends_at']?:null,(int)($d['is_active']??1)]);$id=(int)$pdo->lastInsertId();}$this->audit('promotion.save','promotion',$id,['code'=>$code]);return ['id'=>$id];}

    public function settings(): array{return $this->settingsMap(Database::connection());}
    public function saveSettings(array $d): array{$allowed=['restaurant_name','restaurant_tagline','restaurant_address','restaurant_phone','tax_rate','service_rate','currency','timezone','receipt_width','receipt_footer','loyalty_enabled','loyalty_spend_per_point','reservation_slot_minutes','base_url'];$pdo=Database::connection();$st=$pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');foreach($allowed as $k)if(array_key_exists($k,$d))$st->execute([$k,(string)$d[$k]]);$this->audit('settings.save','settings',null,array_intersect_key($d,array_flip($allowed)));return $this->settings();}

    public function auditLogs(): array
    {
        return Database::connection()->query("SELECT a.*,u.name user_name,u.email user_email FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 500")->fetchAll();
    }

    public function users(): array{$pdo=Database::connection();return ['users'=>$pdo->query('SELECT u.id,u.name,u.email,u.phone,u.status,u.last_login_at,r.id role_id,r.name role_name,r.slug role_slug FROM users u JOIN roles r ON r.id=u.role_id ORDER BY u.name')->fetchAll(),'roles'=>$pdo->query('SELECT * FROM roles ORDER BY id')->fetchAll()];}
    public function saveUser(array $d): array{$pdo=Database::connection();$name=trim((string)($d['name']??''));$email=strtolower(trim((string)($d['email']??'')));$role=(int)($d['role_id']??0);if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||$role<1)throw new RuntimeException('Nama, email, dan role wajib valid.');$status=$d['status']??'active';if(!empty($d['id'])){$id=(int)$d['id'];if(!empty($d['password']))$pdo->prepare('UPDATE users SET role_id=?,name=?,email=?,phone=?,status=?,password=? WHERE id=?')->execute([$role,$name,$email,$d['phone']?:null,$status,password_hash($d['password'],PASSWORD_DEFAULT),$id]);else$pdo->prepare('UPDATE users SET role_id=?,name=?,email=?,phone=?,status=? WHERE id=?')->execute([$role,$name,$email,$d['phone']?:null,$status,$id]);}else{if(empty($d['password']))throw new RuntimeException('Password wajib untuk user baru.');$pdo->prepare('INSERT INTO users(role_id,name,email,password,phone,status) VALUES(?,?,?,?,?,?)')->execute([$role,$name,$email,password_hash($d['password'],PASSWORD_DEFAULT),$d['phone']?:null,$status]);$id=(int)$pdo->lastInsertId();}$this->audit('user.save','user',$id,['name'=>$name,'email'=>$email,'role_id'=>$role]);return ['id'=>$id];}

    public function publicMenu(string $token): array
    {
        $pdo=Database::connection();$s=$pdo->prepare("SELECT id,table_number,status FROM restaurant_tables WHERE qr_token=? AND status<>'unavailable'");$s->execute([$token]);$table=$s->fetch();if(!$table)throw new RuntimeException('QR meja tidak valid.');$menu=$this->menu();$menu['items']=array_values(array_filter($menu['items'],fn($x)=>(int)$x['is_available']===1));return ['table'=>$table]+$menu;
    }

    public function createPublicOrder(array $d): array
    {
        $token=(string)($d['token']??'');$pdo=Database::connection();$s=$pdo->prepare("SELECT id FROM restaurant_tables WHERE qr_token=? AND status<>'unavailable'");$s->execute([$token]);$tableId=(int)$s->fetchColumn();if(!$tableId)throw new RuntimeException('QR meja tidak valid.');$d['table_id']=$tableId;$d['order_type']='dine_in';$d['source']='customer';$d['discount_amount']=0;$d['customer_id']=null;$d['promo_code']=trim((string)($d['promo_code']??''));return $this->createOrder($d);
    }

    private function audit(string $action,?string $entityType=null,?int $entityId=null,array $payload=[]): void
    {
        try{$pdo=Database::connection();$pdo->prepare('INSERT INTO audit_logs(user_id,action,entity_type,entity_id,payload_json,ip_address) VALUES(?,?,?,?,?,?)')->execute([Auth::id(),$action,$entityType,$entityId,json_encode($payload,JSON_UNESCAPED_UNICODE),$_SERVER['REMOTE_ADDR']??null]);}catch(\Throwable $e){}
    }
}
