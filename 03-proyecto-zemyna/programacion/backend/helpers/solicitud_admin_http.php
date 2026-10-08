<?php
/** CSRF separado de los contratos públicos. El token permite clientes sin Origin. */
function solicitudAdminCsrf(array $server, array $session): bool {
    $token=$server['HTTP_X_CSRF_TOKEN']??null;
    if(!is_string($token) || !isset($session['solicitud_admin_csrf']) || !hash_equals($session['solicitud_admin_csrf'],$token))return false;
    if(isset($server['HTTP_SEC_FETCH_SITE']) && $server['HTTP_SEC_FETCH_SITE']==='cross-site')return false;
    if(!isset($server['HTTP_ORIGIN']))return true;
    $origin=parse_url($server['HTTP_ORIGIN']);$host=parse_url('http://'.($server['HTTP_HOST']??''));
    $scheme=!empty($server['HTTPS']) && $server['HTTPS']!=='off'?'https':'http';
    return is_array($origin) && isset($origin['scheme'],$origin['host'],$host['host']) && !isset($origin['user']) && !isset($origin['pass'])
        && !isset($origin['path']) && !isset($origin['query']) && !isset($origin['fragment']) && $origin['scheme']===$scheme
        && strtolower($origin['host'])===strtolower($host['host'])
        && ($origin['port']??($scheme==='https'?443:80))===($host['port']??($scheme==='https'?443:80));
}
