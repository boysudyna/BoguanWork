<?php
/*
* 统计boguan每月对账数据
* 计算核对数据
*/
set_time_limit(0);
include '../../Library/mysqli.class.php';
date_default_timezone_set('Asia/Shanghai');
ini_set('display_errors', true);
error_reporting(E_ALL ^ E_NOTICE);

$dbConfig = array(
    'host'    => '127.0.0.1', 
    'port'  => '3306',
    'db'      => 'test', 
    'user' => 'root', 
    'pass'  => 'root', 
);

$currTable = date('Ym');

$beginTable = $fileName = $_GET['file'] ? $_GET['file'] : '201801';
$putFileName = 'count_'.$fileName.'.txt';
// 重置表内容
$fopen = file_put_contents($putFileName, '');
// 表头
$fileStr .= "电话\t运营商\t订购开始日期\t订购结束日期\t套餐\t天数\t价格".PHP_EOL;
$fopen = file_put_contents($putFileName, $fileStr, FILE_APPEND);

$tableName = "t_mouth_{$fileName}";
$searchName = "t_phone_detail_{$fileName}";
$y = substr($fileName, 0, 4);
$m = substr($fileName, 4, 2);
// 当月的数据实际为统计上个月
$y = date('Y', strtotime("{$y}-{$m}-01 00:00 -1 months"));
$m = date('m', strtotime("{$y}-{$m}-01 00:00 -1 months"));
$priceConfig = array(
    '1000260007' => 4,
    '1000260008' => 9,
    '1000260009' => 15,
    '1000260010' => 38,
    '1000260011' => 29,
    '1000260012' => 29,
);

$monthFir = $y.'-'.$m.'-01'.' 00:00:00';
$monthDays = date('t', mktime(0,0,0,$m,01,$y));
$monthLast = $y.'-'.$m.'-'.$monthDays.' 00:00:00';

$db = ConnectMysqli::getIntance($dbConfig);
$hasSql = "SHOW TABLES LIKE '{$tableName}'";
$hasRet = $db->getRow($hasSql);
// var_dump($hasSql);die;
if ($hasRet) {
    $sql = "TRUNCATE TABLE {$tableName}";
    $db->query($sql);
} else {
    $createSql = <<<SQL
        CREATE TABLE {$tableName} (
        `m_id` int(11) NOT NULL AUTO_INCREMENT,
        `m_phone` bigint(20) NOT NULL,
        `m_city` varchar(50) NOT NULL,
        `m_sdate` datetime NOT NULL,
        `m_edate` datetime NOT NULL,
        `m_name` int(11) NOT NULL,
        `m_days` int(11) NOT NULL,
        `m_money` decimal(10,2) NOT NULL,
        `m_sign` char(32) NOT NULL,
        PRIMARY KEY (`m_id`)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COMMENT='每月统计表'
SQL;
    $db->query($createSql);
}

$stime = microtime(true);
echo  '开始读取数据库并计算 =====<br />';
$condSql = "pd_sdate<='{$monthLast}' and pd_edate>='{$monthFir}'";
$insSql = "INSERT INTO {$tableName} VALUES ";
while ($beginTable <= $currTable) {
    $searchName = "t_phone_detail_{$beginTable}";
    $hasSql = "SHOW TABLES LIKE '{$searchName}'";
    $hasRet = $db->getRow($hasSql);
    if(!$hasRet){
        $beginTable = substr($beginTable, 0, 4).'-'.substr($beginTable, 4, 2);
        $beginTable = date('Ym', strtotime("$beginTable +1 month"));
        continue;
    }

    $per = 1000;
    $offset = 0;
    $sql = "SELECT COUNT(*) as S FROM {$searchName} WHERE {$condSql}";
    $countNum = $db->getRow($sql);
    $countNum = $countNum['S'];
    if($countNum)
        $fopen = file_put_contents($putFileName, "-----------------------{$beginTable}--------------------".PHP_EOL, FILE_APPEND);

    $totalNum += $countNum;
    do{
        $sql = "SELECT pd_phone,pd_city,pd_sdate,pd_edate,pd_name
                FROM {$searchName} 
                WHERE {$condSql}
                LIMIT {$offset}, {$per}";
        $retArr = $db->getAll($sql);
        $offset += $per;
        if (!$retArr) 
            continue;

        $insStr = '';
        $fileStr = "";
        foreach ($retArr as $key => $val) {
            $sign = md5($val['pd_phone'].$val['pd_sdate'].$val['pd_name']);
            // 验证是否重复
            $ssql = "select m_id from {$tableName} where  m_sign='{$sign}' LIMIT 1";
            if($db->getRow($ssql))
                continue;

            // 月费
            $monthPrice = $priceConfig[$val['pd_name']];
            // 天费
            $dayPrice = ceil(($monthPrice/$monthDays)*100)/100; // 小数点后两位进一取法
            // 整月
            if ($val['pd_sdate'] <= $monthFir && $val['pd_edate'] >= $monthLast) {
                $days = $monthDays;
                $needPrice = $monthPrice;
            }
            // 前月开通，当月结束
            if ($val['pd_sdate'] <= $monthFir && $val['pd_edate'] < $monthLast && $val['pd_edate'] > $monthFir) {
                $days = diff_days($monthFir, $val['pd_edate']);
                $needPrice = $dayPrice * $days;
            }
            // 当月开通，后月结束
            if ($val['pd_sdate'] > $monthFir && $val['pd_edate'] >= $monthLast) {
                $days = diff_days($val['pd_sdate'], $monthLast); 
                $needPrice = $dayPrice * $days;   
            }
            // 当月开通，当月结束    
            if ($val['pd_sdate'] > $monthFir && $val['pd_edate'] < $monthLast) {
                $days = diff_days($val['pd_sdate'], $val['pd_edate']);
                $needPrice = $dayPrice * $days;
            }

            $needPrice = $needPrice >= $monthPrice ? $monthPrice : $needPrice;
            $insStr .= "('',{$val['pd_phone']},'{$val['pd_city']}','{$val['pd_sdate']}',
                        '{$val['pd_edate']}',{$val['pd_name']},{$days},{$needPrice},'{$sign}'),";  
            $fileStr .= "{$val['pd_phone']}\t{$val['pd_city']}\t{$val['pd_sdate']}\t{$val['pd_edate']}\t{$val['pd_name']}\t{$days}\t{$needPrice}".PHP_EOL;
            
            $needTotal += $needPrice;
        }

        $sqlStr = substr($insSql . $insStr, 0, -1);
        $db->query($sqlStr);
        $fopen = file_put_contents($putFileName, $fileStr, FILE_APPEND);
    }while($offset < $countNum);

    $beginTable = substr($beginTable, 0, 4).'-'.substr($beginTable, 4, 2);
    $beginTable = date('Ym', strtotime("$beginTable +1 month"));
}

$needTotal =  round($needTotal, 2);
$fileStr .= "\t\t\t\t\t\t应收金额：{$needTotal}".PHP_EOL;
$fopen = file_put_contents($putFileName, $fileStr, FILE_APPEND);

$etime = microtime(true);
$utime = $etime - $stime;
echo  "写入成功,总条数：{$totalNum}, 耗时：{$utime}s =====<br />";
exit;

function diff_days($sdate, $edate) {
    $sTime = strtotime(substr($sdate,0,10));
    $eTime = strtotime(substr($edate,0,10));
    $days = 0;
    if($eTime >= $sTime) {
        $days = ($eTime - $sTime)/86400;
        $days += 1;
    }    

    return $days;
}