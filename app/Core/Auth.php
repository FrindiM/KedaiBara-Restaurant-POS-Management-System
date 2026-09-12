<?php
namespace App\Core;

use PDO;

final class Auth
{
    public static function user(): ?array{return $_SESSION['user']??null;}
    public static function id(): ?int{return isset($_SESSION['user']['id'])?(int)$_SESSION['user']['id']:null;}
    public static function role(): ?string{return $_SESSION['user']['role_slug']??null;}
    public static function check(): bool{return !empty($_SESSION['user']);}
    public static function attempt(string $email,string $password): bool
    {
        $pdo=Database::connection();$s=$pdo->prepare('SELECT u.id,u.name,u.email,u.password,u.status,r.slug role_slug,r.name role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.email=:email LIMIT 1');$s->execute(['email'=>strtolower(trim($email))]);$u=$s->fetch(PDO::FETCH_ASSOC);if(!$u||$u['status']!=='active'||!password_verify($password,$u['password']))return false;unset($u['password']);session_regenerate_id(true);$_SESSION['user']=$u;$pdo->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$u['id']]);return true;
    }
    public static function logout(): void{$_SESSION=[];if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']);}session_destroy();}
    public static function requireLogin(): void{if(!self::check()){header('Location: index.php?page=login');exit;}}
    public static function canAccess(string $page): bool
    {
        $role=self::role();if(!$role)return false;$all=['dashboard','tables','reservations','customers','pos','kitchen','menu','inventory','purchasing','shifts','payments','approvals','promotions','reports','audits','users','settings'];
        $map=[
            'superadmin'=>$all,
            'owner'=>['dashboard','reports','customers','payments','settings'],
            'manager'=>$all,
            'cashier'=>['dashboard','tables','reservations','customers','pos','shifts','payments'],
            'waiter'=>['dashboard','tables','reservations','customers','pos'],
            'kitchen'=>['dashboard','kitchen'],
            'inventory'=>['dashboard','menu','inventory','purchasing','reports'],
            'purchasing'=>['dashboard','inventory','purchasing'],
        ];return in_array($page,$map[$role]??[],true);
    }
}
