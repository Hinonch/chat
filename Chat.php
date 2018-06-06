<?php
/**
 * Created by Zhou.
 * User: Zhou
 * Date: 2017/11/1/001
 * Time: 14:35
 * Desc:
 */

namespace app;
include './JPush.php';
include './Aliyun/DySms.php';
include './Aliyun/AliPush.php';

class Chat
{
    private $server;//socket服务器
    private static $db;//mysql连接
    private static $redis;//redis连接
    private static $jPush;//极光连接
    private static $sms;//短信连接
    private static $push;//阿里连接
//    private $redis;
    public function __construct()
    {
        $this->server = new \swoole_websocket_server("0.0.0.0", port);
        $this->server->set(['daemonize '=>1]);
        $this->server->on('open', [$this, 'open']);//开启连接
        $this->server->on('message', [$this, 'message']);//接受到消息时
        $this->server->on('close', [$this, 'close']);//断开连接时
//        $this->server->on('request', [$this, 'request']);
        cli_set_process_title("php Chat.php: master");
        $this->server->start();
    }

    /**
     * 连接redis
     */
    private function redis()
    {
        if (!self::$redis) {
            self::$redis = new \Redis();
            self::$redis->connect('0.0.0.0', 'port');
            self::$redis->auth('pwd');
            self::$redis->select('db');
        }
        return self::$redis;
    }

    /**
     * @param $registrationId
     * @param $platform
     * @param $content
     * @param array $list
     * @return bool|mixed
     * 给不在线用户发送推送信息
     */
    private function sendPush($registrationId, $platform, $content, $list = array())
    {
        $param['registrationId'] = $registrationId;
        $param['platform'] = $platform;
        $param['content'] = $content;
        $param['list'] = $list;
        if (!self::$jPush) {
            self::$jPush = new JPush();
        }

        return self::$jPush->push($param);
    }
    /**
     * @param $registrationId
     * @param $platform
     * @param $content
     * @return mixed|\SimpleXMLElement
     * 阿里推送
     */
    private function aliPush($registrationId, $platform, $content)
    {
        if (!self::$push) {
            self::$push = new \AliPush();
        }
        return self::$push->pushNotice($registrationId, $platform, $content, ['type'=>'msg'], '消息');
    }
    /**
     * @param $sql
     * @return mixed
     * 同步连接mysql查询
     */
    private function fetch($sql)
    {
        if (!self::$db) {
            self::$db = new \PDO('mysql:host=0.0.0.0;dbname=db', 'name', 'pwd');
        }
        $r = self::$db->query($sql, \PDO::FETCH_ASSOC);
        return $r;
    }

    /**
     * @param $sql
     * @return int
     * 同步连接mysql写入
     */
    private function save($sql){
        if (!self::$db) {
            self::$db = new \PDO('mysql:host=0.0.0.0;dbname=database', 'username', 'password');
        }
        return self::$db->exec($sql);
    }

    /**
     * @param $tmp
     * @param $phone
     * @return \stdClass
     * 发送短信
     */
    private function sms($tmp, $phone, $param = null)
    {
        if (!self::$sms) {
            self::$sms = new \Dysms();
        }
        return self::$sms->sendSms('tag', $tmp, $phone, $param);
    }

    /**
     * @param $toUser
     * @return mixed
     * 根据用户i的获取用户信息
     */
    private function userInfo($toUser) {
        if ($pushInfo = $this->redis()->get('user_'.$toUser)) {
            $toUserInfo = unserialize($pushInfo);
        } else {
            $toUserSql = 'select  from user_info where id='.$toUser;
            $toUserInfo = $this->fetch($toUserSql)->fetch();
        }
        return $toUserInfo;
    }

    /**
     * @param \swoole_websocket_server $server
     * @param $request
     * 建立连接时redis中写入 user_id 和 fd 映射，判断用户是否有未读消息
     * 传入参数 token 用户登录token
     */
    public function open (\swoole_websocket_server $server, $request)
    {

        try {
            //同步验证用户信息查询用户信息
            if (!$request->get['token']) {
                $server->push($request->fd, '二货，你忘带东西了！');
		$server->close($request->fd);
		return;
            }
            $r = false;
            //判断是否新版登录
            if (isset($request->get['userId'])) {

                if ($this->redis()->get('user_'.$request->get['userId'])) {
                    $user = unserialize($this->redis()->get('user_'.$request->get['userId']));
                    if ($user['id'] == $request->get['userId'] && $user['token'] == $request['token']) {
                        $r['userId'] = $user['id'];
                    }
                }
            } else {
                $sql = 'select id as userId from user_info  where `token`="'.$request->get['token'].'"';
                $r = $this->fetch($sql)->fetch();
            }
            if ($r === false) {
		$server->push($request->fd, json_encode(['code'=>'250']));
		$server->close($request->fd);
            } else {

                $redis = $this->redis();
                //存储用户信息表，token 与 userId 映射

                $redis->hSet('all_user', $request->fd, $r['userId']);//以hash表存储用户id 与 fd 映射关系

                //查询未读消息记录
                $sqlCount = 'select count(*) as num from chat_info where status=0 and to_user='.$r['userId'];
                $rCount = $this->fetch($sqlCount)->fetch();
                $rCount = $rCount?$rCount:['num'=>0];
                $server->push($request->fd, json_encode(['code'=>'200', 'num'=>$rCount['num']]));
            }
        } catch (\ErrorException $e) {
            echo $e->getMessage();
        }
    }

    /**
     * @param \swoole_websocket_server $server
     * @param $frame
     * 消息处理
     * 数据格式：
     * 返回数据格式：
     */
    public function message (\swoole_websocket_server $server, $frame)
    {
        try {
            $redis = $this->redis();
            //处理接收数据
            if ($frame->opcode == 1) {//文本数据
                if ($frame->data == '1') {
                    $server->push($frame->fd, '1');
                    echo 1;
                } else {
                    $info = json_decode($frame->data, true);

                    if ($info && trim($info['msg']) != '') {
                        //获取发送人信息
                        $msg = base64_encode($info['msg']);
                        if (mb_strlen($msg) >= 2048) {
                            $msg = base64_encode('消息内容已超过200字长度限制，接收失败！');
                        }
                        $status = 0;
                        //多用户登录模式
                        foreach ($server->connections as $fd) {
                            if ($redis->hGet('all_user', $fd) == $toUser) {
                                $info['ctime'] = time();
                                $server->push($fd, json_encode($info, JSON_UNESCAPED_UNICODE));
                                $status = 1;//用户在线
                            }
                        }
                        $sql = 'insert into chat_info () values ()';
                        $r = $this->save($sql);
                        if ($r) {
                            $server->push($frame->fd, '200');

                            if (!$status) {
                                //极光推送
                                if (!isset($toUserInfo)) {
                                    $toUserInfo = $this->userInfo($toUser);
                                }
                                if (!empty($toUserInfo['registrationId']) && !empty($toUserInfo['source'])) {
                                    $this->aliPush($toUserInfo['registrationId'], $toUserInfo['source'], '内容');
                                }
                                //判断是否有该用户的定时任务，如果没有则开始定时任务，该用户上线swoole_timer_clear掉定时任务
//                            if () {
//                                //定时任务
//                                $timer = swoole_timer_after(300000, function () use ($toUserInfo) {
//                                    
//                                    $time = time()-299;
//                                    $sql = 'select id from chat_info where status=0 and task_status=0 and to_user='.$toUserInfo['id'].' and ctime <='.$time;
//                                    $r = $this->fetch($sql)->fetch();
//                                    if ($r) {
//                                        $result =$this->sms('SMS_109490275', $toUserInfo['phone'], ['time'=>'5分钟']);
//                                        var_dump($result);
//                                        $sqlSave = 'update chat_info_user set task_status=1 where to_user='.$toUserInfo['id'];
//                                        $this->save($sqlSave);
//                                    }
//                                });
//                                $this->redis()->set('timer_'.$toUser, $timer, 300);
//                            }
                            }
                        }
                    }
                }

            } else if ($frame->opcode == 8) {//断开连接
                $this->close($server, $frame->fd);
            } else {
                //暂不支持其他方式发送
                $server->push($frame->fd, json_encode(['code'=>'400'], JSON_UNESCAPED_UNICODE));
            }
        } catch (\Exception $e) {
            echo $e->getMessage();
        }

    }

    /**
     * @param $server
     * @param $fd
     * 用户断开连接时， 删除映射关系
     */
    public function close ($server, $fd)
    {
        try {
            $redis = $this->redis();
            //删除两张关系表中对应映射数据
	    $redis->hDel('all_user', $fd);
	    $server->close($fd);
        } catch (\Exception $e) {
            echo $e->getMessage();
        }
    }
}
new Chat();
