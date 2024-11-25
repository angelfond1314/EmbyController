<?php

namespace app\media\controller;

use app\BaseController;
use app\media\model\FinanceRecordModel;
use app\media\model\PayRecordModel;
use think\facade\Request;
use think\facade\Session;
use app\media\model\UserModel as UserModel;
use app\media\validate\Login as LoginValidate;
use app\media\validate\Register as RegisterValidate;
use think\facade\View;
use think\facade\Config;
use think\facade\Cache;


class Finance extends BaseController
{
    public function index()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        return view();
    }

    public function user()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        $userModel = new UserModel();
        $userFromDatabase = $userModel->where('id', Session::get('r_user')->id)->find();
        $userFromDatabase['password'] = null;
        View::assign('userFromDatabase', $userFromDatabase);
        return view();
    }

    public function record()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        // 从请求参数中获取当前page，pagesize，search等信息
        $page = input('page', 1, 'intval');
        $pagesize = input('pagesize', 10, 'intval');

        $financeRecordModel = new FinanceRecordModel();
        $financeRecordModel= $financeRecordModel
            ->where('userId', Session::get('r_user')->id)
            ->order('createdAt', 'desc');
        $pageCount = ceil($financeRecordModel->count() / $pagesize);
        $recordList = $financeRecordModel
            ->page($page, $pagesize)
            ->select();
        View::assign('page', $page);
        View::assign('pageCount', $pageCount);
        View::assign('recordList', $recordList);
        return view();
    }

    public function payRecord()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        $page = input('page', 1, 'intval');
        $pagesize = input('pagesize', 10, 'intval');

        $payRecordModel = new PayRecordModel();
        $payRecordModel = $payRecordModel
            ->where('userId', Session::get('r_user')->id)
            ->order('createdAt', 'desc');
        $pageCount = ceil($payRecordModel->count() / $pagesize);
        $recordList = $payRecordModel
            ->page($page, $pagesize)
            ->select();
        View::assign('page', $page);
        View::assign('pageCount', $pageCount);
        View::assign('recordList', $recordList);
        return view();
    }

    // 手动检查某订单是否付款完成
    public function checkPay()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        if (Request::isPost()) {
            $payRecordModel = new PayRecordModel();
            $data = Request::param();
            $payRecord = $payRecordModel->where('id', $data['id'])->find();

            if ($payRecord == null) {
                return json(['code' => 400, 'msg' => '订单不存在']);
            } else {
                if ($payRecord['type'] == 2) {
                    return json(['code' => 200, 'msg' => '订单已为支付状态，请刷新页面', 'action' => 'refresh']);
                }
                $url = Config::get('payment.urlBase') . 'api.php?act=order&pid=' . Config::get('payment.id') . '&key=' . Config::get('payment.key') . '&out_trade_no=' . $payRecord['tradeNo'];
                $respond = getHttpResponse($url);
                $respond = json_decode($respond, true);
                if ($respond['code'] == 1 && $respond['status'] == 1) {
                    $payRecord->type = 2;
                    $payRecord->save();

                    $userModel = new UserModel();
                    $user = $userModel->where('id', $payRecord['userId'])->find();
                    $user->rCount = $user->rCount + $payRecord['money'];
                    $user->save();

                    $financeRecordModel = new FinanceRecordModel();
                    $financeRecordModel->save([
                        'userId' => $payRecord['userId'],
                        'action' => 1,
                        'count' => $payRecord['money'],
                        'recordInfo' => [
                            'message' => '订单(#' . $payRecord['tradeNo'] . ')用户手动补单支付成功，兑换成' . $payRecord['money'] . 'R币 + ' . $payRecord['give'] . '赠送R币',
                        ]
                    ]);

                    return json(['code' => 200, 'message' => '系统核实已经支付但未到账，现已补单，奖励已发放', 'action' => 'refresh']);
                } else {
                    return json(['code' => 400, 'message' => '订单未支付']);
                }
            }
        }
    }

    public function payRecordDetail()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        if (Request::isGet()) {
            $payRecordModel = new PayRecordModel();
            $data = Request::param();
            $payRecord = $payRecordModel->where('id', $data['id'])->find();
            if ($payRecord == null) {
                return redirect('/media/finance/payRecord');
            }
            if ($payRecord['userId'] != Session::get('r_user')->id) {
                return redirect('/media/finance/payRecord');
            }
            View::assign('payRecord', $payRecord);
            return view();
        }
    }

    public function rePay()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        if (Request::isPost()) {
            $payRecordModel = new PayRecordModel();
            $data = Request::param();
            $payRecord = $payRecordModel->where('id', $data['id'])->find();
            if ($payRecord == null) {
                return json(['code' => 400, 'msg' => '订单不存在']);
            }
            if ($payRecord['userId'] != Session::get('r_user')->id) {
                return json(['code' => 400, 'msg' => '订单不存在']);
            }
            if ($payRecord['type'] == 2) {
                return json(['code' => 400, 'msg' => '订单已支付']);
            }
            $url = Config::get('payment.urlBase') . 'api.php?act=order&pid=' . Config::get('payment.id') . '&key=' . Config::get('payment.key') . '&out_trade_no=' . $payRecord['tradeNo'];
            $respond = getHttpResponse($url);
            $respond = json_decode($respond, true);
            if ($respond['code'] == 1 && $respond['status'] == 1) {
                $payRecord->type = 2;
                $payRecord->save();

                $userModel = new UserModel();
                $user = $userModel->where('id', $payRecord['userId'])->find();
                $user->rCount = $user->rCount + $payRecord['money'];
                $user->save();

                $financeRecordModel = new FinanceRecordModel();
                $financeRecordModel->save([
                    'userId' => $payRecord['userId'],
                    'action' => 1,
                    'count' => $payRecord['money'],
                    'recordInfo' => [
                        'message' => '订单(#' . $payRecord['tradeNo'] . ')用户手动补单支付成功，兑换成' . $payRecord['money'] . 'R币 + ' . $payRecord['give'] . '赠送R币',
                    ]
                ]);

                return json(['code' => 200, 'message' => '订单已支付']);
            } else {
                $payRecordInfoArray = json_decode(json_encode($payRecord['payRecordInfo']), true);
                if (isset($payRecordInfoArray['payUrl'])) {
                    return json(['code' => 200, 'message' => '订单未支付，请扫码支付', 'payUrl' => $payRecordInfoArray['payUrl']]);
                } else {
                    return json(['code' => 400, 'message' => '订单无法重新支付，请重新下单']);
                }
            }
        }
    }
}

