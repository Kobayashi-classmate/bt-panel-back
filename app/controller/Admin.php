<?php

namespace app\controller;

use app\BaseController;
use think\facade\Db;
use think\facade\View;
use think\facade\Request;
use think\facade\Cache;
use app\lib\Btapi;
use app\lib\Plugins;
use thans\jwt\facade\JWTAuth;


use edward\captcha\facade\CaptchaApi;
class Admin extends BaseController
{
    public function verifycode()
    {
        $data = CaptchaApi::create();
        unset($data['code']);
        return json(['code' => 200, 'data' => $data]);
    }

    public function login()
    {
        if (request()->islogin) {
            return redirect('/admin');
        }
        if (request()->isAjax()) {
            $username = input('post.username', null, 'trim');
            $password = input('post.password', null, 'trim');
            $code = input('post.code', null, 'trim');
            $key = input('post.key', null, 'trim');

            if (empty($username) || empty($password)) {
                return json(['code' => -1, 'codeimg' => true, 'success' => false]);
            }
            if (!CaptchaApi::check($code, $key)) {
                return json(['code' => -1, 'codeimg' => false, 'success' => false]);
            }
            if ($username == config_get('admin_username') && $password == config_get('admin_password')) {
                Db::name('log')->insert(['uid' => 0, 'action' => '登录后台', 'data' => 'IP:' . $this->clientip, 'addtime' => date("Y-m-d H:i:s")]);
                // $expiretime = time() + 2562000;
                $current_key = Db::name('config')->where('key', 'current_token')->value('value');
                $refresh_key = Db::name('config')->where('key', 'refresh_token')->value('value');
                $accesstoken = JWTAuth::builder(['key' => $current_key]);
                $refreshtoken = JWTAuth::builder(['key' => $refresh_key]);
                config_set('admin_lastlogin', date('Y-m-d H:i:s'));
                // Current request time
                $current_time = date('Y-m-d H:i:s');
                // token validity period
                $addtime = strtotime("+40 seconds", strtotime($current_time));
                // token expiration time
                $new_time = date('Y-m-d H:i:s', $addtime);
                return json(['code' => 200, 'data' => ['accessToken' => $accesstoken, 'refreshToken' => $refreshtoken, 'expires' => $new_time], 'success' => true]);
            } else {
                return json(['code' => -1, 'codeimg' => true, 'success' => false]);
            }
        }
    }

    public function refretoken()
    {
        if (request()->isPost()) {
            $access_token = input('post.accessToken', null, 'trim');
            $refresh_token = input('post.token', null, 'trim');
            if (empty($access_token) || empty($refresh_token)) {
                return json(['code' => -1, 'success' => false]);
            }
            $current_key = Db::name('config')->where('key', 'current_token')->value('value');
            $refresh_key = Db::name('config')->where('key', 'refresh_token')->value('value');
            $refresh_payload = JWTAuth::refreshauth();
            $refresh_token_key = $refresh_payload['key'];
            if($refresh_key != $refresh_token_key){                
                return json(['code' => -1, 'success' => "false"]);
            }
            JWTAuth::refresh();
            JWTAuth::invalidate($access_token);
            $accesstoken = JWTAuth::builder(['key' => $current_key]);
            // Current request time
            $current_time = date('Y-m-d H:i:s');
            // token validity period
            $addtime = strtotime("+40 seconds", strtotime($current_time));
            // token expiration time
            $new_time = date('Y-m-d H:i:s', $addtime);
            return json(['code' => 200, 'data' => ['accessToken' => $accesstoken, 'refreshToken' => $refresh_token, 'expires' => $new_time], 'success' => "true"]);
        }
    }

    public function logout()
    {
        cookie('accessToken', null);
        return redirect('/admin/login');
        
    }

    public function statistics()
    {
        $stat = ['total' => 0, 'free' => 0, 'pro' => 0, 'ltd' => 0, 'third' => 0];
        $json_arr = Plugins::get_plugin_list();
        if ($json_arr) {
            foreach ($json_arr['list'] as $plugin) {
                $stat['total']++;
                if ($plugin['type'] == 10)
                    $stat['third']++;
                elseif ($plugin['type'] == 12)
                    $stat['ltd']++;
                elseif ($plugin['type'] == 8)
                    $stat['pro']++;
                elseif ($plugin['type'] == 5 || $plugin['type'] == 6 || $plugin['type'] == 7)
                    $stat['free']++;
            }
        }
        if (!Db::name('config')->where('key', 'runtime')->value('value')) {
            $stat['runyn'] = false;
        }else {
            $stat['runyn'] = true;
            $stat['runtime'] = Db::name('config')->where('key', 'runtime')->value('value');
        }
        $stat['record_total'] = Db::name('record')->count();
        $stat['record_isuse'] = Db::name('record')->whereTime('usetime', '>=', strtotime('-7 days'))->count();
        // View::assign('stat', $stat);

        $tmp = 'version()';
        $mysqlVersion = Db::query("select version()")[0][$tmp];
        $info = [
            'framework_version' => app()::VERSION,
            'php_version' => PHP_VERSION,
            'mysql_version' => $mysqlVersion,
            'software' => $_SERVER['SERVER_SOFTWARE'],
            'os' => php_uname(),
            'date' => date("Y-m-d H:i:s"),
        ];
        return json(['stat' => $stat, 'info' => $info]);
    }

    public function set()
    {
        if (request()->isAjax()) {
            $params = Request::param();

            foreach ($params as $key => $value) {
                config_set($key, $value);
            }
            cache('configs', NULL);
            return json(['code' => 200]);
        }
        $mod = input('param.mod', 'sys');
        View::assign('mod', $mod);
        View::assign('conf', config('sys'));
        $runtime = Db::name('config')->where('key', 'runtime')->value('value') ?? '<font color="red">未运行</font>';
        View::assign('runtime', $runtime);
        return view();
    }

    public function setaccount()
    {
        $params = Request::param();
        if (isset($params['username']))
            $params['username'] = trim($params['username']);
        if (isset($params['oldpwd']))
            $params['oldpwd'] = trim($params['oldpwd']);
        if (isset($params['newpwd']))
            $params['newpwd'] = trim($params['newpwd']);
        if (isset($params['newpwd2']))
            $params['newpwd2'] = trim($params['newpwd2']);

        if (empty($params['username']))
            return json(['code' => -1, 'message' => '用户名不能为空']);

        config_set('admin_username', $params['username']);

        if (!empty($params['oldpwd']) && !empty($params['newpwd']) && !empty($params['newpwd2'])) {
            if (config_get('admin_password') != $params['oldpwd']) {
                return json(['code' => -1, 'message' => '旧密码不正确']);
            }
            if ($params['newpwd'] != $params['newpwd2']) {
                return json(['code' => -1, 'message' => '两次新密码输入不一致']);
            }
            config_set('admin_password', $params['newpwd']);
        }
        cache('configs', NULL);
        cookie('accessToken', null);
        return json(['code' => 200]);
    }

    public function testbturl()
    {
        $bt_type = input('post.bt_type/d');

        if ($bt_type == 1) {
            $bt_surl = input('post.bt_surl');
            if (!$bt_surl)
                return json(['code' => -1, 'message' => '参数不能为空']);
            $res = get_curl($bt_surl . 'api/SetupCount');
            if (strpos($res, 'ok') !== false) {
                return json(['code' => 200, 'message' => '第三方云端连接测试成功！']);
            } else {
                return json(['code' => -1, 'message' => '第三方云端连接测试失败']);
            }
        } else {
            $bt_url = input('post.bt_url');
            $bt_key = input('post.bt_key');
            if (!$bt_url || !$bt_key)
                return json(['code' => -1, 'message' => '参数不能为空']);
            $btapi = new Btapi($bt_url, $bt_key);
            $result = $btapi->get_config();
            if ($result && isset($result['status']) && ($result['status'] == 1 || isset($result['sites_path']))) {
                $result = $btapi->get_user_info();
                if ($result && isset($result['username'])) {
                    return json(['code' => 200, 'message' => '面板连接测试成功！']);
                } else {
                    return json(['code' => -1, 'message' => '面板连接测试成功，但未安装专用插件']);
                }
            } else {
                return json(['code' => -1, 'message' => isset($result['message']) ? $result['message'] : '面板地址无法连接']);
            }
        }
    }

    public function plugins()
    {
        $typelist = [];
        $json_arr = Plugins::get_plugin_list();
        if ($json_arr) {
            foreach ($json_arr['type'] as $type) {
                if ($type['title'] == '一键部署')
                    continue;
                $typelist[$type['id']] = $type['title'];
            }
        }
        View::assign('typelist', $typelist);
        return view();
    }

    public function pluginswin()
    {
        $typelist = [];
        $json_arr = Plugins::get_plugin_list('Windows');
        if ($json_arr) {
            foreach ($json_arr['type'] as $type) {
                if ($type['title'] == '一键部署')
                    continue;
                $typelist[$type['id']] = $type['title'];
            }
        }
        View::assign('typelist', $typelist);
        return view();
    }

    public function plugins_data()
    {
        $type = input('post.type/d');
        $keyword = input('post.keyword', null, 'trim');
        $os = input('get.os');
        if (!$os)
            $os = 'Linux';

        $json_arr = Plugins::get_plugin_list($os);
        if (!$json_arr)
            return json([]);

        $typelist = [];
        foreach ($json_arr['type'] as $row) {
            $typelist[$row['id']] = $row['title'];
        }

        $list = [];
        foreach ($json_arr['list'] as $plugin) {
            if ($type > 0 && $plugin['type'] != $type)
                continue;
            if (!empty($keyword) && $keyword != $plugin['name'] && stripos($plugin['title'], $keyword) === false)
                continue;
            $versions = [];
            foreach ($plugin['versions'] as $version) {
                $ver = $version['m_version'] . '.' . $version['version'];
                if (isset($version['download'])) {
                    $status = false;
                    if (file_exists(get_data_dir() . 'plugins/other/' . $version['download'])) {
                        $status = true;
                    }
                    $versions[] = ['status' => $status, 'type' => 1, 'version' => $ver, 'download' => $version['download'], 'md5' => $version['md5']];
                } else {
                    $status = false;
                    if (file_exists(get_data_dir($os) . 'plugins/package/' . $plugin['name'] . '-' . $ver . '.zip')) {
                        $status = true;
                    }
                    $versions[] = ['status' => $status, 'type' => 0, 'version' => $ver];
                }
            }
            if ($plugin['name'] == 'obs')
                $plugin['ps'] = substr($plugin['ps'], 0, strpos($plugin['ps'], '<a '));
            $list[] = [
                'id' => $plugin['id'],
                'name' => $plugin['name'],
                'title' => $plugin['title'],
                'type' => $plugin['type'],
                'typename' => $typelist[$plugin['type']],
                'desc' => str_replace('target="_blank"', 'target="_blank" rel="noopener noreferrer"', $plugin['ps']),
                'price' => $plugin['price'],
                'author' => isset($plugin['author']) ? $plugin['author'] : '官方',
                'versions' => $versions
            ];
        }
        return json($list);
    }

    public function download_plugin()
    {
        $name = input('post.name', null, 'trim');
        $version = input('post.version', null, 'trim');
        $os = input('post.os');
        if (!$os)
            $os = 'Linux';
        if (!$name || !$version)
            return json(['code' => -1, 'message' => '参数不能为空']);
        try {
            Plugins::download_plugin($name, $version, $os);
            Db::name('log')->insert(['uid' => 0, 'action' => '下载插件', 'data' => $name . '-' . $version . ' os:' . $os, 'addtime' => date("Y-m-d H:i:s")]);
            return json(['code' => 200, 'message' => '下载成功']);
        } catch (\Exception $e) {
            return json(['code' => -1, 'message' => $e->getMessage()]);
        }
    }

    public function refresh_plugins()
    {
        $os = input('get.os');
        if (!$os)
            $os = 'Linux';
        try {
            Plugins::refresh_plugin_list($os);
            Db::name('log')->insert(['uid' => 0, 'action' => '刷新插件列表', 'data' => '刷新' . $os . '插件列表成功', 'addtime' => date("Y-m-d H:i:s")]);
            return json(['code' => 200, 'message' => '获取最新插件列表成功！']);
        } catch (\Exception $e) {
            return json(['code' => -1, 'message' => $e->getMessage()]);
        }
    }

    public function record()
    {
        return view();
    }

    public function record_data()
    {
        $ip = input('post.ip', null, 'trim');
        $offset = input('post.offset/d');
        $limit = input('post.limit/d');

        $select = Db::name('record');
        if (!empty($ip)) {
            $select->where('ip', $ip);
        }
        $total = $select->count();
        $rows = $select->order('id', 'desc')->limit($offset, $limit)->select();

        return json(['total' => $total, 'rows' => $rows]);
    }

    public function log()
    {
        return view();
    }

    public function log_data()
    {
        $action = input('post.action', null, 'trim');
        $offset = input('post.offset/d');
        $limit = input('post.limit/d');

        $select = Db::name('log');
        if (!empty($action)) {
            $select->where('action', $action);
        }
        $total = $select->count();
        $rows = $select->order('id', 'desc')->limit($offset, $limit)->select();

        return json(['total' => $total, 'rows' => $rows]);
    }

    public function list()
    {
        $type = input('param.type', 'black');
        View::assign('type', $type);
        View::assign('typename', $type == 'white' ? '白名单' : '黑名单');
        return view();
    }

    public function list_data()
    {
        $type = input('param.type', 'black');
        $ip = input('post.ip', null, 'trim');
        $offset = input('post.offset/d');
        $limit = input('post.limit/d');

        $tablename = $type == 'black' ? 'black' : 'white';
        $select = Db::name($tablename);
        if (!empty($ip)) {
            $select->where('ip', $ip);
        }
        $total = $select->count();
        $rows = $select->order('id', 'desc')->limit($offset, $limit)->select();

        return json(['total' => $total, 'rows' => $rows]);
    }

    public function list_op()
    {
        $type = input('param.type', 'black');
        $tablename = $type == 'black' ? 'black' : 'white';
        $act = input('post.act', null);
        if ($act == 'get') {
            $id = input('post.id/d');
            if (!$id)
                return json(['code' => -1, 'message' => 'no id']);
            $data = Db::name($tablename)->where('id', $id)->find();
            return json(['code' => 200, 'data' => $data]);
        } elseif ($act == 'add') {
            $ip = input('post.ip', null, 'trim');
            if (!$ip)
                return json(['code' => -1, 'message' => 'IP不能为空']);
            if (Db::name($tablename)->where('ip', $ip)->find()) {
                return json(['code' => -1, 'message' => '该IP已存在']);
            }
            Db::name($tablename)->insert([
                'ip' => $ip,
                'enable' => 1,
                'addtime' => date("Y-m-d H:i:s")
            ]);
            return json(['code' => 200, 'message' => 'succ']);
        } elseif ($act == 'edit') {
            $id = input('post.id/d');
            $ip = input('post.ip', null, 'trim');
            if (!$id || !$ip)
                return json(['code' => -1, 'message' => 'IP不能为空']);
            if (Db::name($tablename)->where('ip', $ip)->where('id', '<>', $id)->find()) {
                return json(['code' => -1, 'message' => '该IP已存在']);
            }
            Db::name($tablename)->where('id', $id)->update([
                'ip' => $ip
            ]);
            return json(['code' => 200, 'message' => 'succ']);
        } elseif ($act == 'enable') {
            $id = input('post.id/d');
            $enable = input('post.enable/d');
            if (!$id)
                return json(['code' => -1, 'message' => 'no id']);
            Db::name($tablename)->where('id', $id)->update([
                'enable' => $enable
            ]);
            return json(['code' => 200, 'message' => 'succ']);
        } elseif ($act == 'del') {
            $id = input('post.id/d');
            if (!$id)
                return json(['code' => -1, 'message' => 'no id']);
            Db::name($tablename)->where('id', $id)->delete();
            return json(['code' => 200, 'message' => 'succ']);
        }
        return json(['code' => -1, 'message' => 'no act']);
    }

    public function deplist()
    {
        $deplist_linux = get_data_dir() . 'config/deployment_list.json';
        $deplist_win = get_data_dir('Windows') . 'config/deployment_list.json';
        $deplist_linux_time = file_exists($deplist_linux) ? date("Y-m-d H:i:s", filemtime($deplist_linux)) : '不存在';
        $deplist_win_time = file_exists($deplist_win) ? date("Y-m-d H:i:s", filemtime($deplist_win)) : '不存在';
        View::assign('deplist_linux_time', $deplist_linux_time);
        View::assign('deplist_win_time', $deplist_win_time);
        return view();
    }

    public function refresh_deplist()
    {
        $os = input('get.os');
        if (!$os)
            $os = 'Linux';
        try {
            Plugins::refresh_deplist($os);
            Db::name('log')->insert(['uid' => 0, 'action' => '刷新一键部署列表', 'data' => '刷新' . $os . '一键部署列表成功', 'addtime' => date("Y-m-d H:i:s")]);
            return json(['code' => 200, 'message' => '获取最新一键部署列表成功！']);
        } catch (\Exception $e) {
            return json(['code' => -1, 'message' => $e->getMessage()]);
        }
    }

    public function cleancache()
    {
        Cache::clear();
        return json(['code' => 200, 'message' => 'succ']);
    }

    public function ssl()
    {
        if (request()->isAjax()) {
            $domain_list = input('post.domain_list', null, 'trim');
            $common_name = input('post.common_name', null, 'trim');
            $validity = input('post.validity/d');
            if (empty($domain_list) || empty($validity)) {
                return json(['code' => -1, 'message' => '参数不能为空']);
            }
            $array = explode("\n", $domain_list);
            $domain_list = [];
            foreach ($array as $domain) {
                $domain = trim($domain);
                if (empty($domain))
                    continue;
                if (!checkDomain($domain))
                    return json(['code' => -1, 'message' => '域名或IP格式不正确:' . $domain]);
                $domain_list[] = $domain;
            }
            if (empty($domain_list))
                return json(['code' => -1, 'message' => '域名列表不能为空']);
            if (empty($common_name))
                $common_name = $domain_list[0];
            $result = makeSelfSignSSL($common_name, $domain_list, $validity);
            if (!$result) {
                return json(['code' => -1, 'message' => '生成证书失败']);
            }
            return json(['code' => 200, 'message' => '生成证书成功', 'cert' => $result['cert'], 'key' => $result['key']]);
        }

        $dir = app()->getBasePath() . 'script/';
        $ssl_path = app()->getRootPath() . 'public/ssl/baota_root.pfx';
        $ssl_path_mac = app()->getRootPath() . 'public/ssl/baota_root.crt';
        $isca = file_exists($dir . 'ca.crt') && file_exists($dir . 'ca.key') && file_exists($ssl_path) && file_exists($ssl_path_mac);
        View::assign('isca', $isca);
        return view();
    }
}