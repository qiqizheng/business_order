<?php
/**
 * AmazonVc EDI 模型类
 * @author 
 */
class AmazonVc_EDI_Model{

	/**
	 * @var 基础配置
	 */
	public $_segment = '~';//数据段分隔符
	public $_field = '*';//字段分隔符
	public $_component = '^';//部件分隔符
	public $_sub_component = '';//子部件分隔符
	public $_escape = '?';//转义符
	
	/**
	 * @var 运行变量
	 */
	private $_edi = '';//edi原始数据
	public $_fieldDataArr = array();//平面的数组
	public $_fieldDataLength = 0;//平面数组长度
	public $_line = 0;//当前读取到的数据行
	public $_readCount = 0;//读取结构树递归计数
	
	/**
	 * @var 异常信息
	 */
	protected $errorInfo = array(
	    'Message'=>'',
	    'ErrorType'=>'',
	);
	
	/**
	 * @var 需子类定义
	 */
	public $_tree = array();
	
	/**
	 * EDI文件解析
	 * @param string $edi
	 */
	public function Parser($edi){
	    $return = array(
	        'ask'=>0,
	        'message'=>'Parser Error',
	        'data'=>array(),
	        'error_type'=>'',
	        'org'=>$edi,
	    );
	    
	    try {
	        /**
	         * 1、初始化解析
	         */
	        $this->_parserInit($edi);
	        
	        /**
	         * 2、edi文件解析成平面数组
	        */
	        $this-> _ediToDataArr();
	    
	        /**
	         * 3、读取结构树
	        */
	        $data = array();
	        $this->_readTree($this->_tree, $data);
	         
	        
	        /**
	         * 4、解析结果检查
	        */
	        $this->_verifyParser($data);
	    
	        /**
	         * 5、整理返回数据
	        */
	        $return['ask'] = 1;
	        $return['data'] = $data;
	        $return['message'] = 'success';
	    } catch (AmazonVc_Exception $e) {
	        $return['message'] = $e->getErrorMessage();
	        $return['error_type'] = $e->getErrorType();
	    }
	     
	    return $return;
	}
	
	/**
	 * 解析结果检查
	 * @param array $data
	 * @throws AmazonVc_Exception
	 */
	public function _verifyParser($data){
	    if(empty($data)){
	        $this->errorInfo['Message'] = "data Is Empty";
	        $this->errorInfo['ErrorType'] = 'Parser Error';
	        throw new AmazonVc_Exception($this->errorInfo);
	    }
	}
	
	/**
	 * 读取结构树
	 * @param array $tree 结构树
	 * @param array $handle 数据句柄
	 */
	public function _readTree($tree,&$handle){
	    //递归次数检查
	    $maxReadCount = $this->_fieldDataLength * 2;//最大递归次数设为平面文件长度两倍
	    if($this->_readCount > $maxReadCount){
	    	$msg = "Max readTree of 2*fieldDataLength[{$maxReadCount}] reached,aborting;tree:".print_r($tree,1);
	        $this->errorInfo['Message'] = $msg;
	        $this->errorInfo['ErrorType'] = 'Internal Error';
	        throw new AmazonVc_Exception($this->errorInfo);
	    }
	    if($tree){
	        if(is_array($tree)){
	            if($this->_isLoopTree($tree)){//循环分支
	                $loopTree = current($tree);
	                $code = is_array($loopTree) ? current($loopTree) : $loopTree;
	                $key = 0;
	                $handle[$key] = array();
	                while ($this->_isThisLine($code)){
	                    $this->_readTree($loopTree,$handle[$key]);
	                    $key++;
	                }
	            }else{//普通分支
	                foreach ($tree as $k=>$v){
	                    if(is_array($v)){
	                        $this->_readTree($v,$handle[$k]);
	                    }else if(is_string($v)){
	                        $handle[$k] = $this->_getSegment($v);
	                    }
	                }
	            }
	        }else if(is_string($tree)){
	            $handle = $this->_getSegment($tree);
	        }
	    }
	    $this->_readCount++;
	}
	
	/**
	 * 读取一个数据段
	 * @param string $code
	 */
	public function _getSegment($code){
	    $return = array();
	    if($this->_isThisLine($code)){
	        //取映射
	        $map = isset($this->$code) ? $this->$code: array();
	        foreach ($this->_fieldDataArr[$this->_line] as $k=>$v){
// 	            if($k!=0){continue;}
	            $pos = str_pad($k, 2, '0', STR_PAD_LEFT);//补足2位数
	            $fieldName = isset($map[$pos]) ? $map[$pos] : $pos ;
	            $return[$fieldName] = $v;
	        }
	        $this->_line++;
	    }
	    return $return;
	}
	
	/**
	 * edi文件解析成平面数组
	 * @throws AmazonVc_Exception
	 */
	public function _ediToDataArr(){
	    //转义处理
	    $segment = quotemeta($this->_segment);
	    //拆分数据段
	    $segmentDataArr = preg_split("/{$segment}[\r\n]{0,1}/", $this->_edi);
	    if(!is_array($segmentDataArr) || !(count($segmentDataArr)>1)){
	        $this->errorInfo['Message'] = "EDI Format Is Not Legal";
	        $this->errorInfo['ErrorType'] = 'Parameter Error';
	        throw new AmazonVc_Exception($this->errorInfo);
	    }
	    //转义处理
	    $field = quotemeta($this->_field);
	    foreach ($segmentDataArr as $k=>$segmentData){
	        $segmentData = trim($segmentData);
	        if($segmentData){
	            $fieldData = preg_split("/{$field}/", $segmentData);
	            if(!is_array($fieldData) || !(count($fieldData)>1)){
	                $line = $k + 1;
	                $this->errorInfo['Message'] = "line[$line]:{$segmentData}";
	                $this->errorInfo['ErrorType'] = 'Parser Error';
	                throw new AmazonVc_Exception($this->errorInfo);
	            }
	            $this->_fieldDataArr[] = $fieldData;
	        }
	    }
	    //解析结果校验
	    if(empty($this->_fieldDataArr)){
	        $this->errorInfo['Message'] = "Parse field error";
	        $this->errorInfo['ErrorType'] = 'Parser Error';
	        throw new AmazonVc_Exception($this->errorInfo);
	    }
	    //设置平面数组长度
	    $this->_fieldDataLength = count($this->_fieldDataArr);
	}
	
	/**
	 * 初始化解析
	 * @param string $edi
	 * @throws AmazonVc_Exception
	 */
	public function _parserInit($edi){
	    /**
	     * 1、基本校验
	     */
	    //edi
	    if(empty($edi)){
	        $this->errorInfo['Message'] = "EDI Is Empty";
	        $this->errorInfo['ErrorType'] = 'Parameter Error';
	        throw new AmazonVc_Exception($this->errorInfo);
	    }
	    //segment
	    if(empty($this->_segment)){
	        $this->errorInfo['Message'] = "_segment Is Empty";
	        $this->errorInfo['ErrorType'] = 'Parameter Error';
	        throw new AmazonVc_Exception($this->errorInfo);
	    }
	    //field
	    if(empty($this->_field)){
	        $this->errorInfo['Message'] = "_field Is Empty";
	        $this->errorInfo['ErrorType'] = 'Internal Error';
	        throw new AmazonVc_Exception($this->errorInfo);
	    }
	    //component
	    if(empty($this->_component)){
	        $this->errorInfo['Message'] = "_component Is Empty";
	        $this->errorInfo['ErrorType'] = 'Internal Error';
	        throw new AmazonVc_Exception($this->errorInfo);
	    }
	    //tree
	    if(empty($this->_tree)){
	        $this->errorInfo['Message'] = "Tree is not defined";
	        $this->errorInfo['ErrorType'] = 'Internal Error';
	        throw new AmazonVc_Exception($this->errorInfo);
	    }
	    
	    /**
	     * 2、设置edi原始数据
	     */
	    $this->_edi = $edi;
	    
	    /**
	     * 3、初始化运行变量
	     */
	    $this->_fieldDataArr = array();//平面的数组
	    $this->_fieldDataLength = 0;//平面数组长度
	    $this->_line = 0;//当前读取到的数据行
	    $this->_readCount = 0;//读取结构树递归计数
	    $this->errorInfo = array(
	        'Message'=>'',
	        'ErrorType'=>'',
	    );
	}
	
	/**
	 * 返回一个结构树是否为循环分支
	 * @param array $tree
	 */
	public function _isLoopTree($tree){
	    /*
	     * 以下两种结构满足循环分支结构:
	     * 1、非空、有且只有一个元素(记为A)且A为下标数组、A中的第一个元素的值为字符串
	     * $tree = array(
	     *         array(
	     *             'ReferenceIdentification'=>'N9',
	     *             'MessageText'=>'MSG',
	     *             .....
	     *         )
	     * );
	     * 
	     * 1、非空且为下标数组、有且只有一个元素且该元素为字符串
	     * $tree = array('REF');
	     */
	    $return = false;
	    if(!empty($tree) && is_array($tree) && array_keys($tree)===array(0)){
	        $item = current($tree);
	        if(!empty($item)){
	        	if(is_string($item)){
	        		$return = true;
	        	}elseif(is_array($item)){
	        		$A = current($item);
	        		$return = !empty($A) && is_string($A);
	        	}
	        }
	    }
	    return $return;
	}
	
	/**
	 * 返回当前行是否是对应的数据段
	 * @param string $code 数据段代码
	 * @return boolean 
	 */
	public function _isThisLine($code){
		return $code && isset($this->_fieldDataArr[$this->_line]) && $this->_fieldDataArr[$this->_line][0] == $code;
	}

}
