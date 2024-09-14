<?php


namespace thans\jwt\middleware;

use think\facade\Db;

class JWTAuth extends BaseMiddleware
{
    public function handle($request, \Closure $next)
    {
        $current_key = Db::name('config')->where('key', 'current_token')->value('value');
        $access_payload = $this->auth->accessauth();
        $access_token_key = $access_payload['key'];
        if($current_key != $access_token_key){                
            return json(['code' => 0, 'message' => "Server error."]);
        }
        return $next($request);
    }
}
