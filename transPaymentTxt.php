<?php require_once('Connections/link.php');?>
<?php
### 連接的 FTP 伺服器是 localhost
$conn_id = ftp_connect('ip');
if ($conn_id == false) {
    echo "Connect ftp error.","</br>";
}

### 登入 FTP, 帳號是 USERNAME, 密碼是 PASSWORD
$login_result = ftp_login($conn_id, 'user', 'pass');
if ($login_result == false) {
    echo "Ftp login error.","</br>";
}

//換目錄
if (ftp_chdir($conn_id, "ap-cvms")) {
    echo "Current directory is now: " . ftp_pwd($conn_id) . "</br>";
} else {
    echo "Couldn't change directory.</br>";
}

//取得ftp list
$all_txt_list = ftp_nlist($conn_id, ".");
if ($all_txt_list == false) {
    echo "Ftp list error.","</br>";
}

//取得fee_parsered_list
$unparsered_list = getUnParseredList($all_txt_list);
echo 'Get unparsered_list size: ', count($unparsered_list),'</br>';
//下載.TXT檔
$payment_files = downloadPaymentFiles($conn_id, $unparsered_list);
ftp_close($conn_id);
echo 'Search conn_id From FTP, Array Size: ',count($payment_files),'</br>';

//開始Insert所有未繳費Data
$insertCounts = 0;
foreach ($payment_files as $file_name) {
    $insertCounts = parsePaymentItems($file_name);
    saveParseredFileWithInsertCounts($file_name, $insertCounts);
}
echo 'END.</br>';

function downloadPaymentFiles($conn_id, $contents)
{
    $ary = array();
    foreach ($contents as $str) {
        $pos = strpos($str, 'ToAPP-');
        if ($pos !== false) {
            $handle = fopen($str, 'w');
            if ($handle != false) {
                if (ftp_fget($conn_id, $handle, $str, FTP_ASCII, 0)) {
                    array_push($ary, $str);
                    echo "Download Success-". $str. "</br>";
                    fclose($handle);
                } else {
                    echo "Download Error.","</br>";
                }
            } else {
                echo "Open Path Error.","</br>";
            }
        }
    }
    return $ary;
}

function getUnParseredList($payment_files)
{
    // SQL 取出fee_tky_parsered所有資料
    $searchAllSqlCmd = "SELECT * FROM fee_tky_parsered";
    //$searchAllFromSql = $mysqli->query($searchAllSqlCmd);
    $result = mysql_query($searchAllSqlCmd);
    while ($data = mysql_fetch_assoc($result))
    {
        for ($i = 0; $i < count($payment_files); $i++)
        {
            if ($payment_files[$i]== $data["file_name"])
            {
                unset($payment_files[$i]);
            }
        }
    }
    return $payment_files;
}

function parsePaymentItems($file_name)
{
    $insertCounts = 0;
    $array = file($file_name);
    $arrayCommunity = array();
    $arrayFeePeriod = array();
    foreach ($array as $line)
    {
        echo $line. "</br>";
        $content = parsePayments($line);

        foreach ($content as $item)
        {
            var_dump($item);
            echo "</br>";
        }

        //從取得communityId
        $tkyCode = array_values($content[0])[1];
        $communityId = getCommunityIdFromArrayByTkyCode($arrayCommunity, $tkyCode);
        if($communityId == "")
        {
            $communityId = getCommunityIdFromDBByTkyCode($tkyCode);
            if($communityId == "")
            {
                echo "Search not found From liveplates_id_community.", "</br>";
                continue;
            }
            else
            {
                echo "tky_code : ",$tkyCode, "</br>";
                echo "community id: ", $communityId, "</br>";
                array_push($arrayCommunity, array($tkyCode, $communityId));
            }
        }

        //確認fee_period
        $payGroup = array_values($content[2])[1];
        if(!getFeePeriodFromArray($arrayFeePeriod, $communityId, $payGroup))
        {
            if(!insertPayGroupToFeePeriod($communityId, $payGroup))
            {
                continue;
            }
        }
        echo "pay_group : ",$payGroup, "</br>";
        array_push($arrayFeePeriod, array($communityId, $payGroup));

        insertPaymentItem($content, $communityId);
        $insertCounts++;
    }
    return $insertCounts;
}

function getCommunityIdFromArrayByTkyCode($arrayCommunity, $tkyCode)
{
    $CommunityId = "";
    for($i=0 ; $i<count($arrayCommunity) ; $i++)
    {
        if($arrayCommunity[i][0] == $tkyCode)
        {
            $CommunityId = $arrayCommunity[i][1];
            break;
        }
    }
    return $CommunityId;
}

function getCommunityIdFromDBByTkyCode($tkyCode)
{
    // 取得community id
    $communityId = "";
    $searchTkyCodeSqlCmd = "SELECT community, tky_code FROM liveplates_id_community WHERE tky_code = '$tkyCode'";
    $result = mysql_query($searchTkyCodeSqlCmd);
    if ($result)
    {
        $row = mysql_fetch_array($result);
        $communityId = $row["community"];
    }
    return $communityId;
}

function getFeePeriodFromArray($arrayFeePeriod, $communityId, $payGroup)
{
    $bFound = false;
    for($i=0 ; $i<count($arrayFeePeriod) ; $i++)
    {
        if($arrayFeePeriod[i][0] == $communityId &&
           $arrayFeePeriod[i][1] == $payGroup)
        {
            $bFound = true;
            break;
        }
    }
    return $bFound;
}

function insertPayGroupToFeePeriod($communityId, $payGroup)
{
    $mappingIdAndNameSqlCmd = "SELECT community, name FROM fee_period WHERE community = '$communityId' AND name = '$payGroup'";
    $mappingIdAndNameFromSql = mysql_query($mappingIdAndNameSqlCmd);
    if (mysql_num_rows($mappingIdAndNameFromSql) == 0)
    {
        // 取得今日日期
        $today = date("Y-m-d H:i:s");
        // 插入資料至fee_period
        $insertDataSqlCmd = "INSERT INTO fee_period (community, group_id, name, fee_footage, note, create_date) VALUES ('$communityId', 0, '$payGroup', 0, '', '$today')";
        $insertDataFromSql = mysql_query($insertDataSqlCmd);
        if ($insertDataFromSql)
        {
            echo "Insert fee_period Success.", "</br>";
            return true;
        }
        else
        {
            echo "Insert fee_period Error, Message : ", mysql_error(), "</br>";
            return false;
        }
    }
    else
    {
        echo "Data is exists From fee_period.", "</br>";
        return true;
    }
}

function parsePayments($line)
{
    $list = array(
        array("tky_code", 1, 5),            // 案場代碼 1-5
        array("account_numbers", 1, 15),    // 住戶編號 6-10
        array("pay_group", 21, 27),            // 歸屬年月 21-27
        array("pay_start", 21, 27),            // 歸屬年月 21-27
        array("pay_end", 21, 27),            // 歸屬年月 21-27
        array("fee", 28, 34),                // 應繳總金額 28-34
        array("note", 35, 234),            // 繳費明細 35-234

    );
    return paserStr($line, $list);
}

function paserStr($line, $list)
{
    $result = array();
    foreach ($list as $item) {
        $name = $item[0];
        $value = getStr($line, $item[1], $item[2]);
        // 日期轉為西元
        if (strcmp($name, "PayDate") == 0 ||    // 繳費日期
            strcmp($name, "DueDate") == 0 ||    // 預計入帳日期
            strcmp($name, "ColDate") == 0) {        // 通路代收日
            $yyyy = getStr($value, 1, 2);
            if ($yyyy < 90) {
                $yyyy += 100;
            }    // 民國90~99不變，民國100年後數字+100，;
            $yyyy += 1911;                    // 民國改西元
            $mm = getStr($value, 3, 4);
            $dd = getStr($value, 5, 6);
            $yyyymmdd = $yyyy."-".$mm."-".$dd;
            $value = $yyyymmdd;
        } elseif (strcmp($name, "pay_start") == 0) {
            // First day of the month.
            $yyyy = getStr($value, 1, 4);
            $mm = getStr($value, 6, 7);
            $yyyymmdd = $yyyy."-".$mm."-01";
            $value = date('Y-m-01', strtotime($yyyymmdd));
        } elseif (strcmp($name, "pay_end") == 0) {
            // Last day of the month.
            $yyyy = getStr($value, 1, 4);
            $mm = getStr($value, 6, 7);
            $yyyymmdd = $yyyy."-".$mm."-01";
            $value = date('Y-m-t', strtotime($yyyymmdd));
        }

        // 去除空白
        $value = str_replace(" ", "", $value);
        array_push($result, array_combine(array("name", "value"), array($name, $value)));
    }

    return $result;
}

function getStr($line, $begin, $end)
{
    $start = floor($begin) - 1;
    $length = floor($end) - floor($start);
    return substr($line, $start, $length);
}

function insertPaymentItem($item, $communityId)
{
    $accountNumbers = array_values($item[1])[1];
    $payStart = array_values($item[3])[1];
    $payEnd = array_values($item[4])[1];
    $payFee = array_values($item[5][1]);

    $selectCommunityDataSqlCmd = "SELECT * FROM liveplates_id WHERE account = '$accountNumbers' AND community = '$communityId'";
    $selectCommunityDataFromSql = mysql_query($selectCommunityDataSqlCmd);
    if ($selectCommunityDataFromSql)
    {
        $row = mysql_fetch_array($selectCommunityDataFromSql);
        $livebricksId = $row["id"];
        $householdNumber = $row["household_number"];
    }
    else
    {
        echo "Search not found From liveplates_id.", "</br>";
    }

    echo "livebricks id : ",$livebricksId, "</br>";
    echo "account Numbers : ",$accountNumbers, "</br>";
    echo "household Number : ",$householdNumber, "</br>";
    echo "pay Start : ",$payStart, "</br>";
    echo "pay End : ",$payEnd, "</br>";
    echo "pay Fee : ",$payFee, "</br>";

    // 預設日期
    $paytime = date('Y-m-d', strtotime($date));
    echo "paytime : ",$paytime, "</br>";

    // 插入資料至fee_order
    $insertDataSqlCmd = "INSERT INTO fee_order (community, account, liveplates_id, household_number, period, period_name, amount_payable, begin_time, end_time, pay_time, virtual_account_id, payment_way)
                    VALUES ('$community', '$accountNumbers', '$livebricksId', '$householdNumber', 0, ' ','$payFee', '$payStart', '$payEnd', '$paytime', ' ', 0)";
    $insertDataFromSql = mysql_query($insertDataSqlCmd);
    if ($insertDataFromSql) {
        echo "Insert fee_order success.", "</br>";
    } else {
        echo "Insert fee_order error, Message : ", mysql_error(), "</br>";
    }
}

function saveParseredFileWithInsertCounts($file_name, $insertCounts)
{
    //將已parsered的資訊存到DB
    echo "file name : ",$file_name, "</br>";
    echo "insert counts : ", $insertCounts, "</br>";

    // 取得今日日期
    $currentDay = date("Y-m-d");

    // 插入資料至fee_tky_parsered
    $insertDataSqlCmd = "INSERT INTO fee_tky_parsered (file_name, item_count, create_date)
                         VALUES ('$file_name', '$insertCounts', '$currentDay')";
    $insertDataFromSql = mysql_query($insertDataSqlCmd);
    if ($insertDataFromSql) {
        echo "Insert fee_tky_parsered success.", "</br>";
    } else {
        echo "Insert fee_tky_parsered error, Message : ", mysql_error(), "</br>";
    }
}

?>
