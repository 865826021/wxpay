<?php
namespace common\lib\Wxpay\Extend;
use common\lib\WxPay\WxPayApi;
use common\models\WxpayRecord;
use yii;
use common\models\Order;
use common\models\Paylog;
use common\models\Cashrecord;
require(__DIR__ . '/../lib/WxPay.Api.php');
require(__DIR__ . '/../lib/WxPay.Notify.php');


/**
 * 
 * 支付回调
 * @author widyhu
 *
 */
class PayNotify extends \WxPayNotify{
	//查询订单
	public function Queryorder($transaction_id)
	{
		$input = new \WxPayOrderQuery();//$this->wxpaycfg
		$input->SetTransaction_id($transaction_id);
		$result = WxPayApi::orderQuery($input);
		if(array_key_exists("return_code", $result)
			&& array_key_exists("result_code", $result)
			&& $result["return_code"] == "SUCCESS"
			&& $result["result_code"] == "SUCCESS")
		{
			return true;
		}
		return false;
	}
	
	//重写回调处理方法，成功的时候返回true，失败返回false，处理商城订单
	public function NotifyProcess($data, &$msg)
	{
		$notfiyOutput = array();
		if(!array_key_exists("transaction_id", $data)){
			$msg = "输入参数不正确";
            throw new \WxPayException($msg);
//			return false;
		}
		//查询订单，判断订单真实性
		if(!$this->Queryorder($data["transaction_id"])){
			$msg = "订单查询失败";
            throw new \WxPayException($msg);
//			return false;
		}
                //业务逻辑
		// 保存微信支付订单流水
		$WxPayRecord = new WxpayRecord();
		$WxPayRecord->setAttributes($data);
		$WxPayRecord->id = 0;
        if($WxPayRecord->save(false) === false)
			throw new \WxPayException('支付记录保存失败！');
		$ordersn = $data['out_trade_no'];
		if(!empty($data['attach']))
			$where['ordersn'] = $data['attach'];
		else
			$where['ordersn'] = $ordersn;
		$where['ispay'] = 2;
		$transaction = Yii::$app->db->beginTransaction();
		try{
			//修改订单状态
			$order = new Order();
			$order= $order->getOne($where);
			$order->ispay = Order::STATUS_PAID;
			$order->status = Order::STATUS_PAYED;
			if(!$order->save(false))
				throw new \WxPayException('支付失败！');
			$cashRecord = new Cashrecord();
			if($cashRecord->inComes($order->ordersn) === false)
				throw new \WxPayException('支付金额记录失败！');
			//记录支付流水
			$paylog = new Paylog();
			$paylog->ordersn = $ordersn;
			$paylog->payswiftnumber = $data['transaction_id'];
			$paylog->status = $paylog::STATUS_NORMAL;
			$paylog->text = $data['trade_type'];
			if(!$paylog->save(false))
				throw new \WxPayException('支付日志记录失败！');
			$transaction->commit();
			return true;
		}catch (\Exception $e){
			$transaction->rollBack();
			return false;
		}

	}
}
