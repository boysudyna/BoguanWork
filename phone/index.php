<?php
/*
* 统计boguan每月对账数据
* 写入到数据库程序
* Add: 批量执行文件
* Add: 补入先前无限开通后面取消的用户，唯一key增加上type
* Add：增加一列更新日期，插入无限开通日期后取消的操作时间， 增加数据源的日志文件名
*/

define('DEBUG', true);
set_time_limit(0);
include '../library.inc.php';
if($_GET['key'] != 'exec')
    exit("请确认后在访问！");


$dbConfig = array(
    'host'    => '127.0.0.1', 
    'port'  => '3306',
    'db'      => 'test', 
    'user' => 'root', 
    'pass'  => 'root', 
);


$db = ConnectMysqli::getIntance($dbConfig);

echo  '开始创建数据库 =====<br />';
$tableName = "t_phone_draft";
$onlyFile = $_GET['file'];

$hasSql = "SHOW TABLES LIKE '{$tableName}'";
$hasRet = $db->getRow($hasSql);
if ($hasRet) {
    // $sql = "TRUNCATE TABLE {$tableName}";
    // $db->query($sql);
} else {
    $createSql = <<<SQL
        CREATE TABLE {$tableName} (
            `pd_id` bigint(11) NOT NULL,
            `pd_type` int(11) NOT NULL, -- 1无限开通  3有限开通
            `pd_phone` bigint(11) NOT NULL,
            `pd_province` varchar(50) NOT NULL,
            `pd_city` varchar(50) NOT NULL,
            `pd_handset` varchar(50) NOT NULL,
            `pd_color` varchar(20) NOT NULL,
            `pd_imei` varchar(20) NOT NULL,
            `pd_price` int(11) NOT NULL,
            `pd_sdate` datetime NOT NULL,
            `pd_edate` datetime NOT NULL,
            `pd_name` int(11) NOT NULL,
            `pd_mtime` int(11) NOT NULL,
            `pd_channeltype` int(11) NOT NULL,
            `pd_storeid` int(11) NOT NULL,
            `pd_file` char(6) NOT NULL,
            `pd_adddate` datetime NOT NULL,
            UNIQUE  `U_index` (`pd_phone`,`pd_type`,`pd_sdate`,`pd_name`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COMMENT='手机保障数据表汇总'
SQL;
    $db->query($createSql);
}

echo  '数据库创建完成 =====<br />';

echo  '开始执行写入 =====<br />';
$dir = './data/';
$fileList = scandir($dir);
$sql = "INSERT IGNORE INTO {$tableName} VALUES ";

$stime = microtime(true);
foreach($fileList as $file){
    if($file == '.' || $file =='..')
        continue;

    if(!$onlyFile || $onlyFile == $file){
        $handle  = fopen ($dir.$file, "r");
        $i = $ri = 0;
        while (!feof ($handle)) {
            $buffer  = fgets($handle, 4096);
            // 跳过第一条数据字段名
            if ($i == 0)  {
                $i ++;
                continue;
            }  

            $strings = trim($buffer);
            // 按 | 分割数据，最后一条总记录也要跳过
            $pos = strpos($strings, '|');
            if ($pos === false) {
                $i ++;
                continue;
            }

            $arr = explode('|', $strings);
            array_map(addslashes, $arr);
            array_push($arr, $file);
            array_push($arr, '3000-01-01 00:00:00');
            $arrStr .= '(\'' . implode('\',\'', $arr) . '\'),';
            if ($i % 1000 == 0) {
                $sqlStr = substr($sql . $arrStr, 0, -1);
                $rr = $db->query($sqlStr);
                $arrStr = '';
            } 

            $i++;
        }

        if ($arrStr) {
            $sqlStr = substr($sql . $arrStr, 0, -1);
            $rr = $db->query($sqlStr);
            $arrStr = '';
        }

        fclose ($handle);
    }
}


$etime = microtime(true);
$utime = $etime - $stime;
$i -= 2;
echo "全部写入成功, 耗时：{$utime}s =====<br />";

// 更新之前开通无限后面关闭无限的客户(有些客户存在一样的三条记录，其他type=2的应该可以归类异常)
$sql = "select * from {$tableName} where pd_adddate ='3000-01-01 00:00:00' group by pd_id having count(*) >1";
$repArr = $db->getAll($sql);
foreach ($repArr as $k => $v) {
    $upSql = "update {$tableName} set pd_adddate=pd_sdate where pd_id={$v['pd_id']} and pd_type=1";
    $db->query($upSql);
}

$eetime = microtime(true);
$utime = $eetime - $etime;
echo "执行开通无限保障后面关闭的用户, 耗时：{$utime}s =====<br />";
exit;

