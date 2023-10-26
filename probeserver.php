<?php  
error_reporting(E_ALL ^ E_DEPRECATED);
ini_set('date.timezone','Asia/Shanghai');

//确保在连接客户端时不会超时  
set_time_limit(0);
ini_set("memory_limit", "2048M");
ini_set("display_errors","On");
error_reporting(E_ERROR);

global $address; 
global $port;

$address = "192.168.1.106";
$port = 9034;

/*

try {
    $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP) or die("socket_create() reason:" . socket_strerror(socket_last_error()) . "\n");
    socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array("sec" => 10, "usec" => 0));
    socket_set_nonblock($sock) or die("socket_set_nonblock() reason:" . socket_strerror(socket_last_error()) . "\n");
    $result = socket_bind($sock, $address, $port) or die("socket_bind() reason:" . socket_strerror(socket_last_error()) . "\n");
    // UDP不需要监听，所以不需要调用 socket_listen()

    // 建立数据库连接
    global $DB;
    $DB = new Mysql('localhost:3306', 'root', '', 'muchang', "utf8");
    $DB->connect();
} catch (Exception $e) {
    echo date('Y-m-d H:i:s', time()) . " DB connect error<br>\n";
    var_dump($e);
    exit;
}

*/


try{
    $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP) or die("socket_create() reason:" . socket_strerror(socket_last_error()) . "\n");  
    socket_set_option($socket,SOL_SOCKET, SO_RCVTIMEO, array("sec"=>10, "usec"=>0));
    socket_set_nonblock($sock) or die("socket_set_nonblock() reason:" . socket_strerror(socket_last_error()) . "\n");  
    $result = socket_bind($sock, $address, $port) or die("socket_bind() reason:" . socket_strerror(socket_last_error()) . "\n");  
    $result = socket_listen($sock, 4) or die("socket_listen() reason:" . socket_strerror(socket_last_error()) . "\n");  
    global $DB;
    $DB=new Mysql('localhost:3306','root','','muchang',"utf8"); 
    $DB->connect();
}
catch(Exception $e){
    echo date('Y-m-d H:i:s',time())." DB connect error<br>\n";
    var_dump($e);
    exit;
}



echo date('Y-m-d H:i:s',time())."  OK Binding the socket on $address:$port ... ;\n";  
//echo date('Y-m-d H:i:s',time())."  OK Now ready to accept connections. Listening on the socket ... ;\n";  
$lastsendtime=0;
global $con_array;
$con_array=array();
$lastsendtime=0;
do { // never stop the daemon  
    try
    {
        $connection = socket_accept($sock);
        if($connection>0){
            socket_set_nonblock($connection);
            $con_array[]=array($connection,time(),new Queue(1024*10),1);
            //echo date('Y-m-d H:i:s',time())."  new connection=$connection; connection count=".count($con_array).";\n";  
        }
        foreach ($con_array as $key => $conn) {
            $flg=handleSocket($DB,$conn[0],$con_array[$key][1],$conn[2],$con_array[$key][3]);
            if($flg==-1){
                socket_close($conn[0]);  
                unset($con_array[$key]);
            }
        }
        
        if(time()-$lastsendtime>5){
            $lastsendtime=time();
            sendCtrlFrameToClient($DB);
        }
        usleep(500000);
        /*if( time() >= (strtotime(date('Y-m-d ',time() )."23:59:20")) ){
            echo date('Y-m-d H:i:s',time())."  close all client connection;\n";  
            foreach ($con_array as $key => $conn) {
                socket_close($conn[0]);  
            }
            break;
        }//stop daoerji*/
    }
    catch(Exception $e)
    {
        echo date('Y-m-d H:i:s',time())." Exception xxxxxxxxxxxxxxxxxxxxxxxxxxxxxx<br>\n";
        var_dump($e);
    }

} while (true);  
socket_close($sock);  
$DB->close();
echo date('Y-m-d H:i:s',time())."  application stop.\n";  
exit;
    
    
function handleSocket(Mysql $DB,$connection,&$errortime,Queue $QE,&$isFirstTime)
{
    global $port;
    if ($connection > 0)
    {
        $str = socket_read($connection, 1024*50);
        if(strlen($str)>0)
            echo date('Y-m-d H:i:s',time())." RECV=   ".$str."<br>\n";
        if($str===false || $str===""){
            if(time()-$errortime>360){//连续70分钟读取错误,关闭
                //echo date('Y-m-d H:i:s',time())."  connection=".$connection.";  no data read from it since last 70min, so close connection;\n";  
                return -1;//del it
            }else{
                return 0;
            }
        }else{
            $errortime=time();
            $ppp1=stripos($str,":");
            $ppp2=stripos($str,".");
            if($isFirstTime==1 && $ppp1===false && $ppp2===false){
                //echo date('Y-m-d H:i:s',time())."  connection=".$connection."; not found flag : or . ,so close connection.\n";  
                $isFirstTime=0;
                return -1;
            }

            for($i=0; $i<strlen($str); $i++){
                $w1=$str[$i];
                if(!$QE->in($w1)){
                    echo date('Y-m-d H:i:s',time())."  Queue Over Flow;\n"; 
                    //echo $QE->toString();
                    handleQueue($connection,$QE,$DB);
                    if(!$QE->in($w1)){
                        echo date('Y-m-d H:i:s',time())."  Queue Over Flow agine! xxxxxxxxxxxxxxxxx;\n"; 
                    }
                }
            }
            handleQueue($connection,$QE,$DB);
            $isFirstTime=0;
            return 1;//OK
        }
    }else{
        $isFirstTime=0;
        return -1;//del from array
    }
}
    
function handleQueue($connection,Queue $QE,Mysql $DB)
{
    while($QE->getCount()>=3){
        $d=$QE->get(0);
        if($d==':'){//start flag
            $fcode=$QE->get2(1);//功能码
            if($fcode===false){//不够一帧,等待
                //echo date('Y-m-d H:i:s',time())."  break wait 0;\n";  
                break;
            }else if($fcode=='00'){//探针数据
                $len=44;
                if($QE->getCount()>=$len){//带功率的帧
                    if($QE->get(($len-1))=='.'){//找到结束标记
                        $data=array();
                        for($i=0; $i<$len; $i++)
                            $data[]=$QE->out();
                        saveProbe($DB,$data);
                    }else{
                        $d=$QE->out();//丢弃,开始滑动
                        //echo date('Y-m-d H:i:s',time())."  shift drop 1: ".$d.";\n";  
                    }
                }else{
                    //echo date('Y-m-d H:i:s',time())."  break wait 1;\n";  
                    break;//不够一帧,等待
                }
            }else if($fcode=='01'){//探针配置数据
                $len=82;
                if($QE->getCount()>=$len){//带功率的帧
                    if($QE->get(($len-1))=='.'){//找到结束标记
                        $data=array();
                        for($i=0; $i<$len; $i++)
                            $data[]=$QE->out();
                        saveProbeConfig($DB,$data);
                    }else{
                        $d=$QE->out();//丢弃,开始滑动
                        //echo date('Y-m-d H:i:s',time())."  shift drop 1: ".$d.";\n";  
                    }
                }else{
                    //echo date('Y-m-d H:i:s',time())."  break wait 1;\n";  
                    break;//不够一帧,等待
                }
            }
            else{
                $d=$QE->out();//丢弃,开始滑动
                //echo date('Y-m-d H:i:s',time())."  shift drop 99: ".$d.";\n";  
            }
            
        }else{
            $d=$QE->out();//丢弃,开始滑动
            //echo date('Y-m-d H:i:s',time())."  shift drop 999: ".$d.";\n";  
        }
    }
    
    //echo "\n";
}

 
 function hexOr($byte1, $byte2)
{
	$result='';
    $byte1= str_pad(base_convert($byte1, 16, 2), '8', '0', STR_PAD_LEFT);
    $byte2= str_pad(base_convert($byte2, 16, 2), '8', '0', STR_PAD_LEFT);
    $len1 = strlen($byte1);
    for ($i = 0; $i < $len1 ; $i++) {
        $result .= $byte1[$i] == $byte2[$i] ? '0' : '1';
    }
    return strtoupper(base_convert($result, 2, 16));
}

function hexOrArr($data)
{
    $result = $data[0];
    for ($i = 0; $i < count($data) - 1; $i++) {
        $result = hexOr($result, $data[$i + 1]);
    }
    return $result;
}

function mbStrSplit($string, $len = 1)
{
    $start = 0;
    $strlen = mb_strlen($string);
    while ($strlen) {
        $array[] = strtoupper(mb_substr($string, $start, $len, "utf8"));
        $string = mb_substr($string, $len, $strlen, "utf8");
        $strlen = mb_strlen($string);
    }
    return $array;
}
 
 

function saveProbe(Mysql $DB,$data)
{
    //“:000004D201112233445566AABBCCDDEEFFC6.”
    //:00000000080108FFFFFFFFFFFF002EC703D7A1AA3F.
    $dt=date('Y-m-d H:i:s',time());
    //print_r($data);
    $str = implode($data);
    //echo $str."\r\n";
    $devid =hexdec(substr($str,3,8));
    $ch   =hexdec(substr($str,11,2));
    $type   =substr($str,13,2);
    $cmac  =substr($str,15,12);
    $apmac   =substr($str,27,12);
    $rssi   =hexdec(substr($str,39,2));
	$crc   =substr($str,41,2);  //校验
    if($rssi>127)//功率补码转负值
        $rssi=(256-$rssi)*(-1);	
		
	$crc1  =substr($str,11,30);
	$sum=hexOrArr(mbStrSplit($crc1, 2));
	if($sum == $crc && $ch != 0){
	//if($ch != 0 && $ch != 255 && $ch < 20 && $type != 'FF' && $type >0 &&$rssi < 0 ){
		
     $sql="insert into probe(devid,chanel,type,cmac,apmac,rssi,dt)";
     $sql=$sql." values($devid,$ch,$type,'$cmac','$apmac',$rssi,'$dt')";
     $DB->query($sql);  
    echo date('Y-m-d H:i:s',time())." Probe $devid, $ch, $type, $cmac, $apmac, $rssi\n";
	}
}



function saveProbeConfig(Mysql $DB,$data)
{
/*
“:”：固定帧起始符号
“CMD”: 命令(2字节Hex) 01:探针参数
“DEV”：设备号(8字节Hex),比如十进制1234,传“000004D2”
“CIP”：客户端IP(8字节Hex),比如 192.168.1.100,传“C0A80164”
“CPORT”：客户端口(4字节Hex),比如 5000,传“1388”
“MASK”：子网掩码IP(8字节Hex),比如 255.255.255.0,传“FFFFFF00”
“GATE”：网关IP(8字节Hex),比如 192.168.1.1,传“C0A80101”
“SIP”：服务端IP(8字节Hex),比如 192.168.1.1,传“C0A80101”
“SPORT”：服务端口(4字节Hex),比如 5000,传“1388”
“CH1”：通道1号(2字节Hex),比如 10,传“0A”
“CH2”：通道2号(2字节Hex),比如 11,传“0B”
“CH3”：通道3号(2字节Hex),比如 12,传“0C”
“CH4”：通道4号(2字节Hex),比如 13,传“0D”
“INTERVAL”：重复帧过滤间隔(4字节Hex),比如 300秒,传“012C”
“TYPE”:4位（0000）从左到右分别对应probe请求帧、数据帧、控制帧、管理帧。0000都不要；1111都要；0001只要管理帧；0101只要管理帧数据帧
“RUNNING1”:运行状态(2字节Hex),00:停止中   01：运行中
“RUNNING2”:运行状态(2字节Hex),00:停止中   01：运行中
“RUNNING3”:运行状态(2字节Hex),00:停止中   01：运行中
“RUNNING4”:运行状态(2字节Hex),00:停止中   01：运行中

“.”：结束符
*/
    $dt=date('Y-m-d H:i:s',time());
    //print_r($data);
    $str = implode($data);
    //echo $str."\r\n";
    $devid =hexdec(substr($str,3,8));
    $cip1   =hexdec(substr($str,11,2));
    $cip2   =hexdec(substr($str,13,2));
    $cip3   =hexdec(substr($str,15,2));
    $cip4   =hexdec(substr($str,17,2));
    $cport   =hexdec(substr($str,19,4));
    $mask1   =hexdec(substr($str,23,2));
    $mask2   =hexdec(substr($str,25,2));
    $mask3   =hexdec(substr($str,27,2));
    $mask4   =hexdec(substr($str,29,2));
    $gate1   =hexdec(substr($str,31,2));
    $gate2   =hexdec(substr($str,33,2));
    $gate3   =hexdec(substr($str,35,2));
    $gate4   =hexdec(substr($str,37,2));
    $sip1   =hexdec(substr($str,39,2));
    $sip2   =hexdec(substr($str,41,2));
    $sip3   =hexdec(substr($str,43,2));
    $sip4   =hexdec(substr($str,45,2));
    $sport   =hexdec(substr($str,47,4));
    $ch1   =hexdec(substr($str,51,2));
    $ch2   =hexdec(substr($str,53,2));
    $ch3   =hexdec(substr($str,55,2));
    $ch4   =hexdec(substr($str,57,2));
    $filter   =hexdec(substr($str,59,4));
    $type1   =hexdec(substr($str,63,2));
	$type2   =hexdec(substr($str,65,2));
	$type3   =hexdec(substr($str,67,2));
	$type4   =hexdec(substr($str,69,2));
    $rssi   =hexdec(substr($str,71,2));
    $running1   =hexdec(substr($str,73,2));
    $running2   =hexdec(substr($str,75,2));
    $running3   =hexdec(substr($str,77,2));
    $running4   =hexdec(substr($str,79,2));
    if($rssi>=128)
        $rssi=(256-$rssi)*(-1);


    $cip=$cip1.".".$cip2.".".$cip3.".".$cip4;
    $mask=$mask1.".".$mask2.".".$mask3.".".$mask4;
    $gate=$gate1.".".$gate2.".".$gate3.".".$gate4;
    $sip=$sip1.".".$sip2.".".$sip3.".".$sip4;
    
    $row=$DB->select_once("probe_config_read"," devid=".$devid);
    if(!is_array($row)){//没有 insert
        $sql="insert into probe_config_read(devid,cip,cport,mask,gate,sip,sport,ch1,ch2,ch3,ch4,filter,running1,running2,running3,running4,type1,type2,type3,type4,rssi,dt)";
        $sql=$sql." values($devid, '$cip', $cport, '$mask', '$gate', '$sip', $sport, $ch1, $ch2, $ch3, $ch4, $filter, $running1, $running2, $running3, $running4,$type1,$type2,$type3,$type4, $rssi, '$dt')";
        $DB->query($sql);
    }else{
        $sql="update probe_config_read set cip='$cip',cport='$cport',mask='$mask',gate='$gate',sip='$sip',";
        $sql=$sql."sport=$sport,ch1=$ch1,ch2=$ch2,ch3=$ch3,ch4=$ch4,filter=$filter,running1=$running1,running2=$running2,running3=$running3,running4=$running4, type1=$type1,type2=$type2,type3=$type3,type4=$type4, rssi=$rssi, ";
        $sql=$sql."dt='$dt' where devid=$devid";
        $DB->query($sql);
    }
        
    echo date('Y-m-d H:i:s',time())." ReadConfig $devid, $cip, $cport, $mask, $gate, $sip, $sport, $ch1, $ch2, $ch3, $ch4, $filter, $running1, $running2, $running3, $running4, $type1, $type2, $type3, $type4, $rssi\n";
}



function sendCtrlFrameToClient(Mysql $DB)
{
        global $con_array;
        $rows=$DB->select_all("probe_config ","sendconfig=1 or sendreset=1 or sendquery=1  or sendrun1=1 or sendstop1=1  or sendrun2=1 or sendstop2=1  or sendrun3=1 or sendstop3=1  or sendrun4=1 or sendstop4=1");
        //print_r($rows);
        if(!empty($rows)>0){
            $sql="update probe_config  set sendconfig=0,sendreset=0,sendquery=0,sendrun1=0,sendstop1=0,sendrun2=0,sendstop2=0,sendrun3=0,sendstop3=0,sendrun4=0,sendstop4=0";
            $DB->query($sql);
            foreach ($rows as $kk => $row){
                
                if($row['sendconfig']==1){
                    $devid=sprintf('%08X',$row['devid']);
                    $tmpip=preg_split('/\./',$row['cip']);
                    $cip=sprintf('%02X%02X%02X%02X',$tmpip[0],$tmpip[1],$tmpip[2],$tmpip[3]);
                    $cport=sprintf('%04X',$row['cport']);
                    $tmpip=preg_split('/\./',$row['mask']);
                    $mask=sprintf('%02X%02X%02X%02X',$tmpip[0],$tmpip[1],$tmpip[2],$tmpip[3]);
                    $tmpip=preg_split('/\./',$row['gate']);
                    $gate=sprintf('%02X%02X%02X%02X',$tmpip[0],$tmpip[1],$tmpip[2],$tmpip[3]);
                    $tmpip=preg_split('/\./',$row['sip']);
                    $sip=sprintf('%02X%02X%02X%02X',$tmpip[0],$tmpip[1],$tmpip[2],$tmpip[3]);
                    $sport=sprintf('%04X',$row['sport']);
                    $ch1=sprintf('%02X',$row['ch1']);
                    $ch2=sprintf('%02X',$row['ch2']);
                    $ch3=sprintf('%02X',$row['ch3']);
                    $ch4=sprintf('%02X',$row['ch4']);
                    $filter=sprintf('%04X',$row['filter']);
                    $type1=sprintf('%02X',$row['type1']);
					$type2=sprintf('%02X',$row['type2']);
					$type3=sprintf('%02X',$row['type3']);
					$type4=sprintf('%02X',$row['type4']);
                    $rssi=substr(sprintf('%X',$row['rssi']),-2);
                    $frame="AT01$devid$cip$cport$mask$gate$sip$sport$ch1$ch2$ch3$ch4$filter$type1$type2$type3$type4$rssi.\r\nxxxxxxxxxxxxx";
                    echo $frame."\r\n";
                    foreach ($con_array as $key => $value) 
                    {
                            $conn=$value[0];
                            socket_send($conn,$frame,76,MSG_OOB);
                    }
					sleep(1);
                }
                
                
                if($row['sendquery']==1){
                    $id=sprintf('%08X',$row['devid']);
                    $frame="AT+QUERY=$id.\r\nxxxxxxxxxxxxx";
                    echo $frame."\r\n";
                    foreach ($con_array as $key => $value) 
                    {
                            $conn=$value[0];
                            socket_send($conn,$frame,20,MSG_OOB);
                    }
					sleep(1);
                }
                
                
                if($row['sendreset']==1){
                    $id=sprintf('%08X',$row['devid']);
                    $frame="AT+RESET=$id.\r\nxxxxxxxxxxxxx";
                    echo $frame."\r\n";
                    foreach ($con_array as $key => $value) 
                    {
                            $conn=$value[0];
                            socket_send($conn,$frame,20,MSG_OOB);
                    }
					sleep(1);
                }
                
                
                if($row['sendrun1']==1){
                    $id=sprintf('%08X',$row['devid']);
                    $frame="AT+RUN=$id"."01.\r\nxxxxxxxxxxxxx";
                    echo $frame."\r\n";
                    foreach ($con_array as $key => $value) 
                    {
                            $conn=$value[0];
                            socket_send($conn,$frame,22,MSG_OOB);
                    }
					sleep(1);
                }
                
                
                if($row['sendstop1']==1){
                    $id=sprintf('%08X',$row['devid']);
                    $frame="AT+STOP=$id"."01.\r\nxxxxxxxxxxxxx";
                    echo $frame."\r\n";
                    foreach ($con_array as $key => $value) 
                    {
                            $conn=$value[0];
                            socket_send($conn,$frame,22,MSG_OOB);
                    }
					sleep(1);
                }
                
                
                if($row['sendrun2']==1){
                    $id=sprintf('%08X',$row['devid']);
                    $frame="AT+RUN=$id"."02.\r\nxxxxxxxxxxxxx";
                    echo $frame."\r\n";
                    foreach ($con_array as $key => $value) 
                    {
                            $conn=$value[0];
                            socket_send($conn,$frame,22,MSG_OOB);
                    }
					sleep(1);
                }
                
                
                if($row['sendstop2']==1){
                    $id=sprintf('%08X',$row['devid']);
                    $frame="AT+STOP=$id"."02.\r\nxxxxxxxxxxxxx";
                    echo $frame."\r\n";
                    foreach ($con_array as $key => $value) 
                    {
                            $conn=$value[0];
                            socket_send($conn,$frame,22,MSG_OOB);
                    }
					sleep(1);
                }
                
                
                if($row['sendrun3']==1){
                    $id=sprintf('%08X',$row['devid']);
                    $frame="AT+RUN=$id"."03.\r\nxxxxxxxxxxxxx";
                    echo $frame."\r\n";
                    foreach ($con_array as $key => $value) 
                    {
                            $conn=$value[0];
                            socket_send($conn,$frame,22,MSG_OOB);
                    }
					sleep(1);
                }
                
                
                if($row['sendstop3']==1){
                    $id=sprintf('%08X',$row['devid']);
                    $frame="AT+STOP=$id"."03.\r\nxxxxxxxxxxxxx";
                    echo $frame."\r\n";
                    foreach ($con_array as $key => $value) 
                    {
                            $conn=$value[0];
                            socket_send($conn,$frame,22,MSG_OOB);
                    }
					sleep(1);
                }
                
                
                if($row['sendrun4']==1){
                    $id=sprintf('%08X',$row['devid']);
                    $frame="AT+RUN=$id"."04.\r\nxxxxxxxxxxxxx";
                    echo $frame."\r\n";
                    foreach ($con_array as $key => $value) 
                    {
                            $conn=$value[0];
                            socket_send($conn,$frame,22,MSG_OOB);
                    }
					sleep(1);
                }
                
                
                if($row['sendstop4']==1){
                    $id=sprintf('%08X',$row['devid']);
                    $frame="AT+STOP=$id"."04.\r\nxxxxxxxxxxxxx";
                    echo $frame."\r\n";
                    foreach ($con_array as $key => $value) 
                    {
                            $conn=$value[0];
                            socket_send($conn,$frame,22,MSG_OOB);
                    }
					sleep(1);
                }
                
            }
        }
}




class Queue{
    private $m_real;  
    private $m_front;  
    private $m_length;
    private $m_data = array();  
    
    public function __construct($maxlen=4096){
        $this->m_real=0;
        $this->m_front=0;
        $this->m_length=$maxlen;
        for($i=0; $i<$this->m_length; $i++){
            $this->m_data[]=0;
        }
    }

    public function in($data) {  
        if(!$this->isFull()){
            $this->m_data[$this->m_real]=$data;
            $this->m_real=($this->m_real+1)%$this->m_length;
            return true;
        }else{
            return false;
        }
    }

    public function out() {
        if(!$this->isEmpty()){
            $rs=$this->m_data[$this->m_front];
            $this->m_front=($this->m_front+1)%$this->m_length;
            return $rs;
        }else{
            return false;
        }
    }

    public function out4() {
        $ch0=$this->out();
        $ch1=$this->out();
        $ch2=$this->out();
        $ch3=$this->out();
        if($ch0===false || $ch1===false || $ch2===false || $ch3===false)
            return false;
        return $ch0.$ch1.$ch2.$ch3;
    }
    
    
    public function get($offset) {
        $tempfront=($this->m_front+$offset)%$this->m_length;
        if($tempfront==$this->m_real){
            return false;
        }else{
            $rs=$this->m_data[$tempfront];
            return $rs;
        }
    }
    
    public function get2($offset) {
        $ch0=$this->get($offset);
        $ch1=$this->get($offset+1);
        if($ch0===false || $ch1===false)
            return false;
        return $ch0.$ch1;
    }
    
    
    public function get4($offset) {
        $ch0=$this->get($offset);
        $ch1=$this->get($offset+1);
        $ch2=$this->get($offset+2);
        $ch3=$this->get($offset+3);
        if($ch0===false || $ch1===false || $ch2===false || $ch3===false)
            return false;
        return $ch0.$ch1.$ch2.$ch3;
    }
    
    public function isEmpty(){
        if($this->m_front==$this->m_real)
            return true;
        else
            return false;
    }
    
    public function isFull(){
        if($this->m_front==($this->m_real+1) % $this->m_length)
            return true;
        else
            return false;
    }
    
    public function toString(){
        $i=0;
        $rs="QE:F=".$this->m_front."; R=".$this->m_real.";";
        while(true){
            $w=$this->get($i);
            if($w===false)
                break;
            $rs=$rs.$w;
            $i++;
        }
        $rs=$rs."....\n";
        return $rs;
    }
    
    public function getCount(){
        if($this->m_real>=$this->m_front)
            return $this->m_real-$this->m_front;
        else
            return ($this->m_real + $this->m_length) - $this->m_front;
    }
}


class Mysql{ 

    private $db_host; 
    private $db_user; 
    private $db_pass; 
    private $db_table; 
    private $conn;
    private $ut; 

    function __construct($db_host,$db_user,$db_pass,$db_table,$ut){ 
        $this->db_host=$db_host; 
        $this->db_user=$db_user; 
        $this->db_pass=$db_pass; 
        $this->db_table=$db_table; 
        $this->host=$ut; 
        //$this->connect(); 
    } 

    function connect(){ 
        $this->conn= mysql_connect($this->db_host,$this->db_user,$this->db_pass) or die("链接错误:".$this->db_table); 
        mysql_select_db($this->db_table,$this->conn); 
        mysql_query("set names '$this->ut'"); 
    }
    
    function close(){ 
        mysql_close($this->conn) or die("关闭错误:".$this->db_table); 
    }

    

    //执行SQL语句的方法
    public function query($sql){
        $this->query=mysql_query($sql);
        return $this->query;
    }
    
    //查询一条记录返回一维数组
    public function select_once($table,$where=1,$fields="*"){
        $sql="select $fields from $table where $where limit 1";
        $this->query($sql);
        $result=$this->_array();
        return $result;
    }
        
    //查询多条记录返回二维数组
    public function select_all($table,$where=1,$fields="*"){
        $sql="select $fields from $table where $where";
        //echo $sql."\r\n";
        $this->query($sql);
        $result="";
        while($rs=$this->_array()){
            $result[]=$rs;
        }
        return $result;
    }
        
    //返回查询记录数
    public function getRowsNum($sql) {
        $query = $this->query ( $sql );
        return mysql_num_rows ( $query );
    }

    //mysql_fetch_array
    private function _array(){
        return $rs=mysql_fetch_array($this->query);
    }
}

?>
