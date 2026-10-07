<?php
/** Solaris Discord Link - WHMCS addon, PHP 8.1+. */
if (!defined('WHMCS')) { exit('Access Denied'); }
use WHMCS\Database\Capsule;

function discordlink_config() {
    return ['name'=>'Discord Account Link','description'=>'Secure Discord OAuth2 linking for WHMCS clients.',
        'version'=>'1.0.0','author'=>'Solaris','language'=>'english','fields'=>[
            'client_id'=>['FriendlyName'=>'Discord Application ID','Type'=>'text','Size'=>'50'],
            'client_secret'=>['FriendlyName'=>'Discord Client Secret','Type'=>'password','Size'=>'80']
        ]];
}
function discordlink_activate() {
    try {
        if (!Capsule::schema()->hasTable('mod_discordlink_accounts')) {
            Capsule::schema()->create('mod_discordlink_accounts',function($t) {
                $t->increments('id'); $t->unsignedInteger('client_id')->unique();
                $t->string('discord_id',32)->unique(); $t->string('username',255);
                $t->string('avatar',255)->nullable(); $t->timestamps();
            });
        }
        if (!Capsule::schema()->hasTable('mod_discordlink_states')) {
            Capsule::schema()->create('mod_discordlink_states',function($t) {
                $t->increments('id'); $t->unsignedInteger('client_id');
                $t->string('state_hash',64)->unique(); $t->timestamp('expires_at');
                $t->timestamps(); $t->index('client_id');
            });
        }
        return ['status'=>'success','description'=>'Discord Link tables created.'];
    } catch (\Throwable $e) { error_log('Discord Link activation: '.get_class($e)); return ['status'=>'error','description'=>'Could not initialize tables.']; }
}
function discordlink_deactivate() {
    return ['status'=>'success','description'=>'Account links retained.'];
}
function discordlink_output($vars) {
    echo '<h2>Discord account links</h2><p>Discord OAuth2 callback URL: <code>'.htmlspecialchars(discordlink_callback_url(),ENT_QUOTES,'UTF-8').'</code></p>';
    echo '<table class="table table-striped"><thead><tr><th>Client ID</th><th>Discord ID</th><th>Username</th><th>Linked</th></tr></thead><tbody>';
    foreach (Capsule::table('mod_discordlink_accounts')->orderBy('id','desc')->limit(100)->get() as $r) {
        echo '<tr><td>'.(int)$r->client_id.'</td><td>'.htmlspecialchars($r->discord_id,ENT_QUOTES,'UTF-8').'</td><td>'.htmlspecialchars($r->username,ENT_QUOTES,'UTF-8').'</td><td>'.htmlspecialchars((string)$r->created_at,ENT_QUOTES,'UTF-8').'</td></tr>';
    }
    echo '</tbody></table>';
}
function discordlink_clientarea($vars) {
    $id=(int)($_SESSION['uid']??0);
    if (!$id) { return ['pagetitle'=>'Discord Link','templatefile'=>'client','requirelogin'=>true,'vars'=>[]]; }
    $action=(string)($_GET['action']??'');
    if ($action==='connect'||$action==='unlink') {
        if (($_SERVER['REQUEST_METHOD']??'')!=='POST'||!hash_equals(discordlink_csrf_token(),(string)($_POST['discordlink_csrf']??''))) {
            http_response_code(403); exit('Invalid request');
        }
        if ($action==='unlink') {
            Capsule::table('mod_discordlink_accounts')->where('client_id',$id)->delete();
            discordlink_flash('Discord account unlinked.'); discordlink_redirect();
        }
        $app=discordlink_setting('client_id');
        if (!$app||!discordlink_setting('client_secret')) { discordlink_flash('Discord linking is not configured.'); discordlink_redirect(); }
        $state=bin2hex(random_bytes(32)); $now=date('Y-m-d H:i:s');
        Capsule::table('mod_discordlink_states')->where('client_id',$id)->delete();
        Capsule::table('mod_discordlink_states')->insert(['client_id'=>$id,'state_hash'=>hash('sha256',$state),'expires_at'=>date('Y-m-d H:i:s',time()+600),'created_at'=>$now,'updated_at'=>$now]);
        header('Location: https://discord.com/oauth2/authorize?'.http_build_query(['client_id'=>$app,'redirect_uri'=>discordlink_callback_url(),'response_type'=>'code','scope'=>'identify','state'=>$state]),true,303); exit;
    }
    if ($action==='callback') {
        $state=(string)($_GET['state']??''); $code=(string)($_GET['code']??'');
        if (!preg_match('/^[a-f0-9]{64}$/D',$state)||$code==='') { discordlink_flash('Authorization cancelled or invalid.'); discordlink_redirect(); }
        $pending=Capsule::table('mod_discordlink_states')->where('client_id',$id)->where('state_hash',hash('sha256',$state))->first();
        if (!$pending||strtotime($pending->expires_at)<time()) { discordlink_flash('Authorization expired. Try again.'); discordlink_redirect(); }
        Capsule::table('mod_discordlink_states')->where('id',$pending->id)->delete();
        try {
            $oauth=discordlink_http('https://discord.com/api/v10/oauth2/token',['client_id'=>discordlink_setting('client_id'),'client_secret'=>discordlink_setting('client_secret'),'grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>discordlink_callback_url()]);
            if (empty($oauth['access_token'])) { throw new \RuntimeException('Missing token'); }
            $user=discordlink_http('https://discord.com/api/v10/users/@me',null,$oauth['access_token']);
            $discordId=(string)($user['id']??'');
            if (!preg_match('/^[0-9]{15,22}$/D',$discordId)) { throw new \RuntimeException('Invalid Discord ID'); }
            $existing=Capsule::table('mod_discordlink_accounts')->where('discord_id',$discordId)->first();
            if ($existing&&(int)$existing->client_id!==$id) { discordlink_flash('Discord account already linked to another client.'); discordlink_redirect(); }
            Capsule::table('mod_discordlink_accounts')->updateOrInsert(['client_id'=>$id],[
                'discord_id'=>$discordId,'username'=>mb_substr((string)($user['username']??'Unknown'),0,255),
                'avatar'=>isset($user['avatar'])?mb_substr((string)$user['avatar'],0,255):null,
                'updated_at'=>date('Y-m-d H:i:s')]);
            discordlink_flash('Discord account connected.');
        } catch (\Throwable $e) { error_log('Discord Link OAuth: '.get_class($e)); discordlink_flash('Unable to connect Discord. Try again.'); }
        discordlink_redirect();
    }
    $link=Capsule::table('mod_discordlink_accounts')->where('client_id',$id)->first();
    return ['pagetitle'=>'Link Discord','templatefile'=>'client','requirelogin'=>true,'vars'=>[
        'discordlinkAccount'=>$link?['username'=>$link->username,'id'=>$link->discord_id]:null,
        'discordlinkCsrf'=>discordlink_csrf_token(),'discordlinkMessage'=>discordlink_consume_flash()
    ]];
}
function discordlink_callback_url() {
    return rtrim((string)\WHMCS\Config\Setting::getValue('SystemURL'),'/').'/index.php?m=discordlink&action=callback';
}
function discordlink_csrf_token() {
    if (empty($_SESSION['discordlink_csrf'])) { $_SESSION['discordlink_csrf']=bin2hex(random_bytes(32)); }
    return $_SESSION['discordlink_csrf'];
}
function discordlink_flash($message) { $_SESSION['discordlink_flash']=$message; }
function discordlink_consume_flash() {
    $message=(string)($_SESSION['discordlink_flash']??''); unset($_SESSION['discordlink_flash']); return $message;
}
function discordlink_redirect() { header('Location: index.php?m=discordlink',true,303); exit; }
function discordlink_setting($key) {
    return (string)Capsule::table('tbladdonmodules')->where('module','discordlink')->where('setting',$key)->value('value');
}
function discordlink_http($url,$post=null,$token=null) {
    $ch=curl_init($url); $headers=['Accept: application/json'];
    if ($post!==null) {
        $headers[]='Content-Type: application/x-www-form-urlencoded';
        curl_setopt($ch,CURLOPT_POST,true); curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($post));
    }
    if ($token!==null) { $headers[]='Authorization: Bearer '.$token; }
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>12,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_HTTPHEADER=>$headers,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_FOLLOWLOCATION=>false]);
    $body=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if ($body===false||$status<200||$status>=300) { throw new \RuntimeException('Discord API request failed'); }
    $data=json_decode($body,true);
    if (!is_array($data)) { throw new \RuntimeException('Invalid Discord API response'); }
    return $data;
}
