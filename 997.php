<?php
/**
 * EDI 997 Purchase Order
 * @author zhengqi 2017-9-26 18:18:07
 */
class 997 extends Model{
	
	/**
	 * @var 结构树
	 */
	public $_tree = array(
			'GroupHeader'=>array(
					'InterchangeControlHeader'=>'ISA',
					'FunctionalGroupHeader'=>'GS'
			),
			'Heading'=>array(
				'TransactionSetHeader'=>'ST',
			),
			'Detail'=>array(
				'FunctionalGroupResponseHeader'=>'AK1',
				'FunctionalGroupResponseTrailer'=>'AK9',
			),
			'Summary'=>array(
					'TransactionSetTrailer'=>'SE',
			),
			'GroupTrailer'=>array(
					'FunctionalGroupTrailer'=>'GE',
					'InterchangeControlTrailer'=>'IEA'
			)
	);
	
	/**
	 * @var 字段映射
	 */
	//ISA
	public $ISA = array(
			'01'=>'AuthorizationInformationQualifier',
			'02'=>'AuthorizationInformation',
			'03'=>'SecurityInformationQualifier',
			'04'=>'SecurityInformation',
			'05'=>'InterchangeIDQualifier',
			'06'=>'InterchangeSenderID',
			'07'=>'InterchangeIDQualifier',
			'08'=>'InterchangeReceiverID',
			'09'=>'InterchangeDate',
			'10'=>'InterchangeTime',
			'11'=>'RepetitionSeparator',
			'12'=>'InterchangeControlVersionNumber',
			'13'=>'InterchangeControlNumber',
			'14'=>'AcknowledgmentRequested',
			'15'=>'UsageIndicator',
			'16'=>'ComponentElementSeparator'
	);
	//GS
	public $GS = array(
			'01'=>'FunctionalIdentifierCode',
			'02'=>'ApplicationSenderCode',
			'03'=>'ApplicationReceiverCode',
			'04'=>'Date',
			'05'=>'Time',
			'06'=>'GroupControlNumber',
			'07'=>'ResponsibleAgencyCode',
			'08'=>'Version',
	);
	//ST
	public $ST = array(
			'01'=>'TransactionSetIdentifierCode',
			'02'=>'TransactionSetControlNumber',
	);
	//AK1
	public $AK1 = array(
			'01'=>'FunctionalIdentifierCode',
			'02'=>'GroupControlNumber',
	);
	//AK9
	public $AK9 = array(
			'01'=>'FunctionalGroupAcknowledgeCode',
			'02'=>'NumberofTransactionSetsIncluded',
			'03'=>'NumberofReceivedTransactionSets',
			'04'=>'NumberofAcceptedTransactionSets',
	);
	//SE
	public $SE = array(
			'01'=>'NumberOfIncludedSegments',
			'02'=>'TransactionSetControlNumber',
	);
	//GE
	public $GE = array(
			'01'=>'NumberOfTransactionSetsIncluded',
			'02'=>'GroupControlNumber',
	);
	//IEA
	public $IEA = array(
			'01'=>'NumberOfIncludedFunctionalGroups',
			'02'=>'InterchangeControlNumber',
	);
	
	/*
	 * 报文类型标识符对应订单状态
	* */
	public  $IdentifierCode=array(
			'PR'=>'2',  //855
			'SH'=>'4', //856
 			'IN'=>'4', //810
	);
		
	/*
	 * amazon响应状态
	* */
	public  $ResponseTrailer=array(
			'A', //Accepted
			'E', //Accepted, But Errors Were Noted.
	);
		
	
	/**
	 * 解析订单数据
	 * @param array $file
	 * @return multitype:string |multitype:string NULL
	 */
	public function AnalysisFile997($file) {
		$edi = file_get_contents($file['link']);
		$ediArr = $this->Parser($edi);
		$orderDetail = array();
		//解析成功
		if($ediArr ['ask'] === 1){
			$data = $ediArr['data'];
// 			print_r($data);die;
			if($data['Heading']['TransactionSetHeader']['TransactionSetIdentifierCode']  == '997'){
				//原始订单id
				$av_id			   = $data['Detail']['FunctionalGroupResponseHeader']['GroupControlNumber'];
				//通过状态
				$file_id            = $file['f_id'];
				//响应状态
				$reponse_code = $data['Detail']['FunctionalGroupResponseTrailer']['FunctionalGroupAcknowledgeCode'];
				//订单状态
				$order_status  = $this->IdentifierCode[$data['Detail']['FunctionalGroupResponseHeader']['FunctionalIdentifierCode']];
				$this->saveStatus($av_id, $order_status, $file_id, $reponse_code, $file['link']);
			}
			
		}else{
			throw new Exception($edifile." 文件解析失败");
		}
	}

	/**
	 * 更新订单状态
	 */
	public function saveStatus($VcOrderAvid, $order_status, $file_id, $reponse_code, $file_path) {
		if(empty($VcOrderAvid)){
			throw new Exception ('_VcOrderAvId is empty'); 
		}
		if(empty($order_status)){
			throw new Exception ('_OrderStatus is empty');
		}
		if(empty($file_id)){
			throw new Exception ('_FileId is empty');
		}
		if(empty($reponse_code)){
			throw new Exception ('_reponse_code is empty');
		}
		$db = Common_Common::getAdapter();
// 		$db->beginTransaction();
		try {
			$amazonVcOrder = Service_AmazonVcOrders::getByField($VcOrderAvid);
			//确认文件已处理
			if(!empty($amazonVcOrder)){
				Service_AmazonVcFiles::update(array('is_create_order'=>1,'create_order_time'=>date('Y-m-d H:i:s')),$file_id);
// 				$db->commit();return ;
			}
			//响应为通过状态
			if(in_array($reponse_code, $this->ResponseTrailer)){
				$old_order_status_arr = Service_Orders::getByField($amazonVcOrder['purchase_order_sn'], 'refrence_no', 'order_status');
				$old_order_status = $old_status_arr['0']['order_status'];
				//新状态<目前状态时不更新
				if($order_status < $old_order_status){
					throw new Exception ($amazonVcOrder['purchase_order_sn'].'重复解析');
				}
				$order_info = array(
						'order_status'=>$order_status
				);
				if( !Service_Orders::update($order_info, $amazonVcOrder['purchase_order_sn'],'refrence_no')){
					throw new Exception ($amazonVcOrder['purchase_order_sn'].'update status Error');
				}
				Ec::showError ( "[".$file_id."]文件为：" . $file_path . '验证通过'."content 为".file_get_contents($file_path),"AmazonVc/edi 997_".date('Y-m-d')  );
			}else{//拒绝状态
				$amazonVcOrder = Service_AmazonVcOrders::getByField($av_id);
				if(!Service_Orders::update(array('sync_status'=>5), $amazonVcOrder['purchase_order_sn'],'refrence_no')){
					throw new Exception ($amazonVcOrder['purchase_order_sn'].'update status  Error');
				};
				Ec::showError ( "[".$file_id."]文件为：" . $file_path . '验证不通过'."content 为".file_get_contents($file_path),"AmazonVc/edi 997_".date('Y-m-d')  );
			}
			
			
// 			$db->commit();
		} catch (Exception $e) {
// 			$db->rollback();
			throw new Exception ('saveStatus Error : '.$e->getMessage());
		}
	}

	
	

	

}
