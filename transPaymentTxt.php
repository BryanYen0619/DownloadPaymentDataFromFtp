<!DOCTYPE html>
<html>
<body>

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
    /*
    1.fee_parsered_list下Select, 取得$parsered_files
    2.將比對到的unset
    for(int i=[$payment_files count]-1 ; i>=0 ; i--)
    {
        if(in_array($payment_files[i], $parsered_files))
        {
            unset($payment_files[i]);
        }
    }
    */

    $serverName = "localhost";
    $userName = "root";
    $password = "Abcd1234";
    $dbName = "tokyo_payment_test";

    // Create connection
    $mysqli = new mysqli($serverName, $userName, $password, $dbName);
    // Check connection
    if ($mysqli->connect_error) {
        die("Connection failed: " . $mysqli->connect_error);
    }

    // SQL 取出fee_tky_parsered所有資料
    $searchAllSqlCmd = "SELECT * FROM fee_tky_parsered";
    $searchAllFromSql = $mysqli->query($searchAllSqlCmd);
    if ($searchAllFromSql) {
        $row = $searchAllFromSql->fetch_array(MYSQLI_ASSOC);
        $parsered_files = $row["file_name"];
        echo "DB search file_name: ", $parsered_files, "</br>";
    } else {
        echo "Search not found From fee_tky_parsered.", "</br>";
    }

    // 將比對結果從payment_files移除
    for ($i = 0; $i < count($payment_files); $i++) {
        if ($payment_files[$i]== $parsered_files) {
            unset($payment_files[$i]);
        }
    }

    $searchAllFromSql ->free();
    // Close DB
    $mysqli->close();

    return $payment_files;
}

function parsePaymentItems($file_name)
{
    $insertCounts = 0;
    $array = file($file_name);
    foreach ($array as $line) {
        echo $line. "</br>";
        $content = parsePayments($line);

        //只是Print而已
        foreach ($content as $item) {
            var_dump($item);
            echo "</br>";
        }

        /*
        確認資料庫是否已有該"pay_group"，如沒有就做Insert(fee_period)

        將該Item做Insert(fee_order)
        //insertPaymentItem($content);
        $insertCounts++;
        */

        // 連結DB
        $serverName = "localhost";
        $userName = "root";
        $password = "Abcd1234";
        $dbName = "tokyo_payment_test";

        $mysqli = new mysqli($serverName, $userName, $password, $dbName);
        if ($mysqli->connect_error) {
            die("Connection failed: " . $mysqli->connect_error);
        }

        // 從array取出tky_code
        $tkyCode = array_values($content[0])[1];
        echo "tky_code : ",$tkyCode, "</br>";

        // 取得community id
        $searchTkyCodeSqlCmd = "SELECT community, tky_code FROM liveplates_id_community WHERE tky_code = '$tkyCode'";
        $searchTkyCodeFromSql = $mysqli->query($searchTkyCodeSqlCmd);
        if ($searchTkyCodeFromSql) {
            $row = $searchTkyCodeFromSql->fetch_array(MYSQLI_ASSOC);
            $communityId = $row["community"];
            echo "community id: ", $communityId, "</br>";
        } else {
            echo "Search not found From liveplates_id_community.", "</br>";
        }
        $searchTkyCodeFromSql->free();


        // 從array取出account_numbers
        $accountNumber = array_values($content[1])[1];
        echo "pay_group : ",$accountNumber, "</br>";

        // 比對fee_period是否有符合條件的資料
        $mappingIdAndNameSqlCmd = "SELECT community, name FROM fee_period WHERE community = '$communityId' AND name = '$accountNumber'";
        $mappingIdAndNameFromSql = $mysqli->query($mappingIdAndNameSqlCmd);
        if ($mappingIdAndNameFromSql->num_rows == 0) {
            // 取得今日日期
            $today = date("Y-m-d H:i:s");
            // 插入資料至fee_period
            $insertDataSqlCmd = "INSERT INTO fee_period (community, group_id, name, fee_footage, note, create_date)
                       VALUES ('$communityId', 0, '$accountNumber', 0, '', '$today')";
            $insertDataFromSql = $mysqli->query($insertDataSqlCmd);
            if ($insertDataFromSql) {
                echo "Insert fee_period Success.", "</br>";
            } else {
                echo "Insert fee_period Error, Message : ", $mysqli->error, "</br>";
            }
        } else {
            echo "Data is exists From fee_period.", "</br>";
        }
        $mappingIdAndNameFromSql->free();

        $mysqli->close();

        // 將該Item做Insert(fee_order)
        insertPaymentItem($content);
        $insertCounts++;
    }
    return $insertCounts;
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

function insertPaymentItem($item)
{
    //insert到fee_order

    $serverName = "localhost";
    $userName = "root";
    $password = "Abcd1234";
    $dbName = "tokyo_payment_test";

    $mysqli = new mysqli($serverName, $userName, $password, $dbName);
    if ($mysqli->connect_error) {
        die("Connection failed: " . $mysqli->connect_error);
    }

    // $livebricksId = array_values($item[0])[1];
    $accountNumbers = array_values($item[1])[1];
    // $name = array_values($item[2])[1];
    $payStart = array_values($item[3])[1];
    $payEnd = array_values($item[4])[1];
    $note = array_values($item[6])[1];

    $selectCommunityDataSqlCmd = "SELECT * FROM liveplates_id WHERE account = '$accountNumbers'";
    $selectCommunityDataFromSql = $mysqli->query($selectCommunityDataSqlCmd);
    if ($selectCommunityDataFromSql) {
        $row = $selectCommunityDataFromSql->fetch_array(MYSQLI_ASSOC);
        $livebricksId = $row["id"];
        $community = $row["community"];
        $householdNumber = $row["household_number"];
    } else {
        echo "Search not found From liveplates_id.", "</br>";
    }

    echo "livebricks id : ",$livebricksId, "</br>";
    echo "account Numbers : ",$accountNumbers, "</br>";
    echo "household Number : ",$householdNumber, "</br>";
    echo "pay Start : ",$payStart, "</br>";
    echo "pay End : ",$payEnd, "</br>";
    echo "note : ",$note, "</br>";

    // 預設日期
    $paytime = date('Y-m-d', strtotime($date));
    echo "paytime : ",$paytime, "</br>";

    // 插入資料至fee_order
    $insertDataSqlCmd = "INSERT INTO fee_order (community, account, liveplates_id, household_number, period, period_name, begin_time, end_time, pay_time, status_description, virtual_account_id, payment_way)
                    VALUES ('$community', '$accountNumbers', '$livebricksId', '$householdNumber', 0, ' ', '$payStart', '$payEnd', '$paytime', '$note', ' ', 0)";
    $insertDataFromSql = $mysqli->query($insertDataSqlCmd);
    if ($insertDataFromSql) {
        echo "Insert fee_order success.", "</br>";
    } else {
        echo "Insert fee_order error, Message : ", $mysqli->error, "</br>";
    }

    $selectCommunityDataFromSql ->free();
    $mysqli->close();
}

function saveParseredFileWithInsertCounts($file_name, $insertCounts)
{
    //將已parsered的資訊存到DB

    // 連結DB
    $serverName = "localhost";
    $userName = "root";
    $password = "Abcd1234";
    $dbName = "tokyo_payment_test";

    $mysqli = new mysqli($serverName, $userName, $password, $dbName);
    if ($mysqli->connect_error) {
        die("Connection failed: " . $mysqli->connect_error);
    }

    echo "file name : ",$file_name, "</br>";
    echo "insert counts : ", $insertCounts, "</br>";

    // 取得今日日期
    $currentDay = date("Y-m-d");

    // 插入資料至fee_tky_parsered
    $insertDataSqlCmd = "INSERT INTO fee_tky_parsered (file_name, item_count, create_date)
                         VALUES ('$file_name', '$insertCounts', '$currentDay')";
    $insertDataFromSql = $mysqli->query($insertDataSqlCmd);
    if ($insertDataFromSql) {
        echo "Insert fee_tky_parsered success.", "</br>";
    } else {
        echo "Insert fee_tky_parsered error, Message : ", $mysqli->error, "</br>";
    }

    $mysqli->close();
}

?>

</body>
</html>
