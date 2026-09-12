<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Response;
use App\Services\RestaurantService;
use RuntimeException;

final class ApiController
{
    private RestaurantService $service;
    public function __construct(){ $this->service=new RestaurantService(); }
    public function handle(string $resource,string $action,array $input): never
    {
        try{
            if($resource==='auth'&&$action==='login'){if(!Csrf::validate($input['_csrf']??null))Response::error('Sesi form tidak valid. Muat ulang halaman.',419);$now=time();if(($_SESSION['login_block_until']??0)>$now)Response::error('Terlalu banyak percobaan login. Coba lagi sebentar.',429);if(Auth::attempt((string)($input['email']??''),(string)($input['password']??''))){unset($_SESSION['login_fail_count'],$_SESSION['login_block_until']);Response::ok(['redirect'=>'index.php?page=dashboard'],'Login berhasil.');}$fails=(int)($_SESSION['login_fail_count']??0)+1;$_SESSION['login_fail_count']=$fails;if($fails>=5){$_SESSION['login_block_until']=$now+60;$_SESSION['login_fail_count']=0;}Response::error('Email atau password salah.',401);}
            if($resource==='auth'&&$action==='logout'){if(!Csrf::validate($input['_csrf']??($_SERVER['HTTP_X_CSRF_TOKEN']??null)))Response::error('CSRF token tidak valid.',419);Auth::logout();Response::ok(['redirect'=>'index.php?page=login'],'Logout berhasil.');}
            if(!Auth::check())Response::error('Sesi login berakhir.',401);
            $pageMap=['dashboard'=>'dashboard','tables'=>'tables','reservations'=>'reservations','customers'=>'customers','menu'=>'menu','inventory'=>'inventory','purchasing'=>'purchasing','orders'=>'pos','kitchen'=>'kitchen','shifts'=>'shifts','payments'=>'payments','approvals'=>'approvals','promotions'=>'promotions','reports'=>'reports','audits'=>'audits','users'=>'users','settings'=>'settings'];
            if($resource==='approvals'&&$action==='request'){if(!(Auth::canAccess('pos')||Auth::canAccess('payments')))Response::error('Anda tidak memiliki izin mengajukan approval.',403);}elseif(isset($pageMap[$resource])&&!Auth::canAccess($pageMap[$resource]))Response::error('Anda tidak memiliki izin untuk endpoint ini.',403);
            if($_SERVER['REQUEST_METHOD']!=='GET'&&!Csrf::validate($input['_csrf']??($_SERVER['HTTP_X_CSRF_TOKEN']??null)))Response::error('CSRF token tidak valid.',419);
            $result=match($resource.':'.$action){
                'dashboard:list'=>$this->service->dashboard(),
                'tables:list'=>$this->service->tables(),'tables:save'=>$this->service->saveTable($input),'tables:status'=>($this->service->setTableStatus((int)($input['id']??0),(string)($input['status']??''))??[]),
                'customers:list'=>$this->service->customers(),'customers:save'=>$this->service->saveCustomer($input),
                'reservations:list'=>$this->service->reservations(),'reservations:save'=>$this->service->saveReservation($input),'reservations:status'=>($this->service->reservationStatus((int)($input['id']??0),(string)($input['status']??''))??[]),
                'menu:list'=>$this->service->menu(),'menu:save'=>$this->service->saveMenu($input),'menu:modifier_save'=>$this->service->saveModifierGroup($input),'menu:recipe'=>$this->service->recipeDetail((int)($input['menu_id']??0)),'menu:recipe_save'=>$this->service->saveRecipe($input),
                'inventory:list'=>$this->service->inventory(),'inventory:save'=>$this->service->saveIngredient($input),'inventory:adjust'=>$this->service->adjustInventory($input),
                'purchasing:list'=>$this->service->purchasing(),'purchasing:supplier_save'=>$this->service->saveSupplier($input),'purchasing:save'=>$this->service->savePurchaseOrder($input),'purchasing:receive'=>$this->service->receivePurchaseOrder((int)($input['id']??0)),
                'orders:list'=>$this->service->orders((string)($input['scope']??'all')),'orders:create'=>$this->service->createOrder($input),'orders:detail'=>$this->service->orderDetail((int)($input['id']??0)),'orders:split'=>$this->service->splitOrder($input),
                'kitchen:list'=>$this->service->kitchen((string)($input['station']??'all')),'kitchen:status'=>($this->service->kitchenStatus((int)($input['id']??0),(string)($input['status']??''))??[]),'kitchen:item_status'=>($this->service->kitchenItemStatus((int)($input['id']??0),(string)($input['status']??''))??[]),
                'shifts:list'=>$this->service->shifts(),'shifts:open'=>$this->service->openShift($input),'shifts:movement'=>$this->service->cashMovement($input),'shifts:close'=>$this->service->closeShift($input),
                'payments:list'=>$this->service->payments(),'payments:create'=>$this->service->addPayment($input),
                'approvals:list'=>$this->service->approvals(),'approvals:request'=>$this->service->requestApproval($input),'approvals:decide'=>$this->service->decideApproval($input),
                'promotions:list'=>$this->service->promotions(),'promotions:save'=>$this->service->savePromotion($input),
                'reports:list'=>$this->service->reports((string)($input['from']??''),(string)($input['to']??'')),
                'audits:list'=>$this->service->auditLogs(),
                'settings:list'=>$this->service->settings(),'settings:save'=>$this->service->saveSettings($input),
                'users:list'=>$this->service->users(),'users:save'=>$this->service->saveUser($input),
                default=>throw new RuntimeException('Endpoint tidak ditemukan.')
            };
            Response::ok(is_array($result)?$result:[],'Berhasil.');
        }catch(RuntimeException $e){Response::error($e->getMessage(),422);}catch(\Throwable $e){error_log('[KedaiBara] '.$e->getMessage().' '.$e->getTraceAsString());Response::error('Terjadi kesalahan server. Silakan hubungi administrator.',500);}
    }
}
